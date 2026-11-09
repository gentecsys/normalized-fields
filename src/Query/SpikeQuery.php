<?php
/**
 * Spike query: two WP_Query shapes translated to the pivot and typed tables.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Query;

use NF\Backend\BackendInterface;
use NF\Pivot\Pivot;
use NF\Storage\DataTable;
use NF\Storage\ObjectType;
use NF\Storage\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Translates a WP_Query argument array to SQL on nf_pivot (WP 1.3). It understands exactly:
 *
 * - `meta_query` clauses (AND only) on slotted single-valued fields: `=`, `!=`, `>`, `>=`, `<`, `<=`;
 * - a `LIKE '"123"'` clause on a multi-valued int field (ACF relationship), i.e. the reverse relationship;
 * - `post_type` (one), `post_status`, `orderby` (`date`, `ID`, `meta_value`, `meta_value_num`) with `meta_key`, `order`;
 * - `posts_per_page`, `paged`, `offset`, `nopaging`, `no_found_rows`.
 *
 * Anything else returns null from translate() and the caller leaves the query to core. The full compiler is WP 3.1.
 * Reads only: never writes (anonymous reads never write, ADR-0012).
 */
final class SpikeQuery {

	private const OPS = array( '=', '!=', '>', '>=', '<', '<=' );

	/**
	 * Arguments the translator understands; any other key means the query is not translated.
	 */
	private const KNOWN = array( 'post_type', 'post_status', 'meta_query', 'meta_key', 'orderby', 'order', 'posts_per_page', 'paged', 'offset', 'nopaging', 'no_found_rows', 'fields' );

	/**
	 * Constructor.
	 *
	 * @param BackendInterface $db    Backend.
	 * @param Pivot            $pivot Pivot.
	 */
	public function __construct( private BackendInterface $db, private Pivot $pivot ) {}

	/**
	 * Run a query.
	 *
	 * @param array<string,mixed> $args WP_Query arguments.
	 * @return array{ids:int[],found_posts:int,max_num_pages:int}|null Null when the arguments are outside the spike subset.
	 */
	public function run( array $args ): ?array {
		$plan = $this->translate( $args );
		if ( null === $plan ) {
			return null;
		}
		$ids   = array_map( 'intval', array_column( $this->db->select( $plan['sql'], $plan['params'] ), 'object_id' ) );
		$found = count( $ids );
		$max   = 0;
		if ( $plan['limit'] > 0 ) {
			// Core returns found_posts = 0 and max_num_pages = 0 when the page of posts is empty (also past the last page).
			if ( $found > 0 && ! $plan['no_found_rows'] ) {
				$found = (int) ( $this->db->select( $plan['count_sql'], $plan['count_params'] )[0]['c'] ?? 0 );
				$max   = (int) ceil( $found / $plan['limit'] );
			} else {
				$found = 0;
			}
		} elseif ( $plan['no_found_rows'] ) {
			$found = 0;
		}
		return array(
			'ids'           => $ids,
			'found_posts'   => $found,
			'max_num_pages' => $max,
		);
	}

	/**
	 * The SQL for a query, for toSql() and tests.
	 *
	 * @param array<string,mixed> $args WP_Query arguments.
	 * @return array{sql:string,params:array<int,scalar>,count_sql:string,count_params:array<int,scalar>,limit:int,no_found_rows:bool}|null
	 */
	public function translate( array $args ): ?array {
		if ( array() !== array_diff( array_keys( $args ), self::KNOWN ) || ( isset( $args['fields'] ) && 'ids' !== $args['fields'] ) ) {
			return null;
		}
		$type = $args['post_type'] ?? 'post';
		if ( ! is_string( $type ) ) {
			return null;
		}
		$statuses = array();
		foreach ( (array) ( $args['post_status'] ?? 'publish' ) as $name ) {
			$st = Status::fromName( (string) $name );
			if ( null === $st ) {
				return null;
			}
			$statuses[] = $st->value;
		}
		$pivotId = $this->pivot->findSetId( ObjectType::Post, $type );
		if ( null === $pivotId ) {
			return null;
		}
		$slots   = $this->pivot->slots( $pivotId );
		$byName  = array();
		foreach ( $slots as $slot => $f ) {
			$byName[ $f['name'] ] = array( $slot, $f['table'] );
		}

		$pivotTable = $this->db->table( 'nf_pivot' );
		$where      = array( 'p.pivot_id = ?', 'p.status IN (' . implode( ', ', array_fill( 0, count( $statuses ), '?' ) ) . ')' );
		$params     = array_merge( array( $pivotId ), $statuses );
		$driver     = null;

		$meta = $args['meta_query'] ?? array();
		if ( ! is_array( $meta ) || ( isset( $meta['relation'] ) && 'AND' !== strtoupper( (string) $meta['relation'] ) ) ) {
			return null;
		}
		unset( $meta['relation'] );
		foreach ( $meta as $clause ) {
			if ( ! is_array( $clause ) || ! isset( $clause['key'], $clause['value'] ) || ! is_scalar( $clause['value'] ) ) {
				return null;
			}
			$compare = strtoupper( (string) ( $clause['compare'] ?? '=' ) );
			$name    = (string) $clause['key'];
			if ( isset( $byName[ $name ] ) && in_array( $compare, self::OPS, true ) ) {
				[ $slot, $table ] = $byName[ $name ];
				$value            = $this->slotValue( $table, $clause, (string) $clause['value'], $compare );
				if ( null === $value ) {
					return null;
				}
				$where[]  = "p.{$slot} {$compare} ?";
				$params[] = $value;
				continue;
			}
			// Reverse relationship: ACF stores a relationship as a serialized array and queries it with LIKE '"123"'.
			$multi = 'LIKE' === $compare ? $this->multiIntField( $name ) : null;
			if ( null !== $multi && 1 === preg_match( '/^"(\d+)"$/', (string) $clause['value'], $m ) && null === $driver ) {
				$driver = array( $multi, (int) $m[1] );
				continue;
			}
			return null;
		}

		// Order.
		$orderby = $args['orderby'] ?? 'date';
		$order   = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		if ( ! is_string( $orderby ) ) {
			return null;
		}
		switch ( $orderby ) {
			case 'date':
				$orderSql = $this->db->dialect()->nullsOrder( 'published', $order, 'LAST' ) . ", p.object_id {$order}";
				break;
			case 'ID':
				$orderSql = "p.object_id {$order}";
				break;
			case 'meta_value':
			case 'meta_value_num':
				$key = (string) ( $args['meta_key'] ?? '' );
				if ( ! isset( $byName[ $key ] ) ) {
					return null;
				}
				// Core joins the sort key with an inner join, so objects without a value are excluded.
				if ( 'meta_value' === $orderby && DataTable::Date !== $byName[ $key ][1] ) {
					return null; // Core sorts meta_value as a string; only dates sort the same way.
				}
				$where[]  = "p.{$byName[ $key ][0]} IS NOT NULL";
				$orderSql = "p.{$byName[ $key ][0]} {$order}, p.object_id {$order}";
				break;
			default:
				return null;
		}

		// Paging, with core's meaning.
		$ppp = (int) ( $args['posts_per_page'] ?? get_option( 'posts_per_page' ) );
		if ( ! empty( $args['nopaging'] ) ) {
			$ppp = -1;
		}
		$limit  = $ppp > 0 ? $ppp : 0;
		$offset = 0;
		if ( $limit > 0 ) {
			$paged  = max( 1, (int) ( $args['paged'] ?? 1 ) );
			$offset = isset( $args['offset'] ) && '' !== $args['offset'] ? max( 0, (int) $args['offset'] ) : ( $paged - 1 ) * $limit;
		}

		$dialect = $this->db->dialect();
		if ( null !== $driver ) {
			// Semi-join: one index seek on nf_int.by_value (field_id, value), then pivot rows by primary key.
			[ $def, $target ] = $driver;
			$where[]          = 'p.object_id IN (SELECT m.object_id FROM ' . $this->db->table( $def['table']->table() ) . ' m WHERE m.field_id = ? AND m.value = ? AND m.object_type = ?)';
			$params[]         = $def['id'];
			$params[]         = $target;
			$params[]         = ObjectType::Post->value;
		}
		$whereS = implode( ' AND ', $where );
		$from   = $pivotTable . ' p';
		$sql    = 'SELECT p.object_id FROM ' . $from . ' WHERE ' . $whereS . ' ORDER BY ' . $orderSql;
		if ( $limit > 0 ) {
			$sql .= ' ' . $dialect->limit( $limit, $offset );
		}
		$count = 'SELECT COUNT(*) AS c FROM ' . $from . ' WHERE ' . $whereS;

		return array(
			'sql'           => $sql,
			'params'        => $params,
			'count_sql'     => $count,
			'count_params'  => $params,
			'limit'         => $limit,
			'no_found_rows' => ! empty( $args['no_found_rows'] ),
		);
	}

	/**
	 * Value in the slot's type, or null when it cannot be converted.
	 *
	 * @param DataTable            $table  Slot data table.
	 * @param array<string,mixed>  $clause Meta clause.
	 * @param string               $raw    Raw value.
	 * @return int|string|null
	 */
	private function slotValue( DataTable $table, array $clause, string $raw, string $compare ): int|string|null {
		$numeric = in_array( strtoupper( (string) ( $clause['type'] ?? '' ) ), array( 'NUMERIC', 'DECIMAL', 'SIGNED', 'UNSIGNED' ), true );
		switch ( $table ) {
			case DataTable::Int:
				// Core compares as strings unless typed; only `=` on an integer is the same either way.
				return is_numeric( $raw ) && ( $numeric || '=' === $compare ) ? (int) $raw : null;
			case DataTable::Dec:
				return is_numeric( $raw ) && $numeric ? $raw : null;
			case DataTable::Date:
				// ACF stores Ymd; compare as DATE like core's CAST(... AS DATE).
				if ( 1 === preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $raw, $m ) && 'DATE' === strtoupper( (string) ( $clause['type'] ?? '' ) ) ) {
					return "{$m[1]}-{$m[2]}-{$m[3]} 00:00:00";
				}
				return null;
			default:
				return null;
		}
	}

	/**
	 * A top-level multi-valued int field by name.
	 *
	 * @param string $name Field name.
	 * @return array{id:int,table:DataTable}|null
	 */
	private function multiIntField( string $name ): ?array {
		$rows = $this->db->select(
			'SELECT field_id FROM ' . $this->db->table( 'nf_fields' ) . " WHERE field_name = ? AND parent_id = 0 AND data_table = 'int' AND multi = 1 ORDER BY field_id LIMIT 2",
			array( $name )
		);
		return 1 === count( $rows ) ? array(
			'id'    => (int) $rows[0]['field_id'],
			'table' => DataTable::Int,
		) : null;
	}
}
