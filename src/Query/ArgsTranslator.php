<?php
/**
 * Translates WP_Query arguments to a QuerySpec (WP 3.3a).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Query;

use NF\Storage\ObjectType;
use NF\Storage\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Pure translation: array in, spec or null out. Anything it cannot answer exactly returns null, so the caller passes
 * the query through to core untouched (design doc, "Native WordPress queries": untranslatable clauses pass through).
 */
final class ArgsTranslator {

	private const META_OPS = array(
		'='          => '=',
		'!='         => '!=',
		'<'          => '<',
		'<='         => '<=',
		'>'          => '>',
		'>='         => '>=',
		'BETWEEN'    => 'between',
		'IN'         => 'in',
		'NOT IN'     => 'not_in',
		'EXISTS'     => 'not_null',
		'NOT EXISTS' => 'null',
	);

	/**
	 * Argument names that do not change which rows match or their order, so they are safe to ignore.
	 */
	private const IGNORED = array(
		'cache_results',
		'update_post_meta_cache',
		'update_post_term_cache',
		'update_menu_item_cache',
		'lazy_load_term_meta',
		'no_found_rows',
		'fields',
		'suppress_filters',
		'ignore_sticky_posts',
		'nf_with',
		'nf_count',
		'nf',
		'order',
		'orderby',
		'meta_key',
		'meta_query',
		'post_type',
		'post_status',
		'posts_per_page',
		'paged',
		'offset',
		'nopaging',
		'author',
		'post_parent',
		'post__in',
		'post__not_in',
		'author__in',
		'author__not_in',
		'date_query',
	);

	private const MAX_LIST = 1000;

	private const NUMERIC_TYPES = array( 'NUMERIC', 'SIGNED', 'UNSIGNED', 'DECIMAL' );

	private const CLAUSE_KEYS = array( 'key', 'value', 'compare', 'type' );

	/**
	 * Constructor.
	 *
	 * @param callable(string,string):(string|null) $fieldKind     For a meta key and post type: `text`, `number` (integer-valued only: core casts to SIGNED), `date` or `related` (multi-valued ids, LIKE probes only) for a managed single-valued
	 *                                                              scalar field; null for an unmanaged, multi-valued or serialized one.
	 * @param bool                                  $loggedIn       Whether a user is logged in; core then adds their own `private` posts to a
	 *                                                              query without `post_status`, which the translator does not answer.
	 */
	public function __construct( private $fieldKind, private bool $loggedIn = false ) {}

	/**
	 * Field kind of a key, or null.
	 *
	 * @param string $key  Meta key.
	 * @param string $type Post type.
	 * @return string|null
	 */
	private function kind( string $key, string $type ): ?string {
		$kind = ( $this->fieldKind )( $key, $type );
		return in_array( $kind, array( 'text', 'number', 'date' ), true ) ? $kind : null;
	}

	/**
	 * Whether a key is a managed multi-valued integer field (relationship, post object, user, taxonomy).
	 *
	 * @param string $key  Meta key.
	 * @param string $type Post type.
	 * @return bool
	 */
	private function isRelated( string $key, string $type ): bool {
		return 'related' === ( $this->fieldKind )( $key, $type );
	}

	/**
	 * Translate.
	 *
	 * @param array<string,mixed> $args WP_Query arguments.
	 * @return QuerySpec|null Null when any part cannot be answered exactly.
	 */
	public function translate( array $args ): ?QuerySpec {
		foreach ( array_keys( $args ) as $name ) {
			if ( in_array( $name, self::IGNORED, true ) ) {
				continue;
			}
			$v = $args[ $name ];
			if ( in_array( $name, array( 'has_password', 'post_password' ), true ) && null !== $v ) {
				return null; // Core filters on these whenever they are set, even when false or empty.
			}
			if ( 'meta_value' === $name && false === $v ) {
				return null; // Core's isset() and !== '' test makes false a filter on an empty value.
			}
			if ( null !== $v && false !== $v && '' !== $v && array() !== $v ) {
				return null; // Includes 0 and '0', which are real filters in core.
			}
		}

		$type = $args['post_type'] ?? 'post';
		if ( ! is_string( $type ) || '' === $type || 'any' === $type ) {
			return null;
		}

		$statuses = $this->statuses( $args['post_status'] ?? null );
		if ( null === $statuses ) {
			return null;
		}

		$conditions = array();
		if ( ! empty( $args['author'] ) ) {
			if ( ! is_scalar( $args['author'] ) || 1 !== preg_match( '/^\d+$/', (string) $args['author'] ) || (int) $args['author'] < 1 ) {
				return null;
			}
			$conditions[] = new Condition( 'author', '=', (int) $args['author'] );
		}
		if ( isset( $args['post_parent'] ) && '' !== $args['post_parent'] && false !== $args['post_parent'] ) {
			if ( ! is_scalar( $args['post_parent'] ) || 1 !== preg_match( '/^\d+$/', (string) $args['post_parent'] ) ) {
				return null;
			}
			$conditions[] = new Condition( 'parent', '=', (int) $args['post_parent'] );
		}

		$listed = $this->listConditions( $args );
		if ( null === $listed ) {
			return null;
		}
		$conditions = array_merge( $conditions, $listed );

		$meta_query = $args['meta_query'] ?? array();
		if ( ! is_array( $meta_query ) ) {
			return null;
		}
		$masks   = array();
		$clauses = $this->metaClauses( $meta_query, $type, $masks );
		if ( null === $clauses ) {
			return null;
		}
		if ( ! empty( $args['meta_key'] ) ) {
			// A bare meta_key without orderby only filters on existence in core.
			if ( ! is_string( $args['meta_key'] ) || null === $this->kind( $args['meta_key'], $type ) ) {
				return null;
			}
			$clauses[] = new Condition( $args['meta_key'], 'not_null' );
		}
		$conditions = array_merge( $conditions, $clauses );

		$orders = $this->orders( $args, $type );
		if ( null === $orders ) {
			return null;
		}

		$rows = array();
		if ( array_key_exists( 'nf', $args ) && null !== $args['nf'] && array() !== $args['nf'] ) {
			if ( ! is_array( $args['nf'] ) || ! $this->nfArgument( $args['nf'], $type, $masks, $rows, $orders ) ) {
				return null;
			}
		}

		$paging = $this->paging( $args );
		if ( null === $paging ) {
			return null;
		}

		return new QuerySpec( ObjectType::Post, $type, $statuses, $conditions, $orders, $paging[0], $paging[1], $masks, $rows );
	}

	/**
	 * Conditions from post__in, post__not_in, author__in, author__not_in and date_query.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return Condition[]|null Null when a value is not exactly what core would use.
	 */
	private function listConditions( array $args ): ?array {
		$out = array();
		if ( ! empty( $args['author__in'] ) && ! empty( $args['author__not_in'] ) ) {
			return null; // Core lets one win; not worth mirroring.
		}
		if ( ! empty( $args['post__in'] ) && ! empty( $args['post__not_in'] ) ) {
			return null;
		}
		foreach ( array(
			'post__in'       => array( 'ID', 'in' ),
			'post__not_in'   => array( 'ID', 'not_in' ),
			'author__in'     => array( 'author', 'in' ),
			'author__not_in' => array( 'author', 'not_in' ),
		) as $name => $map ) {
			$list = $args[ $name ] ?? null;
			if ( null === $list || array() === $list || '' === $list || false === $list ) {
				continue;
			}
			if ( ! is_array( $list ) || count( $list ) > self::MAX_LIST ) {
				return null;
			}
			$ids = array();
			foreach ( $list as $id ) {
				if ( ! is_int( $id ) && ! ( is_string( $id ) && 1 === preg_match( '/^[1-9]\d{0,17}$/', $id ) ) ) {
					return null; // Core absint()s; anything odd is left to it.
				}
				if ( (int) $id < 1 ) {
					return null;
				}
				$ids[] = (int) $id;
			}
			$out[] = new Condition( $map[0], $map[1], array_values( array_unique( $ids ) ) );
		}
		$date = $args['date_query'] ?? null;
		if ( null !== $date && array() !== $date && '' !== $date && false !== $date ) {
			$dates = $this->dateConditions( $date );
			if ( null === $dates ) {
				return null;
			}
			$out = array_merge( $out, $dates );
		}
		return $out;
	}

	/**
	 * Date conditions from `after` / `before` clauses written as full `Y-m-d H:i:s` strings on post_date, joined by AND.
	 * Date-only values and year / month / day parts default to different bounds in core, so they pass through.
	 *
	 * @param mixed $date date_query value.
	 * @return Condition[]|null
	 */
	private function dateConditions( mixed $date ): ?array {
		if ( ! is_array( $date ) ) {
			return null;
		}
		$clauses = isset( $date['after'] ) || isset( $date['before'] ) ? array( $date ) : array_filter( $date, 'is_array' );
		if ( isset( $date['relation'] ) && 'AND' !== strtoupper( (string) $date['relation'] ) ) {
			return null;
		}
		if ( array() === $clauses || count( $clauses ) > 4 ) {
			return null;
		}
		$out = array();
		foreach ( $clauses as $clause ) {
			if ( ! is_array( $clause ) ) {
				return null;
			}
			foreach ( array_keys( $clause ) as $k ) {
				if ( ! in_array( $k, array( 'after', 'before', 'inclusive', 'column', 'compare' ), true ) ) {
					return null;
				}
			}
			if ( isset( $clause['column'] ) && 'post_date' !== $clause['column'] ) {
				return null;
			}
			if ( isset( $clause['compare'] ) && '=' !== $clause['compare'] ) {
				return null;
			}
			$inclusive = ! empty( $clause['inclusive'] );
			$found     = false;
			foreach ( array(
				'after'  => $inclusive ? '>=' : '>',
				'before' => $inclusive ? '<=' : '<',
			) as $k => $op ) {
				if ( ! isset( $clause[ $k ] ) ) {
					continue;
				}
				$v = $clause[ $k ];
				if ( ! is_string( $v ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $v, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59 ) {
					return null;
				}
				$out[] = new Condition( 'date', $op, $v );
				$found = true;
			}
			if ( ! $found ) {
				return null;
			}
		}
		return $out;
	}

	/**
	 * Statuses, or null for anything but a plain list of named statuses.
	 *
	 * @param mixed $status post_status value.
	 * @return Status[]|null
	 */
	private function statuses( mixed $status ): ?array {
		if ( null === $status || '' === $status ) {
			return $this->loggedIn ? null : array( Status::Publish );
		}
		return 'publish' === $status ? array( Status::Publish ) : null; // Other statuses depend on the user's capabilities (WP 3.3b).
	}

	/**
	 * Flat AND meta clauses; nesting, OR, named clauses and unmanaged keys return null.
	 *
	 * @param array<mixed>    $meta_query The meta_query argument.
	 * @param string          $post_type  Post type.
	 * @param MaskCondition[] $masks      Receives membership conditions from `LIKE '"id"'` on relationship keys.
	 * @return Condition[]|null
	 */
	private function metaClauses( array $meta_query, string $post_type, array &$masks ): ?array {
		$out = array();
		foreach ( $meta_query as $k => $clause ) {
			if ( 'relation' === $k ) {
				if ( 'AND' !== strtoupper( (string) $clause ) ) {
					return null;
				}
				continue;
			}
			if ( ! is_int( $k ) || ! is_array( $clause ) || isset( $clause[0] ) ) {
				return null; // Nested group or named clause.
			}
			$key = $clause['key'] ?? null;
			if ( is_string( $key ) && '' !== $key && 'LIKE' === strtoupper( (string) ( $clause['compare'] ?? '' ) ) && $this->isRelated( $key, $post_type ) ) {
				// ACF's serialized-array lookup `LIKE '"123"'` on a relationship key becomes a membership test.
				$like  = $clause['value'] ?? null;
				$ctype = strtoupper( (string) ( $clause['type'] ?? '' ) );
				if ( ! is_string( $like ) || 1 !== preg_match( '/^"([1-9]\d*)"$/', $like, $m ) || ( '' !== $ctype && 'CHAR' !== $ctype ) || array() !== array_diff( array_keys( $clause ), self::CLAUSE_KEYS ) ) {
					return null;
				}
				$masks[] = new MaskCondition( $key, 'any', array( $m[1] ) );
				continue;
			}
			$kind = is_string( $key ) && '' !== $key ? $this->kind( $key, $post_type ) : null;
			if ( null === $kind || array() !== array_diff( array_keys( $clause ), self::CLAUSE_KEYS ) ) {
				return null;
			}
			$compare = strtoupper( (string) ( $clause['compare'] ?? '=' ) );
			$op      = self::META_OPS[ $compare ] ?? null;
			if ( null === $op ) {
				return null; // LIKE, REGEXP and the rest are for later WPs.
			}
			// Core compares as CHAR unless typed; NF compares by the field's own type. Only agree when they match.
			$type = isset( $clause['type'] ) && is_string( $clause['type'] ) ? strtoupper( $clause['type'] ) : '';
			if ( 'number' === $kind ? ! in_array( $type, self::NUMERIC_TYPES, true ) : ( '' !== $type && 'CHAR' !== $type ) ) {
				return null;
			}
			if ( 'text' === $kind && in_array( $op, array( '<', '<=', '>', '>=', 'between' ), true ) ) {
				return null; // Core compares text by the column collation; leave ranges on text to core.
			}
			if ( in_array( $op, array( 'null', 'not_null' ), true ) ) {
				$out[] = new Condition( $key, $op );
				continue;
			}
			if ( ! array_key_exists( 'value', $clause ) ) {
				return null;
			}
			$value = $clause['value'];
			if ( in_array( $op, array( 'between', 'in', 'not_in' ), true ) ) {
				if ( ! is_array( $value ) ) {
					$parts = preg_split( '/[,\s]+/', (string) $value );
					$value = false === $parts ? array() : $parts;
				}
				$value = array_values( $value );
				if ( array() === $value || ( 'between' === $op && 2 !== count( $value ) ) ) {
					return null;
				}
			} elseif ( ! is_scalar( $value ) ) {
				return null;
			}
			foreach ( (array) $value as $one ) {
				if ( ! is_scalar( $one ) ) {
					return null;
				}
				$str = (string) $one;
				// Core compares dates as raw strings; NF normalises them. Only agree on one canonical form.
				if ( 'date' === $kind && 1 !== preg_match( '/^\d{8}$/', $str ) ) {
					return null;
				}
				if ( 'number' === $kind && 1 !== preg_match( '/^-?\d+$/', $str ) ) {
					return null;
				}
			}
			try {
				$out[] = Builder::condition( $key, $op, $value );
			} catch ( \InvalidArgumentException ) {
				return null;
			}
		}
		return $out;
	}

	/**
	 * The NF argument: `row`, `related`, `orderby` and `count` (count is read by PreQuery).
	 *
	 * @param array<mixed>                        $nf     Argument.
	 * @param string                              $type   Post type.
	 * @param MaskCondition[]                     $masks  Receives related conditions.
	 * @param RowCondition[]                      $rows   Receives same-row conditions.
	 * @param array<int,array{0:string,1:string}> $orders Replaced when `orderby` is given.
	 * @return bool False when any part is not understood.
	 */
	private function nfArgument( array $nf, string $type, array &$masks, array &$rows, array &$orders ): bool {
		if ( array() !== array_diff( array_keys( $nf ), array( 'row', 'related', 'orderby', 'count' ) ) ) {
			return false;
		}
		if ( isset( $nf['count'] ) && ! in_array( $nf['count'], array( 'exact', 'capped' ), true ) ) {
			return false;
		}
		foreach ( (array) ( $nf['related'] ?? array() ) as $field => $ids ) {
			if ( ! is_string( $field ) || ! $this->isRelated( $field, $type ) ) {
				return false;
			}
			$list = array_values( (array) $ids );
			if ( array() === $list ) {
				return false;
			}
			foreach ( $list as $id ) {
				if ( ! ( is_int( $id ) || is_string( $id ) ) || 1 !== preg_match( '/^[1-9]\d*$/', (string) $id ) ) {
					return false;
				}
			}
			$masks[] = new MaskCondition( $field, 'any', array_map( 'strval', $list ) );
		}
		$ops = array_values( self::META_OPS );
		foreach ( (array) ( $nf['row'] ?? array() ) as $series => $conds ) {
			if ( ! is_string( $series ) || ! is_array( $conds ) || array() === $conds ) {
				return false;
			}
			$built = array();
			foreach ( $conds as $c ) {
				if ( ! is_array( $c ) || count( $c ) < 2 || count( $c ) > 3 || ! isset( $c[0], $c[1] ) || ! is_string( $c[0] ) || ! in_array( $c[1], $ops, true ) ) {
					return false;
				}
				try {
					$built[] = Builder::condition( $c[0], $c[1], $c[2] ?? null );
				} catch ( \InvalidArgumentException ) {
					return false;
				}
			}
			$rows[] = new RowCondition( $series, $built );
		}
		if ( isset( $nf['orderby'] ) ) {
			if ( ! is_array( $nf['orderby'] ) || array() === $nf['orderby'] ) {
				return false;
			}
			$orders = array();
			foreach ( $nf['orderby'] as $field => $dir ) {
				$dir = is_string( $dir ) ? strtoupper( $dir ) : '';
				if ( ! is_string( $field ) || ! in_array( $dir, array( 'ASC', 'DESC' ), true ) ) {
					return false;
				}
				if ( ! in_array( $field, array( 'date', 'modified', 'menu_order', 'parent', 'ID' ), true ) && null === $this->kind( $field, $type ) ) {
					return false;
				}
				$orders[] = array( $field, $dir );
			}
		}
		return true;
	}

	/**
	 * Sort keys, matching core: `meta_value` and `meta_value_num` order by the `meta_key` field.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @param string              $type Post type.
	 * @return array<int,array{0:string,1:string}>|null
	 */
	private function orders( array $args, string $type ): ?array {
		$dir     = strtoupper( (string) ( $args['order'] ?? 'DESC' ) );
		$orderby = $args['orderby'] ?? 'date';
		if ( ! in_array( $dir, array( 'ASC', 'DESC' ), true ) || ! is_string( $orderby ) ) {
			return null; // Arrays of orderby and per-clause orders are for later.
		}
		$parts = preg_split( '/\s+/', trim( $orderby ) );
		if ( false === $parts || array() === $parts || '' === $parts[0] ) {
			return null;
		}
		$out = array();
		foreach ( $parts as $part ) {
			switch ( $part ) {
				case 'date':
				case 'modified':
				case 'menu_order':
				case 'parent':
					$out[] = array( $part, $dir );
					break;
				case 'ID':
					$out[] = array( 'ID', $dir );
					break;
				case 'meta_value':
				case 'meta_value_num':
					$key  = $args['meta_key'] ?? null;
					$kind = is_string( $key ) ? $this->kind( $key, $type ) : null;
					// meta_value sorts as text in core, meta_value_num as a number.
					if ( null === $kind || 'text' === $kind || ( 'meta_value_num' === $part ) !== ( 'number' === $kind ) ) {
						return null;
					}
					$out[] = array( $key, $dir );
					break;
				default:
					return null; // title, name, rand, relevance, post__in, none, ...
			}
		}
		return $out;
	}

	/**
	 * Limit and offset with core's rules: offset overrides paged; -1 and nopaging mean no limit.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array{0:int,1:int}|null
	 */
	private function paging( array $args ): ?array {
		if ( ! empty( $args['nopaging'] ) ) {
			return array( 0, 0 );
		}
		$per = $args['posts_per_page'] ?? get_option( 'posts_per_page', 10 );
		foreach ( array( 'paged', 'offset' ) as $n ) {
			if ( isset( $args[ $n ] ) && '' !== $args[ $n ] && ( ! is_numeric( $args[ $n ] ) || (int) $args[ $n ] < 0 ) ) {
				return null;
			}
		}
		if ( ! is_numeric( $per ) ) {
			return null;
		}
		$per = (int) $per;
		if ( -1 === $per ) {
			return array( 0, 0 );
		}
		if ( $per < 1 ) {
			return null;
		}
		if ( isset( $args['offset'] ) && is_numeric( $args['offset'] ) ) {
			return array( $per, (int) $args['offset'] );
		}
		$paged = max( 1, (int) ( $args['paged'] ?? 0 ) );
		return array( $per, ( $paged - 1 ) * $per );
	}
}
