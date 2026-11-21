<?php
/**
 * Background job: rebuild a pivot set.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Core\Jobs;

use NF\Pivot\Pivot;

defined( 'ABSPATH' ) || exit;

/**
 * Rebuilds one pivot set from core objects and the typed tables in chunks of object ids (Pivot::rebuildFromCoreChunk), then prunes the row pivot.
 * Arguments: pivot_id (int), chunk (int, optional).
 */
final class PivotRebuildJob implements JobInterface {

	/**
	 * Constructor.
	 *
	 * @param Pivot $pivot Pivot service.
	 */
	public function __construct( private Pivot $pivot ) {}

	/**
	 * Job type.
	 *
	 * @return string
	 */
	public function key(): string {
		return 'pivot_rebuild';
	}

	/**
	 * Initial state.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<string,mixed>
	 */
	public function init( array $args ): array {
		return array(
			'pivot_id' => absint( $args['pivot_id'] ?? 0 ),
			'chunk'    => max( 1, absint( $args['chunk'] ?? Pivot::REBUILD_CHUNK ) ),
			'after'    => 0,
			'total'    => 0,
			'done'     => false,
		);
	}

	/**
	 * Rebuild the next chunk.
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 */
	public function step( array $state ): array {
		$pivot_id = absint( $state['pivot_id'] );
		$chunk    = max( 1, absint( $state['chunk'] ) );
		if ( 0 === $pivot_id ) {
			$state['done'] = true;
			return $state;
		}
		$res            = $this->pivot->rebuildFromCoreChunk( $pivot_id, absint( $state['after'] ), $chunk );
		$state['after'] = $res['last'];
		$state['total'] = absint( $state['total'] ) + $res['count'];
		if ( $res['done'] ) {
			$this->pivot->pruneRowPivot( $pivot_id );
			$this->pivot->clearPending( $pivot_id );
			$state['done'] = true;
		}
		return $state;
	}
}
