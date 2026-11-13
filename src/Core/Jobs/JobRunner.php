<?php
/**
 * Runs resumable background jobs.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Core\Jobs;

use NF\Install\Upgrader;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a job in steps within a time budget, saving its state after every step in the option `nf_job_<id>`.
 *
 * - Survives killed requests: the next run is queued before the first step, and a lock left by a dead request
 *   expires after LOCK_TTL.
 * - Survives PHP timeouts: the budget (default 15 s, filter `nf/jobs/budget`) ends well before max_execution_time.
 * - Never runs while the schema is behind (CLAUDE.md): the state is kept, nothing is written or queued, and
 *   the action `nf/jobs/blocked` fires. A run after the upgrade carries on from the saved state.
 */
final class JobRunner {

	public const OPTION_PREFIX = 'nf_job_';

	/**
	 * Seconds after which a lock left by a killed request is ignored.
	 */
	public const LOCK_TTL = 120;

	/**
	 * Default seconds of work per run.
	 */
	public const BUDGET = 15;

	/**
	 * Seconds before a run that was blocked by a schema that is behind tries again.
	 */
	public const BLOCKED_RETRY = 300;

	/**
	 * Job types by key.
	 *
	 * @var array<string,JobInterface>
	 */
	private array $jobs = array();

	/**
	 * Constructor.
	 *
	 * @param JobInterface[] $jobs      Known job types.
	 * @param Scheduler|null $scheduler Scheduler; null for the default.
	 * @param int|null       $budget    Seconds of work per run; null for the default and filter.
	 */
	public function __construct( array $jobs, private ?Scheduler $scheduler = null, private ?int $budget = null ) {
		foreach ( $jobs as $job ) {
			$this->jobs[ $job->key() ] = $job;
		}
		$this->scheduler ??= new Scheduler();
	}

	/**
	 * Hook the scheduled run and the WP-CLI commands.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( Scheduler::HOOK, array( $this, 'run' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::add_command( 'nf job', JobCommand::class );
		}
	}

	/**
	 * Job id for a type and arguments: the same arguments give the same id, so a job is never queued twice.
	 *
	 * @param string              $key  Job type.
	 * @param array<string,mixed> $args Arguments.
	 * @return string
	 */
	public static function idFor( string $key, array $args ): string {
		ksort( $args );
		return sanitize_key( $key ) . '-' . substr( md5( (string) wp_json_encode( $args ) ), 0, 12 );
	}

	/**
	 * Create a job (or restart a finished one) and queue its first run. Callers are admin, WP-CLI or another job.
	 *
	 * @param string              $key  Job type.
	 * @param array<string,mixed> $args Arguments.
	 * @return string Job id, or '' when the type is unknown or the schema is behind.
	 */
	public function start( string $key, array $args = array() ): string {
		if ( ! isset( $this->jobs[ $key ] ) || ! Upgrader::isCurrent() ) {
			return '';
		}
		$id     = self::idFor( $key, $args );
		$record = $this->record( $id );
		if ( null === $record || 'done' === $record['status'] ) {
			$this->save(
				$id,
				array(
					'key'     => $key,
					'args'    => $args,
					'state'   => $this->jobs[ $key ]->init( $args ),
					'status'  => 'queued',
					'steps'   => 0,
					'started' => time(),
					'updated' => time(),
				)
			);
		}
		$this->scheduler->schedule( $id );
		return $id;
	}

	/**
	 * The stored record of a job, or null.
	 *
	 * @param string $id Job id.
	 * @return array{key:string,args:array<string,mixed>,state:array<string,mixed>,status:string,steps:int,started:int,updated:int}|null
	 */
	public function record( string $id ): ?array {
		$r = get_option( self::OPTION_PREFIX . sanitize_key( $id ) );
		return is_array( $r ) ? $r : null; // @phpstan-ignore-line Shape is written only by save().
	}

	/**
	 * Run steps of a job until it is done or the budget is spent. Safe to call twice at once: the second call returns.
	 *
	 * @param string $id Job id.
	 * @return string Status after the run: done, queued, running (another run holds the lock), blocked or missing.
	 */
	public function run( string $id ): string {
		$record = $this->record( $id );
		if ( null === $record || ! isset( $this->jobs[ $record['key'] ] ) ) {
			return 'missing';
		}
		if ( 'done' === $record['status'] ) {
			return 'done';
		}
		if ( ! Upgrader::isCurrent() ) {
			/**
			 * Fires when a job run is skipped because the NF schema is behind. Nothing was written or queued.
			 *
			 * @param string $id Job id.
			 */
			do_action( 'nf/jobs/blocked', $id ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			$this->scheduler->schedule( $id, self::BLOCKED_RETRY ); // A scheduler write, not an NF table write: the job resumes after the upgrade.
			return 'blocked';
		}
		if ( ! $this->lock( $id ) ) {
			return 'running';
		}
		// The record is read again under the lock: another run may have finished since the first read.
		$record = $this->record( $id );
		if ( null === $record || 'done' === $record['status'] ) {
			$this->unlock( $id );
			return null === $record ? 'missing' : 'done';
		}
		$job      = $this->jobs[ $record['key'] ];
		$watchdog = array();
		try {
			// Queue a watchdog first: if this request is killed, the job still resumes.
			$watchdog = $this->scheduler->schedule( $id, self::LOCK_TTL + 5 );
			$budget   = $this->budget ?? (int) apply_filters( 'nf/jobs/budget', self::BUDGET ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			$until    = microtime( true ) + max( 0, $budget );
			do {
				$record['state']   = $job->step( $record['state'] );
				$record['steps']  += 1;
				$record['updated'] = time();
				$record['status']  = empty( $record['state']['done'] ) ? 'queued' : 'done';
				$this->save( $id, $record );
				$this->touchLock( $id );
			} while ( 'queued' === $record['status'] && microtime( true ) < $until );
		} finally {
			$this->unlock( $id );
		}
		$this->scheduler->unschedule( $watchdog );
		if ( 'done' === $record['status'] ) {
			/**
			 * Fires when a job has finished.
			 *
			 * @param string $id Job id.
			 */
			do_action( 'nf/jobs/done', $id ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		} else {
			$this->scheduler->schedule( $id );
		}
		return $record['status'];
	}

	/**
	 * Option name of a job's lock.
	 *
	 * @param string $id Job id.
	 * @return string
	 */
	private function lockName( string $id ): string {
		return self::OPTION_PREFIX . sanitize_key( $id ) . '_lock';
	}

	/**
	 * Take the job lock with a single INSERT IGNORE on the options table (a core table), which succeeds for
	 * exactly one caller. A lock older than LOCK_TTL is taken over with a compare-and-swap UPDATE.
	 *
	 * @param string $id Job id.
	 * @return bool
	 */
	private function lock( string $id ): bool {
		global $wpdb;
		$name = $this->lockName( $id );
		$now  = (string) time();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $now ) );
		if ( 1 === (int) $inserted ) {
			wp_cache_delete( $name, 'options' );
			return true;
		}
		$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		if ( '' === $held || time() - (int) $held < self::LOCK_TTL ) {
			return false;
		}
		$swapped = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $now, $name, $held ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $name, 'options' );
		return 1 === (int) $swapped;
	}

	/**
	 * Refresh the lock time after a step, so a long job keeps its lock.
	 *
	 * @param string $id Job id.
	 * @return void
	 */
	private function touchLock( string $id ): void {
		global $wpdb;
		$name = $this->lockName( $id );
		$wpdb->update( $wpdb->options, array( 'option_value' => (string) time() ), array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $name, 'options' );
	}

	/**
	 * Release the job lock.
	 *
	 * @param string $id Job id.
	 * @return void
	 */
	private function unlock( string $id ): void {
		global $wpdb;
		$name = $this->lockName( $id );
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $name, 'options' );
	}

	/**
	 * Store a record.
	 *
	 * @param string              $id     Job id.
	 * @param array<string,mixed> $record Record.
	 * @return void
	 */
	private function save( string $id, array $record ): void {
		update_option( self::OPTION_PREFIX . sanitize_key( $id ), $record, false );
	}
}
