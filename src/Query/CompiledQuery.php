<?php
/**
 * Output of the compiler (WP 3.1a).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Query;

defined( 'ABSPATH' ) || exit;

/**
 * SQL with bound parameters, a count statement, and the planner's choice for explain().
 */
final class CompiledQuery {

	/**
	 * Constructor.
	 *
	 * @param string                           $sql         Statement returning object_id in order.
	 * @param array<int,scalar>                $params      Bound parameters.
	 * @param string                           $countSql    COUNT(*) AS c over the same filter.
	 * @param array<int,scalar>                $countParams Parameters of the count.
	 * @param array<string,mixed>              $plan        Mode, driver and notes.
	 */
	public function __construct(
		public readonly string $sql,
		public readonly array $params,
		public readonly string $countSql,
		public readonly array $countParams,
		public readonly array $plan
	) {}

	/**
	 * SQL with parameters inlined, for toSql() and debugging only (never executed).
	 *
	 * @return string
	 */
	public function toSql(): string {
		$params = $this->params;
		return (string) preg_replace_callback(
			'/\?/',
			static function () use ( &$params ): string {
				$v = array_shift( $params );
				return is_int( $v ) || is_float( $v ) ? (string) $v : "'" . str_replace( "'", "''", (string) $v ) . "'";
			},
			$this->sql
		);
	}
}
