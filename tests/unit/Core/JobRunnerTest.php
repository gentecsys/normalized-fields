<?php
/**
 * Background job runner tests.
 *
 * @package NF
 */

use NF\Core\Jobs\JobInterface;
use NF\Core\Jobs\JobRunner;
use NF\Core\Jobs\Scheduler;
use NF\Install\Upgrader;

require_once __DIR__ . '/JobRunnerTestJob.php';
require_once __DIR__ . '/JobRunnerTestFailingJob.php';

/**
 * Jobs resume from stored state, never overlap, and never run while the schema is behind.
 */
class JobRunnerTest extends WP_UnitTestCase {

	/**
	 * Make sure the schema counts as current.
	 */
	public function set_up() {
		parent::set_up();
		update_option( Upgrader::OPTION, Upgrader::SCHEMA_VERSION );
	}

	/**
	 * Runner with a zero budget: one step per run.
	 *
	 * @param JobRunnerTestJob $job Job.
	 * @return JobRunner
	 */
	private function runner( JobRunnerTestJob $job ): JobRunner {
		return new JobRunner( array( $job ), new Scheduler(), 0 );
	}

	/**
	 * Each run does one step, saves state, and queues the next run.
	 */
	public function test_resumes_from_stored_state_until_done(): void {
		$job    = new JobRunnerTestJob();
		$runner = $this->runner( $job );
		$id     = $runner->start( 'counting', array( 'target' => 3 ) );
		$this->assertNotSame( '', $id );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK, array( $id ) ) );

		$this->assertSame( 'queued', $runner->run( $id ) );
		$this->assertSame( 1, $runner->record( $id )['state']['n'] );
		// A new runner (a new request) carries on from the stored state.
		$runner = $this->runner( $job );
		$this->assertSame( 'queued', $runner->run( $id ) );
		$this->assertSame( 'done', $runner->run( $id ) );
		$this->assertSame( 3, $job->calls );
		$this->assertSame( 'done', $runner->run( $id ) );
		$this->assertSame( 3, $job->calls, 'A finished job does no more work.' );
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK, array( $id ) ) );
	}

	/**
	 * A watchdog is queued at about LOCK_TTL + 5 s before the first step.
	 */
	public function test_watchdog_is_queued_ahead(): void {
		$runner = new JobRunner( array( new JobRunnerTestJob() ), new Scheduler(), 0 );
		$id     = $runner->start( 'counting', array( 'target' => 5 ) );
		wp_clear_scheduled_hook( Scheduler::HOOK, array( $id ) );
		$runner->run( $id );
		$due = wp_next_scheduled( Scheduler::HOOK, array( $id ) );
		$this->assertNotFalse( $due, 'A run is queued after a partial run.' );
		$this->assertLessThanOrEqual( time() + 2, $due, 'The continuation replaces the watchdog and is due now.' );
	}

	/**
	 * A run that finds the job done after taking the lock does no work.
	 */
	public function test_done_job_is_not_stepped_again(): void {
		$job    = new JobRunnerTestJob();
		$runner = new JobRunner( array( $job ), new Scheduler(), 30 );
		$id     = $runner->start( 'counting', array( 'target' => 2 ) );
		$runner->run( $id );
		$other = new JobRunner( array( $job ), new Scheduler(), 30 );
		$this->assertSame( 'done', $other->run( $id ) );
		$this->assertSame( 2, $job->calls );
	}

	/**
	 * A generous budget finishes a short job in one run.
	 */
	public function test_budget_runs_several_steps(): void {
		$job    = new JobRunnerTestJob();
		$runner = new JobRunner( array( $job ), new Scheduler(), 30 );
		$id     = $runner->start( 'counting', array( 'target' => 5 ) );
		$this->assertSame( 'done', $runner->run( $id ) );
		$this->assertSame( 5, $job->calls );
	}

	/**
	 * The same type and arguments give the same job.
	 */
	public function test_same_arguments_same_id(): void {
		$this->assertSame(
			JobRunner::idFor(
				'counting',
				array(
					'a' => 1,
					'b' => 2,
				)
			),
			JobRunner::idFor(
				'counting',
				array(
					'b' => 2,
					'a' => 1,
				)
			)
		);
	}

	/**
	 * A live lock stops a second run; a stale lock left by a killed request is taken over.
	 */
	public function test_lock_blocks_overlap_and_expires(): void {
		$job    = new JobRunnerTestJob();
		$runner = $this->runner( $job );
		$id     = $runner->start( 'counting' );
		$lock   = JobRunner::OPTION_PREFIX . $id . '_lock';

		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->options,
			array(
				'option_name'  => $lock,
				'option_value' => (string) time(),
				'autoload'     => 'no',
			)
		);
		$this->assertSame( 'running', $runner->run( $id ) );
		$this->assertSame( 0, $job->calls );

		$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() - JobRunner::LOCK_TTL - 1 ) ), array( 'option_name' => $lock ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $lock, 'options' );
		$this->assertSame( 'queued', $runner->run( $id ) );
		$this->assertSame( 1, $job->calls );
		wp_cache_delete( $lock, 'options' );
		$this->assertFalse( get_option( $lock ), 'The lock is released after a run.' );
	}

	/**
	 * While the schema is behind nothing runs or is queued, and the state is kept.
	 */
	public function test_blocked_while_schema_is_behind(): void {
		$job    = new JobRunnerTestJob();
		$runner = $this->runner( $job );
		$id     = $runner->start( 'counting' );
		$runner->run( $id );

		update_option( Upgrader::OPTION, Upgrader::SCHEMA_VERSION - 1 );
		$seen = 0;
		add_action(
			'nf/jobs/blocked',
			static function () use ( &$seen ): void {
				++$seen;
			}
		);
		$this->assertSame( 'blocked', $runner->run( $id ) );
		$this->assertSame( 1, $seen );
		$this->assertNotFalse( wp_next_scheduled( Scheduler::HOOK, array( $id ) ), 'A retry is queued so the job resumes after the upgrade.' );
		$this->assertSame( 1, $job->calls );
		$this->assertSame( '', $runner->start( 'counting', array( 'target' => 9 ) ), 'No new job is created either.' );

		update_option( Upgrader::OPTION, Upgrader::SCHEMA_VERSION );
		$this->assertSame( 'queued', $runner->run( $id ) );
		$this->assertSame( 2, $runner->record( $id )['state']['n'] );
	}

	/**
	 * Unknown ids and types are refused without side effects.
	 */
	public function test_unknown_job(): void {
		$runner = $this->runner( new JobRunnerTestJob() );
		$this->assertSame( 'missing', $runner->run( 'nope-123' ) );
		$this->assertSame( '', $runner->start( 'nope' ) );
	}

	/**
	 * Three throwing steps in a row fail the job: no more runs, watchdog cleared, action fired, error kept.
	 */
	public function test_failed_after_three_attempts(): void {
		$job    = new JobRunnerTestFailingJob();
		$runner = $this->runner_for( $job );
		$fired  = array();
		add_action(
			'nf/jobs/failed',
			function ( $id, $error ) use ( &$fired ) {
				$fired[] = array( $id, $error );
			},
			10,
			2
		);
		$id = $runner->start( 'failing' );
		$this->assertSame( 'queued', $runner->run( $id ) );
		$this->assertSame( 'queued', $runner->run( $id ) );
		$this->assertSame( 'failed', $runner->run( $id ) );
		$record = $runner->record( $id );
		$this->assertSame( 'failed', $record['status'] );
		$this->assertSame( 3, $record['attempts'] );
		$this->assertSame( 'boom 3', $record['error'] );
		$this->assertSame( array( array( $id, 'boom 3' ) ), $fired );
		$this->assertFalse( wp_next_scheduled( Scheduler::HOOK, array( $id ) ), 'No watchdog or continuation is left.' );
		$this->assertSame( 'failed', $runner->run( $id ) );
		$this->assertSame( 3, $job->calls, 'A failed job does no more work.' );
	}

	/**
	 * A success resets the attempt count; restarting a failed job starts over.
	 */
	public function test_success_resets_attempts_and_restart_starts_over(): void {
		$job             = new JobRunnerTestFailingJob();
		$job->fail_times = 2;
		$runner          = $this->runner_for( $job );
		$id              = $runner->start( 'failing' );
		$runner->run( $id );
		$runner->run( $id );
		$this->assertSame( 'done', $runner->run( $id ), 'Two failures then a success finish the job.' );

		$job             = new JobRunnerTestFailingJob();
		$runner          = $this->runner_for( $job );
		$id              = $runner->start( 'failing', array( 'x' => 1 ) );
		$runner->run( $id );
		$runner->run( $id );
		$runner->run( $id );
		$this->assertSame( 'failed', $runner->record( $id )['status'] );
		$job->fail_times = 0;
		$this->assertSame( $id, $runner->start( 'failing', array( 'x' => 1 ) ) );
		$this->assertSame( 'queued', $runner->record( $id )['status'] );
		$this->assertArrayNotHasKey( 'error', $runner->record( $id ) );
		$this->assertSame( 'done', $runner->run( $id ) );
	}

	/**
	 * Runner with a zero budget for the failing job.
	 *
	 * @param JobRunnerTestFailingJob $job Job.
	 * @return JobRunner
	 */
	private function runner_for( JobRunnerTestFailingJob $job ): JobRunner {
		return new JobRunner( array( $job ), new Scheduler(), 0 );
	}
}
