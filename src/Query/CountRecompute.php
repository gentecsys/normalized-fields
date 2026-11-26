<?php
/**
 * Debounced trigger for the count recompute job.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Query;

use NF\Core\Jobs\CountRecomputeJob;
use NF\Core\Jobs\JobRunner;
use NF\Install\Upgrader;

defined( 'ABSPATH' ) || exit;

/**
 * After a pivot write, queues one recompute job per pivot set when the set has declared combinations. While a job for
 * the set is queued or running, further saves queue nothing: the job reads the current write_version when it runs.
 * Saves are writes by logged-in users, WP-CLI or jobs, so an anonymous GET never queues it.
 */
final class CountRecompute {

	/**
	 * Constructor.
	 *
	 * @param JobRunner         $jobs Job runner.
	 * @param CountRecomputeJob $job  The recompute job.
	 */
	public function __construct( private JobRunner $jobs, private CountRecomputeJob $job ) {}

	/**
	 * Hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'nf/pivot/changed', array( $this, 'onChanged' ) );
	}

	/**
	 * Queue the job for a set that declares combinations.
	 *
	 * @param int $pivotId Pivot set id.
	 * @return void
	 */
	public function onChanged( int $pivotId ): void {
		if ( ! Upgrader::isCurrent() ) {
			return;
		}
		$target = $this->job->target( $pivotId );
		if ( null === $target || array() === $this->job->combinations( $target ) ) {
			return;
		}
		$args   = array( 'pivot_id' => $pivotId );
		$record = $this->jobs->record( JobRunner::idFor( $this->job->key(), $args ) );
		if ( null !== $record && in_array( $record['status'], array( 'queued', 'running' ), true ) ) {
			return;
		}
		$this->jobs->start( $this->job->key(), $args );
	}
}
