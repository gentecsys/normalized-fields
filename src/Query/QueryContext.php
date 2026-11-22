<?php
/**
 * Query context: resolves a spec's pivot set, slots, fields, row slots and statistics (WP 3.2b).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Query;

use NF\Backend\BackendInterface;
use NF\Pivot\Dictionary;
use NF\Pivot\FieldScope;
use NF\Pivot\Pivot;
use NF\Storage\DataTable;
use NF\Storage\FieldDef;

defined( 'ABSPATH' ) || exit;

/**
 * Reads NF's own tables through the backend only (no core tables, ADR-0009) and runs compiled queries.
 * Read only: it never writes, so it is safe on anonymous requests. Nothing is cached across calls: slots, stats and the
 * site prefix can change within a request (switch_blog, writes); caching with the pivot write_version comes with WP 3.4.
 */
final class QueryContext {

	/**
	 * Length at which an nf_str value is a prefix of the full text in nf_text (mirrors Store).
	 */
	private const STR_MAX = 191;

	/**
	 * Constructor.
	 *
	 * @param BackendInterface $db         Backend.
	 * @param Pivot            $pivot      Pivot service (set ids and slots).
	 * @param Dictionary|null  $dictionary Dictionary for bitmask bits and row-slot strings.
	 */
	public function __construct( private BackendInterface $db, private Pivot $pivot, private ?Dictionary $dictionary = null ) {}

	/**
	 * Compile a spec against the stored pivot set.
	 *
	 * @param QuerySpec $spec Query.
	 * @return CompiledQuery|null Null when the type has no pivot set (nothing was ever stored for it).
	 * @throws UnsupportedQueryException When the pivot and probes cannot answer the query.
	 */
	public function compile( QuerySpec $spec ): ?CompiledQuery {
		$in = $this->resolve( $spec );
		if ( null === $in ) {
			return null;
		}
		return ( new Compiler( $this->db, $this->dictionary ) )->compile( $spec, $in['pivot'], $in['slots'], $in['fields'], $in['rowSlots'], $in['stats'] );
	}

	/**
	 * Matching object ids in query order.
	 *
	 * @param QuerySpec $spec Query.
	 * @return int[]
	 */
	public function ids( QuerySpec $spec ): array {
		$c = $this->compile( $spec );
		if ( null === $c ) {
			return array();
		}
		return array_map( static fn( array $r ): int => (int) $r['object_id'], $this->db->select( $c->sql, $c->params ) );
	}

	/**
	 * Cursor values (sort columns plus id) of one matching object, for seek pagination.
	 *
	 * @param QuerySpec $spec Query.
	 * @param int       $id   Object id.
	 * @return array<int,int|string|null>|null Null when the object is not in the pivot set; a null sort value stays null in the list.
	 * @throws UnsupportedQueryException When a sort field has no slot.
	 */
	public function cursor( QuerySpec $spec, int $id ): ?array {
		$in = $this->resolve( $spec );
		if ( null === $in ) {
			return null;
		}
		$cols = ( new Compiler( $this->db, $this->dictionary ) )->cursorColumns( $spec, $in['slots'] );
		$d    = $this->db->dialect();
		$list = implode( ', ', array_map( static fn( string $c ): string => $d->quoteIdentifier( $c ), $cols ) );
		$rows = $this->db->select( 'SELECT ' . $list . ' FROM ' . $this->db->table( 'nf_pivot' ) . ' WHERE pivot_id = ? AND object_id = ?', array( $in['pivot'], $id ) );
		if ( array() === $rows ) {
			return null;
		}
		$out = array();
		foreach ( $cols as $c ) {
			$v = $rows[0][ $c ] ?? null;
			if ( null === $v ) {
				$out[] = null;
				continue;
			}
			$out[] = is_numeric( $v ) && ! str_contains( (string) $v, '-' ) && ! str_contains( (string) $v, '.' ) ? (int) $v : (string) $v;
		}
		return $out;
	}

	/**
	 * Live count of matching objects, ignoring limit and offset.
	 *
	 * @param QuerySpec $spec Query.
	 * @return int
	 */
	public function count( QuerySpec $spec ): int {
		$c = $this->compile( $spec );
		if ( null === $c ) {
			return 0;
		}
		$rows = $this->db->select( $c->count_sql, $c->count_params );
		return (int) ( $rows[0]['c'] ?? 0 );
	}

	/**
	 * The compiler's plan plus the database's own EXPLAIN of the page statement.
	 *
	 * @param QuerySpec $spec Query.
	 * @return array<string,mixed>
	 */
	public function explain( QuerySpec $spec ): array {
		$c = $this->compile( $spec );
		if ( null === $c ) {
			return array(
				'plan' => array( 'empty' => true ),
				'sql'  => '',
				'db'   => array(),
			);
		}
		return array(
			'plan' => $c->plan,
			'sql'  => $c->toSql(),
			'db'   => $this->db->select( 'EXPLAIN ' . $c->sql, $c->params ),
		);
	}

	/**
	 * Typed projection for the objects a query matches: one read per field on its typed table, no object built.
	 *
	 * Each row has `id` plus the requested fields: scalars as `int` or `string` (decimals and dates stay strings),
	 * multi-valued fields as lists in stored order, missing values as null or an empty list.
	 *
	 * @param QuerySpec $spec   Query.
	 * @param string[]  $fields Top-level field names.
	 * @return array<int,array<string,mixed>>
	 * @throws \InvalidArgumentException For an unknown field or one without a typed value table (Series, groups).
	 */
	public function values( QuerySpec $spec, array $fields ): array {
		$in = $this->resolve( $spec );
		if ( null === $in ) {
			return array();
		}
		$defs = array();
		foreach ( $fields as $name ) {
			$name = (string) $name;
			if ( 'id' === $name ) {
				continue;
			}
			if ( ! isset( $in['fields'][ $name ] ) || ! $in['fields'][ $name ]->table->hasValues() ) {
				throw new \InvalidArgumentException( sprintf( 'values() cannot project the field "%s".', esc_html( $name ) ) );
			}
			$defs[ $name ] = $in['fields'][ $name ];
		}
		$ids = $this->ids( $spec );
		if ( array() === $ids ) {
			return array();
		}
		$marks = implode( ',', array_fill( 0, count( $ids ), '?' ) );
		$data  = array();
		foreach ( $defs as $name => $def ) {
			$params = array_merge( array( $spec->type->value, $def->id ), $ids );
			$found  = $this->db->select(
				'SELECT object_id, position, value FROM ' . $this->db->table( $def->table->table() ) . ' WHERE object_type = ? AND field_id = ? AND row_id = 0 AND object_id IN (' . $marks . ') ORDER BY object_id, position',
				$params
			);
			if ( DataTable::Str === $def->table ) {
				$found = $this->spill( $found, $def->id, $spec->type->value, $ids, $marks );
			}
			foreach ( $found as $r ) {
				$data[ $name ][ (int) $r['object_id'] ][] = $def->table->fromDb( $r['value'] );
			}
		}
		$out = array();
		foreach ( $ids as $id ) {
			$row = array( 'id' => $id );
			foreach ( $defs as $name => $def ) {
				$list         = $data[ $name ][ $id ] ?? array();
				$row[ $name ] = $def->multi ? $list : ( $list[0] ?? null );
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Replace 191-char prefixes with the full text from nf_text, as Store does for single reads.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows of nf_str.
	 * @param int                            $field_id Field id.
	 * @param int                            $type     Object type value.
	 * @param int[]                          $ids      Object ids.
	 * @param string                         $marks    Placeholder list for $ids.
	 * @return array<int,array<string,mixed>>
	 */
	private function spill( array $rows, int $field_id, int $type, array $ids, string $marks ): array {
		$long = false;
		foreach ( $rows as $r ) {
			if ( mb_strlen( (string) $r['value'] ) >= self::STR_MAX ) {
				$long = true;
				break;
			}
		}
		if ( ! $long ) {
			return $rows;
		}
		$full = $this->db->select(
			'SELECT object_id, position, value FROM ' . $this->db->table( 'nf_text' ) . ' WHERE object_type = ? AND field_id = ? AND row_id = 0 AND object_id IN (' . $marks . ')',
			array_merge( array( $type, $field_id ), $ids )
		);
		$by   = array();
		foreach ( $full as $f ) {
			$by[ (int) $f['object_id'] ][ (int) $f['position'] ] = $f['value'];
		}
		foreach ( $rows as $i => $r ) {
			$key = (int) $r['position'];
			$oid = (int) $r['object_id'];
			if ( isset( $by[ $oid ][ $key ] ) && mb_strlen( (string) $r['value'] ) >= self::STR_MAX ) {
				$rows[ $i ]['value'] = $by[ $oid ][ $key ];
			}
		}
		return $rows;
	}

	/**
	 * Resolve everything the compiler needs for a type.
	 *
	 * @param QuerySpec $spec Query.
	 * @return array{pivot:int,slots:array<string,array{id:int,name:string,table:DataTable}>,fields:array<string,FieldDef>,rowSlots:array<string,array{id:int,slots:array<string,array{name:string,table:DataTable,id:int}>}>,stats:array<string,array{rows:int,distinct:int}>}|null
	 */
	private function resolve( QuerySpec $spec ): ?array {
		$pivotId = $this->pivot->findSetId( $spec->type, $spec->subtype );
		if ( null === $pivotId ) {
			return null;
		}

		$fields = array();
		$stats  = array();
		$by     = array();
		$rows   = $this->db->select( 'SELECT field_id, field_key, field_name, parent_id, data_table, multi, row_count, distinct_count FROM ' . $this->db->table( 'nf_fields' ) . ' WHERE parent_id = 0 ORDER BY field_id' );
		foreach ( $rows as $r ) {
			$by[ (string) $r['field_name'] ][] = $r;
		}
		$used = array();
		foreach ( $spec->conditions as $c ) {
			$used[ $c->field ] = true;
		}
		foreach ( $spec->masks as $m ) {
			$used[ $m->field ] = true;
		}
		foreach ( $spec->orders as $o ) {
			$used[ $o[0] ] = true;
		}
		$scope = new FieldScope();
		foreach ( $by as $name => $cands ) {
			try {
				$r = $scope->choose( $cands, $spec->type, $spec->subtype, (string) $name );
			} catch ( \InvalidArgumentException $e ) {
				if ( isset( $used[ (string) $name ] ) ) {
					throw new UnsupportedQueryException( $e->getMessage() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				}
				continue;
			}
			if ( null === $r ) {
				continue;
			}
			$name            = (string) $name;
			$fields[ $name ] = new FieldDef( (int) $r['field_id'], (string) $r['field_key'], $name, 0, DataTable::from( (string) $r['data_table'] ), 1 === (int) $r['multi'] );
			$stats[ $name ]  = array(
				'rows'     => (int) $r['row_count'],
				'distinct' => (int) $r['distinct_count'],
			);
		}
		$count      = $this->db->select( 'SELECT COUNT(*) AS c FROM ' . $this->db->table( 'nf_pivot' ) . ' WHERE pivot_id = ?', array( $pivotId ) );
		$stats['*'] = array(
			'rows'     => (int) ( $count[0]['c'] ?? 0 ),
			'distinct' => 0,
		);

		$rowSlots = array();
		$series   = $this->db->select(
			'SELECT s.slot, s.repeater_id, f.field_id, f.field_name, f.data_table, p.field_name AS series_name FROM ' . $this->db->table( 'nf_pivot_slots' ) . ' s JOIN ' . $this->db->table( 'nf_fields' ) .
			' f ON f.field_id = s.field_id JOIN ' . $this->db->table( 'nf_fields' ) . ' p ON p.field_id = s.repeater_id WHERE s.pivot_id = ? AND s.repeater_id > 0 ORDER BY s.repeater_id, s.slot',
			array( $pivotId )
		);
		foreach ( $series as $r ) {
			$sn = (string) $r['series_name'];
			if ( ! isset( $rowSlots[ $sn ] ) ) {
				$rowSlots[ $sn ] = array(
					'id'    => (int) $r['repeater_id'],
					'slots' => array(),
				);
			}
			$rowSlots[ $sn ]['slots'][ (string) $r['slot'] ] = array(
				'name'  => (string) $r['field_name'],
				'table' => DataTable::from( (string) $r['data_table'] ),
				'id'    => (int) $r['field_id'],
			);
		}

		return array(
			'pivot'    => $pivotId,
			'slots'    => array_diff_key( $this->pivot->slots( $pivotId ), array_flip( $this->pivot->pendingSlots( $pivotId ) ) ),
			'fields'   => $fields,
			'rowSlots' => $rowSlots,
			'stats'    => $stats,
		);
	}
}
