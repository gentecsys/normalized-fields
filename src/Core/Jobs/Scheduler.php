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
 * Uses Action Scheduler when it is loaded (the bundled copy or another plugin's), otherwise WP-Cron.
 * Only callers that may write (admin, WP-CLI, a running job) schedule; an anonymous request never does.
 */
final class Scheduler {

	/**
	 * Hook that runs a job. Receives the job id.
	 */
	public const HOOK = 'nf_run_job';

	/**
	 * Action Scheduler group.
	 */
	public const GROUP = 'nf';

	/**
	 * Queue one run of a job, unless one is already queued.
	 *
	 * @param string $id    Job id.
	 * @param int    $delay Seconds from now.
	 * @return void
	 */
	public function schedule( string $id, int $delay = 0 ): void {
		$delay = max( 0, $delay );
		if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK, array( $id ), self::GROUP ) ) {
				as_schedule_single_action( time() + $delay, self::HOOK, array( $id ), self::GROUP );
			}
			return;
		}
		if ( false === wp_next_scheduled( self::HOOK, array( $id ) ) ) {
			wp_schedule_single_event( time() + $delay, self::HOOK, array( $id ) );
		}
	}

	/**
	 * Drop queued runs of a job.
	 *
	 * @param string $id Job id.
	 * @return void
	 */
	public function unschedule( string $id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array( $id ), self::GROUP );
		}
		wp_clear_scheduled_hook( self::HOOK, array( $id ) );
	}
}
