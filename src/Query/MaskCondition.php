<?php
/**
 * Multi-valued membership condition of a query (WP 3.1c).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Query;

defined( 'ABSPATH' ) || exit;

/**
 * `whereAny` / `whereAll` / `whereNone` on a multi-valued field: any of, all of or none of the values.
 */
final class MaskCondition {

	public const MODES = array( 'any', 'all', 'none' );

	/**
	 * Constructor.
	 *
	 * @param string   $field  Multi-valued string field name (bitmask slot, or typed-table probe).
	 * @param string   $mode   any|all|none.
	 * @param string[] $values Values.
	 * @throws \InvalidArgumentException When the mode is unknown.
	 */
	public function __construct( public readonly string $field, public readonly string $mode, public readonly array $values ) {
		if ( ! in_array( $mode, self::MODES, true ) ) {
			throw new \InvalidArgumentException( 'Unknown mask mode.' );
		}
	}
}
