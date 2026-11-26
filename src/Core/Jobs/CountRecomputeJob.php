<?php
/**
 * Background job: recompute the declared filter combinations of one pivot set into the count caches.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Core\Jobs;

use NF\Backend\BackendInterface;
use NF\Query\Builder;
use NF\Query\QueryContext;
use NF\Storage\ObjectType;

defined( 'ABSPATH' ) || exit;

/**
 * Runs `count()` for each combination returned by the `nf/count/combinations` filter, so the object cache and the
 * `nf_count_cache` table hold the totals at the set's current write_version. Argument: `pivot_id`. A few combinations
 * per step; the count itself is the normal count path, so a combination that fails is skipped.
 */
final class CountRecomputeJob implements JobInterface {

	/**
	 * Combinations counted per step.
	 */
	private const PER_STEP = 5;

	/**
	 * Constructor.
	 *
	 * @param BackendInterface $db      Backend.
	 * @param \Closure         $context Returns the QueryContext (resolved late).
	 */
	public function __construct( private BackendInterface $db, private \Closure $context ) {}

	/**
	 * Job type.
	 *
	 * @return string
	 */
	public function key(): string {
		return 'count_recompute';
	}

	/**
	 * Query target of a pivot set (`event`, `term:genre`, `user`), or null for a type with no query builder.
	 *
	 * @param int $pivotId Pivot set id.
	 * @return string|null
	 */
	public function target( int $pivotId ): ?string {
		$rows = $this->db->select( 'SELECT object_type, subtype FROM ' . $this->db->table( 'nf_pivot_sets' ) . ' WHERE pivot_id = ?', array( $pivotId ) );
		if ( array() === $rows ) {
			return null;
		}
		$sub = (string) $rows[0]['subtype'];
		return match ( ObjectType::tryFrom( (int) $rows[0]['object_type'] ) ) {
			ObjectType::Post => '' === $sub ? null : $sub,
			ObjectType::Term => '' === $sub ? null : 'term:' . $sub,
			ObjectType::User => 'user',
			default          => null,
		};
	}

	/**
	 * Declared combinations for a target. Each is `array( 'where' => array( array( field, op, value ), ... ) )` with an
	 * optional `'status'`. An empty `where` is the unfiltered total, which the rollup already serves.
	 *
	 * @param string $target Query target.
	 * @return array<int,array<string,mixed>>
	 */
	public function combinations( string $target ): array {
		/**
		 * Filter combinations to keep warm in the count caches after saves.
		 *
		 * @param array<int,array<string,mixed>> $combinations Declared combinations.
		 * @param string                         $target       Query target.
		 */
		$declared = apply_filters( 'nf/count/combinations', array(), $target ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		return is_array( $declared ) ? array_values( array_filter( $declared, 'is_array' ) ) : array();
	}

	/**
	 * Initial state.
	 *
	 * @param array<string,mixed> $args Arguments: pivot_id.
	 * @return array<string,mixed>
	 */
	public function init( array $args ): array {
		return array(
			'pivot_id' => absint( $args['pivot_id'] ?? 0 ),
			'offset'   => 0,
			'counted'  => 0,
			'done'     => false,
		);
	}

	/**
	 * Count the next few combinations.
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 */
	public function step( array $state ): array {
		$target = $this->target( absint( $state['pivot_id'] ) );
		$combos = null === $target ? array() : $this->combinations( $target );
		$offset = absint( $state['offset'] );
		$ctx = ( $this->context )();
		if ( ! $ctx instanceof QueryContext ) {
			return $state;
		}
		foreach ( array_slice( $combos, $offset, self::PER_STEP ) as $combo ) {
			try {
				$b = new Builder( (string) $target, $ctx );
				foreach ( (array) ( $combo['where'] ?? array() ) as $w ) {
					if ( is_array( $w ) && 3 === count( $w ) ) {
						$b->where( (string) $w[0], $w[1], $w[2] );
					}
				}
				if ( isset( $combo['status'] ) ) {
					$b->status( $combo['status'] );
				}
				$ctx->count( $b->toSpec(), true );
				$state['counted'] = absint( $state['counted'] ) + 1;
			} catch ( \Throwable $e ) {
				continue; // A bad declaration never stops the others.
			}
		}
		$state['offset'] = $offset + self::PER_STEP;
		$state['done']   = $state['offset'] >= count( $combos );
		return $state;
	}
}
