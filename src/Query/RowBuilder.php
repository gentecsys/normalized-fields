<?php
/**
 * Condition builder for one Series row (WP 3.2a).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Passed to the `whereRow()` callback: every condition applies to the SAME row of the Series.
 */
final class RowBuilder {

	/**
	 * Collected conditions.
	 *
	 * @var Condition[]
	 */
	private array $conditions = array();

	/**
	 * Add a condition on a sub-field. Two arguments mean `=`; `null` as the value means IS NULL.
	 *
	 * @param string $field Sub-field name.
	 * @param mixed  $op    Operator, or the value when the third argument is omitted.
	 * @param mixed  $value Value.
	 * @return $this
	 * @throws \InvalidArgumentException On an invalid condition.
	 */
	public function where( string $field, mixed $op = null, mixed $value = null ): self {
		$this->conditions[] = 2 === func_num_args() ? Builder::condition( $field, null === $op ? 'null' : '=', $op ) : Builder::condition( $field, (string) $op, $value );
		return $this;
	}

	/**
	 * Inclusive range, same as `where( $field, 'between', [ $min, $max ] )`.
	 *
	 * @param string  $field  Sub-field name.
	 * @param mixed[] $range  Exactly two values.
	 * @return $this
	 * @throws \InvalidArgumentException Unless exactly two values are given.
	 */
	public function whereBetween( string $field, array $range ): self {
		$this->conditions[] = Builder::condition( $field, 'between', $range );
		return $this;
	}

	/**
	 * Conditions collected so far.
	 *
	 * @return Condition[]
	 */
	public function conditions(): array {
		return $this->conditions;
	}
}
