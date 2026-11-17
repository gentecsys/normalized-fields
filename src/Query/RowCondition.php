<?php
/**
 * Same-row condition on a Series of a query (WP 3.1c).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `whereRow`: an object matches when ONE row of the Series satisfies every condition (no self-joins).
 */
final class RowCondition {

	/**
	 * Constructor.
	 *
	 * @param string      $series     Series (repeater) field name.
	 * @param Condition[] $conditions Conditions on sub-fields, all on the same row.
	 * @throws \InvalidArgumentException When no condition is given.
	 */
	public function __construct( public readonly string $series, public readonly array $conditions ) {
		if ( array() === $conditions ) {
			throw new \InvalidArgumentException( 'A row condition needs at least one condition.' );
		}
	}
}
