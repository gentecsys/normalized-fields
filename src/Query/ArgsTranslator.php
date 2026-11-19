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
		'='           => '=',
		'!='          => '!=',
		'<'           => '<',
		'<='          => '<=',
		'>'           => '>',
		'>='          => '>=',
		'BETWEEN'     => 'between',
		'IN'          => 'in',
		'NOT IN'      => 'not_in',
		'EXISTS'      => 'not_null',
		'NOT EXISTS'  => 'null',
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
		'order',
		'orderby',
		'meta_key',
		'meta_type',
		'meta_query',
		'post_type',
		'post_status',
		'posts_per_page',
		'posts_per_archive_page',
		'paged',
		'page',
		'offset',
		'nopaging',
		'author',
		'post_parent',
	);

	/**
	 * Constructor.
	 *
	 * @param callable(string):bool $isManaged Whether NF manages a meta key as a field of the post type.
	 */
	public function __construct( private $isManaged ) {}

	/**
	 * Translate.
	 *
	 * @param array<string,mixed> $args WP_Query arguments.
	 * @return QuerySpec|null Null when any part cannot be answered exactly.
	 */
	public function translate( array $args ): ?QuerySpec {
		foreach ( array_keys( $args ) as $name ) {
			if ( ! in_array( $name, self::IGNORED, true ) && ! empty( $args[ $name ] ) ) {
				return null;
			}
		}

		$type = $args['post_type'] ?? 'post';
		if ( ! is_string( $type ) || '' === $type || 'any' === $type ) {
			return null;
		}

		$statuses = $this->statuses( $args['post_status'] ?? 'publish' );
		if ( null === $statuses ) {
			return null;
		}

		$conditions = array();
		if ( ! empty( $args['author'] ) ) {
			if ( ! is_numeric( $args['author'] ) || (int) $args['author'] < 1 ) {
				return null;
			}
			$conditions[] = new Condition( 'author', '=', (int) $args['author'] );
		}
		if ( isset( $args['post_parent'] ) && '' !== $args['post_parent'] && false !== $args['post_parent'] ) {
			if ( ! is_numeric( $args['post_parent'] ) ) {
				return null;
			}
			$conditions[] = new Condition( 'parent', '=', (int) $args['post_parent'] );
		}

		$meta_query = $args['meta_query'] ?? array();
		if ( ! is_array( $meta_query ) ) {
			return null;
		}
		$clauses = $this->metaClauses( $meta_query );
		if ( null === $clauses ) {
			return null;
		}
		if ( ! empty( $args['meta_key'] ) ) {
			// A bare meta_key without orderby only filters on existence in core.
			if ( ! is_string( $args['meta_key'] ) || ! ( $this->isManaged )( $args['meta_key'] ) ) {
				return null;
			}
			$clauses[] = new Condition( $args['meta_key'], 'not_null' );
		}
		$conditions = array_merge( $conditions, $clauses );

		$orders = $this->orders( $args );
		if ( null === $orders ) {
			return null;
		}

		$paging = $this->paging( $args );
		if ( null === $paging ) {
			return null;
		}

		return new QuerySpec( ObjectType::Post, $type, $statuses, $conditions, $orders, $paging[0], $paging[1] );
	}

	/**
	 * Statuses, or null for anything but a plain list of named statuses.
	 *
	 * @param mixed $status post_status value.
	 * @return Status[]|null
	 */
	private function statuses( mixed $status ): ?array {
		$names = is_array( $status ) ? $status : array_map( 'trim', explode( ',', (string) $status ) );
		$out   = array();
		foreach ( $names as $name ) {
			$st = is_string( $name ) ? Status::fromName( $name ) : null;
			if ( null === $st ) {
				return null; // 'any', custom statuses.
			}
			$out[] = $st;
		}
		return array() === $out ? null : $out;
	}

	/**
	 * Flat AND meta clauses; nesting, OR, named clauses and unmanaged keys return null.
	 *
	 * @param array<mixed> $meta_query The meta_query argument.
	 * @return Condition[]|null
	 */
	private function metaClauses( array $meta_query ): ?array {
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
			if ( ! is_string( $key ) || '' === $key || ! ( $this->isManaged )( $key ) ) {
				return null;
			}
			$compare = strtoupper( (string) ( $clause['compare'] ?? '=' ) );
			$op      = self::META_OPS[ $compare ] ?? null;
			if ( null === $op ) {
				return null; // LIKE, REGEXP and the rest are for later WPs.
			}
			if ( ! empty( $clause['type'] ) && ! in_array( strtoupper( (string) $clause['type'] ), array( 'NUMERIC', 'CHAR', 'DATE', 'DATETIME', 'DECIMAL', 'SIGNED', 'UNSIGNED' ), true ) ) {
				return null;
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
				$value = is_array( $value ) ? array_values( $value ) : array_map( 'trim', explode( ',', (string) $value ) );
				if ( array() === $value || ( 'between' === $op && 2 !== count( $value ) ) ) {
					return null;
				}
			} elseif ( ! is_scalar( $value ) ) {
				return null;
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
	 * Sort keys, matching core: `meta_value` and `meta_value_num` order by the `meta_key` field.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<int,array{0:string,1:string}>|null
	 */
	private function orders( array $args ): ?array {
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
					$key = $args['meta_key'] ?? null;
					if ( ! is_string( $key ) || ! ( $this->isManaged )( $key ) ) {
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
		if ( isset( $args['offset'] ) && '' !== $args['offset'] ) {
			return array( $per, max( 0, (int) $args['offset'] ) );
		}
		$paged = max( 1, (int) ( $args['paged'] ?? 0 ), (int) ( $args['page'] ?? 0 ) );
		return array( $per, ( $paged - 1 ) * $per );
	}
}
