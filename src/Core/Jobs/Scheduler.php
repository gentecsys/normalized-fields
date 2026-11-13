<?php
/**
 * Schedules job runs.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Core\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * Queues job runs on WP-Cron (`nf_run_job`). NF does not use Action Scheduler, bundled or from another plugin
 * (owner decision 2026-11-13). Only callers that may write (admin, WP-CLI, a running job) schedule; an anonymous
 * request never does. Sites with DISABLE_WP_CRON need a system cron that requests wp-cron.php.
 */
final class Scheduler {

	/**
	 * Hook that runs a job. Receives the job id.
	 */
	public const HOOK = 'nf_run_job';

	/**
	 * Queue one run of a job. The args are (id), so a run already queued is kept rather than duplicated.
	 *
	 * @param string $id    Job id.
	 * @param int    $delay Seconds from now.
	 * @return array<int,string> Args to pass to unschedule().
	 */
	public function schedule( string $id, int $delay = 0 ): array {
		$args = array( $id );
		if ( false === wp_next_scheduled( self::HOOK, $args ) ) {
			wp_schedule_single_event( time() + max( 0, $delay ), self::HOOK, $args );
		}
		return $args;
	}

	/**
	 * Drop the queued runs of a job, by the args schedule() returned.
	 *
	 * @param array<int,string> $args Args.
	 * @return void
	 */
	public function unschedule( array $args ): void {
		wp_clear_scheduled_hook( self::HOOK, $args );
	}
}
