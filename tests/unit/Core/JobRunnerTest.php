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

		add_option( $lock, time(), '', false );
		$this->assertSame( 'running', $runner->run( $id ) );
		$this->assertSame( 0, $job->calls );

		update_option( $lock, time() - JobRunner::LOCK_TTL - 1 );
		$this->assertSame( 'queued', $runner->run( $id ) );
		$this->assertSame( 1, $job->calls );
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
}
