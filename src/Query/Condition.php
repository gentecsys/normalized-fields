<?php
/**
 * One filter condition of a query (WP 3.1a).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable `field op value` triple. Operators: = != < <= > >= between in not_in null not_null.
 */
final class Condition {

	public const OPERATORS = array( '=', '!=', '<', '<=', '>', '>=', 'between', 'in', 'not_in', 'null', 'not_null' );

	/**
	 * Constructor.
	 *
	 * @param string $field Field name, or a core column (`date`, `modified`, `author`, `parent`, `menu_order`, `ID`).
	 * @param string $op    Operator.
	 * @param mixed  $value Scalar, or an array for between / in / not_in; ignored for null / not_null.
	 */
	public function __construct( public readonly string $field, public readonly string $op, public readonly mixed $value = null ) {
		if ( ! in_array( $op, self::OPERATORS, true ) ) {
			throw new \InvalidArgumentException( 'Unknown operator.' );
		}
	}
}
