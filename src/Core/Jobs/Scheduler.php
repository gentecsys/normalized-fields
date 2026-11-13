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
	 * Queue one run of a job. Under Action Scheduler each call gets its own args (id, due time), because the
	 * action that is running counts as scheduled and would otherwise suppress a watchdog queued from inside it.
	 * Under WP-Cron the args are (id) and a run already queued is kept.
	 *
	 * @param string $id    Job id.
	 * @param int    $delay Seconds from now.
	 * @return array<int,string|int> Args to pass to unschedule().
	 */
	public function schedule( string $id, int $delay = 0 ): array {
		$at = time() + max( 0, $delay );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$args = array( $id, $at );
			as_schedule_single_action( $at, self::HOOK, $args, self::GROUP );
			return $args;
		}
		$args = array( $id );
		if ( false === wp_next_scheduled( self::HOOK, $args ) ) {
			wp_schedule_single_event( $at, self::HOOK, $args );
		}
		return $args;
	}

	/**
	 * Drop one queued run, by the args schedule() returned.
	 *
	 * @param array<int,string|int> $args Args.
	 * @return void
	 */
	public function unschedule( array $args ): void {
		if ( function_exists( 'as_unschedule_action' ) ) {
			as_unschedule_action( self::HOOK, $args, self::GROUP );
			return;
		}
		wp_clear_scheduled_hook( self::HOOK, $args );
	}
}
