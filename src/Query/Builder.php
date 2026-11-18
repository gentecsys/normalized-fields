<?php
/**
 * Fluent query builder: collects a QuerySpec (WP 3.2a).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Query;

use NF\Storage\ObjectType;
use NF\Storage\Status;

defined( 'ABSPATH' ) || exit;

/**
 * `nf_query( 'event' )->where( … )->orderBy( … )`. Pure: no database access; it only builds the spec the compiler takes.
 * Results, SQL and explain() arrive with the query context in WP 3.2b.
 */
final class Builder {

	public const COUNT_MODES = array( 'exact', 'capped', 'none' );

	private ObjectType $type;
	private string $subtype;

	/**
	 * Statuses.
	 *
	 * @var Status[]
	 */
	private array $statuses = array();

	/**
	 * Conditions.
	 *
	 * @var Condition[]
	 */
	private array $conditions = array();

	/**
	 * Mask conditions.
	 *
	 * @var MaskCondition[]
	 */
	private array $masks = array();

	/**
	 * Row conditions.
	 *
	 * @var RowCondition[]
	 */
	private array $rows = array();

	/**
	 * Orders.
	 *
	 * @var array<int,array{0:string,1:string}>
	 */
	private array $orders = array();

	private int $limit      = 0;
	private int $offset     = 0;
	private string $count   = 'exact';

	/**
	 * Constructor.
	 *
	 * @param string $target Post type (`event`), taxonomy (`term:genre`) or `user`.
	 * @throws \InvalidArgumentException When the target is empty.
	 */
	public function __construct( string $target ) {
		$target = trim( $target );
		if ( '' === $target || 'term:' === $target ) {
			throw new \InvalidArgumentException( 'A query needs a post type, `term:<taxonomy>` or `user`.' );
		}
		if ( 'user' === $target ) {
			$this->type    = ObjectType::User;
			$this->subtype = '';
		} elseif ( str_starts_with( $target, 'term:' ) ) {
			$this->type    = ObjectType::Term;
			$this->subtype = substr( $target, 5 );
		} else {
			$this->type    = ObjectType::Post;
			$this->subtype = $target;
		}
	}

	/**
	 * Validated condition, shared with RowBuilder.
	 *
	 * @param string $field Field.
	 * @param string $op    Operator.
	 * @param mixed  $value Value.
	 * @return Condition
	 * @throws \InvalidArgumentException On an invalid field, operator or value.
	 */
	public static function condition( string $field, string $op, mixed $value ): Condition {
		if ( '' === $field ) {
			throw new \InvalidArgumentException( 'A condition needs a field name.' );
		}
		if ( 'between' === $op && ( ! is_array( $value ) || 2 !== count( $value ) ) ) {
			throw new \InvalidArgumentException( 'between needs exactly two values.' );
		}
		if ( in_array( $op, array( 'in', 'not_in' ), true ) && ( ! is_array( $value ) || array() === $value ) ) {
			throw new \InvalidArgumentException( 'in and not_in need a non-empty array.' );
		}
		if ( ! in_array( $op, array( 'between', 'in', 'not_in', 'null', 'not_null' ), true ) && ! is_scalar( $value ) ) {
			throw new \InvalidArgumentException( 'This operator needs a scalar value.' );
		}
		return new Condition( $field, $op, is_array( $value ) ? array_values( $value ) : $value );
	}

	/**
	 * Limit to statuses (default: publish).
	 *
	 * @param string|Status|array<int,string|Status> $status One or more statuses.
	 * @return $this
	 * @throws \InvalidArgumentException On an unknown status.
	 */
	public function status( string|Status|array $status ): static {
		$this->statuses = array();
		foreach ( (array) $status as $s ) {
			$st = $s instanceof Status ? $s : Status::fromName( $s );
			if ( null === $st ) {
				throw new \InvalidArgumentException( 'Unknown status.' );
			}
			$this->statuses[] = $st;
		}
		return $this;
	}

	/**
	 * Add a condition. Two arguments mean `=`; `null` as the value means IS NULL.
	 *
	 * @param string $field Field or core column.
	 * @param mixed  $op    Operator, or the value when the third argument is omitted.
	 * @param mixed  $value Value.
	 * @return $this
	 * @throws \InvalidArgumentException On an invalid condition.
	 */
	public function where( string $field, mixed $op = null, mixed $value = null ): static {
		$this->conditions[] = 2 === func_num_args() ? self::condition( $field, null === $op ? 'null' : '=', $op ) : self::condition( $field, (string) $op, $value );
		return $this;
	}

	/**
	 * Inclusive range, same as `where( $field, 'between', [ $min, $max ] )`.
	 *
	 * @param string  $field Field.
	 * @param mixed[] $range Exactly two values.
	 * @return $this
	 * @throws \InvalidArgumentException Unless exactly two values are given.
	 */
	public function whereBetween( string $field, array $range ): static {
		$this->conditions[] = self::condition( $field, 'between', $range );
		return $this;
	}

	/**
	 * Any of the values.
	 *
	 * @param string   $field  Multi-valued field.
	 * @param string[] $values Values.
	 * @return $this
	 */
	public function whereAny( string $field, array $values ): static {
		return $this->mask( $field, 'any', $values );
	}

	/**
	 * All of the values.
	 *
	 * @param string   $field  Multi-valued field.
	 * @param string[] $values Values.
	 * @return $this
	 */
	public function whereAll( string $field, array $values ): static {
		return $this->mask( $field, 'all', $values );
	}

	/**
	 * None of the values.
	 *
	 * @param string   $field  Multi-valued field.
	 * @param string[] $values Values.
	 * @return $this
	 */
	public function whereNone( string $field, array $values ): static {
		return $this->mask( $field, 'none', $values );
	}

	/**
	 * Conditions that must hold on ONE row of a Series.
	 *
	 * @param string   $series   Series field name.
	 * @param callable $callback Receives a RowBuilder.
	 * @return $this
	 * @throws \InvalidArgumentException When the callback adds no condition.
	 */
	public function whereRow( string $series, callable $callback ): static {
		$row = new RowBuilder();
		$callback( $row );
		$this->rows[] = new RowCondition( $series, $row->conditions() );
		return $this;
	}

	/**
	 * Add a sort key; call again for more.
	 *
	 * @param string $field     Field or core column.
	 * @param string $direction ASC or DESC.
	 * @return $this
	 * @throws \InvalidArgumentException On a bad direction.
	 */
	public function orderBy( string $field, string $direction = 'ASC' ): static {
		$dir = strtoupper( $direction );
		if ( '' === $field || ! in_array( $dir, array( 'ASC', 'DESC' ), true ) ) {
			throw new \InvalidArgumentException( 'orderBy needs a field and ASC or DESC.' );
		}
		$this->orders[] = array( $field, $dir );
		return $this;
	}

	/**
	 * Page size.
	 *
	 * @param int $limit Rows; 0 means no limit.
	 * @return $this
	 * @throws \InvalidArgumentException When negative.
	 */
	public function limit( int $limit ): static {
		if ( $limit < 0 ) {
			throw new \InvalidArgumentException( 'limit cannot be negative.' );
		}
		$this->limit = $limit;
		return $this;
	}

	/**
	 * Rows to skip.
	 *
	 * @param int $offset Rows.
	 * @return $this
	 * @throws \InvalidArgumentException When negative.
	 */
	public function offset( int $offset ): static {
		if ( $offset < 0 ) {
			throw new \InvalidArgumentException( 'offset cannot be negative.' );
		}
		$this->offset = $offset;
		return $this;
	}

	/**
	 * Page size, page number and counting mode.
	 *
	 * @param int    $per_page Rows per page, at least 1.
	 * @param int    $page     Page number, 1-based; below 1 counts as 1.
	 * @param string $count    exact | capped | none.
	 * @return $this
	 * @throws \InvalidArgumentException On a bad page size or mode.
	 */
	public function paginate( int $per_page, int $page = 1, string $count = 'exact' ): static {
		if ( $per_page < 1 || ! in_array( $count, self::COUNT_MODES, true ) ) {
			throw new \InvalidArgumentException( 'paginate needs a page size of at least 1 and a count mode of exact, capped or none.' );
		}
		$this->limit  = $per_page;
		$this->offset = ( max( 1, $page ) - 1 ) * $per_page;
		$this->count  = $count;
		return $this;
	}

	/**
	 * Counting mode set by paginate().
	 *
	 * @return string
	 */
	public function countMode(): string {
		return $this->count;
	}

	/**
	 * The spec for the compiler.
	 *
	 * @return QuerySpec
	 */
	public function toSpec(): QuerySpec {
		return new QuerySpec( $this->type, $this->subtype, $this->statuses, $this->conditions, $this->orders, $this->limit, $this->offset, $this->masks, $this->rows );
	}

	/**
	 * Add a mask condition.
	 *
	 * @param string   $field  Field.
	 * @param string   $mode   any|all|none.
	 * @param string[] $values Values.
	 * @return $this
	 * @throws \InvalidArgumentException On empty field or values.
	 */
	private function mask( string $field, string $mode, array $values ): static {
		if ( '' === $field || array() === $values ) {
			throw new \InvalidArgumentException( 'A membership condition needs a field and at least one value.' );
		}
		$this->masks[] = new MaskCondition( $field, $mode, array_map( 'strval', array_values( $values ) ) );
		return $this;
	}
}
