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

	/**
	 * Object type.
	 *
	 * @var ObjectType
	 */
	private ObjectType $type;

	/**
	 * Post type or taxonomy; empty for users.
	 *
	 * @var string
	 */
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

	/**
	 * Page size.
	 *
	 * @var int
	 */
	private int $limit = 0;

	/**
	 * Rows to skip.
	 *
	 * @var int
	 */
	private int $offset = 0;

	/**
	 * Counting mode.
	 *
	 * @var string
	 */
	private string $count = 'exact';

	/**
	 * Constructor.
	 *
	 * @param string            $target  Post type (`event`), taxonomy (`term:genre`) or `user`.
	 * @param QueryContext|null $context Resolver for toSql(), explain(), ids() and count(); none for a spec-only builder.
	 * @throws \InvalidArgumentException When the target is empty.
	 */
	public function __construct( string $target, private ?QueryContext $context = null ) {
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
		if ( is_array( $value ) ) {
			foreach ( $value as $v ) {
				if ( ! is_scalar( $v ) ) {
					throw new \InvalidArgumentException( 'Array values must be scalars.' );
				}
			}
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
	public function status( string|Status|array $status ): self {
		if ( array() === $status ) {
			throw new \InvalidArgumentException( 'status needs at least one status.' );
		}
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
	public function where( string $field, mixed $op = null, mixed $value = null ): self {
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
	public function whereBetween( string $field, array $range ): self {
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
	public function whereAny( string $field, array $values ): self {
		return $this->mask( $field, 'any', $values );
	}

	/**
	 * All of the values.
	 *
	 * @param string   $field  Multi-valued field.
	 * @param string[] $values Values.
	 * @return $this
	 */
	public function whereAll( string $field, array $values ): self {
		return $this->mask( $field, 'all', $values );
	}

	/**
	 * None of the values.
	 *
	 * @param string   $field  Multi-valued field.
	 * @param string[] $values Values.
	 * @return $this
	 */
	public function whereNone( string $field, array $values ): self {
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
	public function whereRow( string $series, callable $callback ): self {
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
	public function orderBy( string $field, string $direction = 'ASC' ): self {
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
	public function limit( int $limit ): self {
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
	public function offset( int $offset ): self {
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
	public function paginate( int $per_page, int $page = 1, string $count = 'exact' ): self {
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
	 * The SQL the query compiles to, with values inlined for reading (never run it).
	 *
	 * @return string Empty when the type has no pivot set yet.
	 */
	public function toSql(): string {
		return $this->context()->compile( $this->toSpec() )?->toSql() ?? '';
	}

	/**
	 * Compiler plan and the database's EXPLAIN.
	 *
	 * @return array<string,mixed>
	 */
	public function explain(): array {
		return $this->context()->explain( $this->toSpec() );
	}

	/**
	 * Matching object ids in query order.
	 *
	 * @return int[]
	 */
	public function ids(): array {
		return $this->context()->ids( $this->toSpec() );
	}

	/**
	 * Live count of matches, ignoring limit and offset.
	 *
	 * @return int
	 */
	public function count(): int {
		return $this->context()->count( $this->toSpec() );
	}

	/**
	 * Matching posts as native WP_Post objects, in query order.
	 *
	 * One `_prime_post_caches()` call hydrates the page; ids that no longer resolve are dropped.
	 *
	 * @return \WP_Post[]
	 * @throws \LogicException For a term or user query, or without a context.
	 */
	public function posts(): array {
		if ( ObjectType::Post !== $this->type ) {
			throw new \LogicException( 'posts() needs a post type query; use terms() or users().' );
		}
		$ids = $this->ids();
		if ( array() === $ids ) {
			return array();
		}
		_prime_post_caches( $ids, true, true );
		$posts = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post ) {
				$posts[] = $post;
			}
		}
		return $posts;
	}

	/**
	 * Typed field values for the matches, straight from the typed tables: no WP_Post is built.
	 *
	 * Rows hold `id` plus the fields asked for, typed as stored (ints, strings, lists for multi-valued fields).
	 *
	 * @param string[] $fields Top-level field names.
	 * @return array<int,array<string,mixed>>
	 * @throws \InvalidArgumentException For an unknown field or a Series.
	 */
	public function values( array $fields ): array {
		return $this->context()->values( $this->toSpec(), array_values( array_map( 'strval', $fields ) ) );
	}

	/**
	 * A real WP_Query answered by this builder through `posts_pre_query`.
	 *
	 * Core skips its own SQL; `found_posts` is the live count and `max_num_pages` follows the limit.
	 *
	 * @return \WP_Query
	 * @throws \LogicException For a term or user query.
	 */
	public function wp_query(): \WP_Query { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Public API name from the design doc.
		if ( ObjectType::Post !== $this->type ) {
			throw new \LogicException( 'wp_query() needs a post type query.' );
		}
		$posts = $this->posts();
		$found = array() === $posts ? 0 : $this->count();
		$limit = $this->limit;
		$pages = $limit > 0 ? (int) ceil( $found / $limit ) : ( $found > 0 ? 1 : 0 );
		$hook  = static function ( $pre, \WP_Query $query ) use ( &$hook, $posts, $found, $pages ) {
			if ( ! $query->get( 'nf_builder' ) ) {
				return $pre;
			}
			remove_filter( 'posts_pre_query', $hook, 10 );
			$query->found_posts   = $found;
			$query->max_num_pages = $pages;
			return $posts;
		};
		add_filter( 'posts_pre_query', $hook, 10, 2 );
		try {
			return new \WP_Query(
				array(
					'nf_builder'     => true,
					'post_type'      => $this->subtype,
					'post_status'    => 'any',
					'posts_per_page' => $limit > 0 ? $limit : -1,
					'no_found_rows'  => false,
				)
			);
		} finally {
			remove_filter( 'posts_pre_query', $hook, 10 );
		}
	}

	/**
	 * Matching terms as native WP_Term objects, in query order.
	 *
	 * `get_terms` filters from other plugins can still alter the set.
	 *
	 * @return \WP_Term[]
	 * @throws \LogicException For a non-term query, or without a context.
	 */
	public function terms(): array {
		if ( ObjectType::Term !== $this->type ) {
			throw new \LogicException( 'terms() needs a `term:<taxonomy>` query.' );
		}
		$ids = $this->ids();
		if ( array() === $ids ) {
			return array();
		}
		$found = get_terms(
			array(
				'taxonomy'   => $this->subtype,
				'include'    => $ids,
				'orderby'    => 'include',
				'hide_empty' => false,
				'number'     => 0,
			)
		);
		return is_array( $found ) ? array_values( array_filter( $found, static fn( $t ): bool => $t instanceof \WP_Term ) ) : array();
	}

	/**
	 * Matching users as native WP_User objects, in query order.
	 *
	 * @return \WP_User[]
	 * @throws \LogicException For a non-user query, or without a context.
	 */
	public function users(): array {
		if ( ObjectType::User !== $this->type ) {
			throw new \LogicException( 'users() needs a `user` query.' );
		}
		$ids = $this->ids();
		if ( array() === $ids ) {
			return array();
		}
		return get_users(
			array(
				'include' => $ids,
				'orderby' => 'include',
				'number'  => -1,
				'blog_id' => 0, // User values are network-wide (ADR-0011): do not drop users outside this site.
			)
		);
	}

	/**
	 * The context, or an error for a spec-only builder.
	 *
	 * @return QueryContext
	 * @throws \LogicException Without a context.
	 */
	private function context(): QueryContext {
		if ( null === $this->context ) {
			throw new \LogicException( 'This builder has no query context; create it with nf_query().' );
		}
		return $this->context;
	}

	/**
	 * Related-object condition: the relationship field holds the id (plain equality; the compiler resolves it).
	 *
	 * @param string $field Relationship field.
	 * @param int    $id    Related object id.
	 * @return $this
	 * @throws \InvalidArgumentException On an empty field or a non-positive id.
	 */
	public function whereRelated( string $field, int $id ): self {
		if ( $id < 1 ) {
			throw new \InvalidArgumentException( 'whereRelated() needs a positive object id.' );
		}
		return $this->where( $field, '=', $id );
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
	private function mask( string $field, string $mode, array $values ): self {
		if ( '' === $field || array() === $values ) {
			throw new \InvalidArgumentException( 'A membership condition needs a field and at least one value.' );
		}
		$this->masks[] = new MaskCondition( $field, $mode, array_map( 'strval', array_values( $values ) ) );
		return $this;
	}
}
