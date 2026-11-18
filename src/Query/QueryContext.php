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
		$rows   = $this->db->select( 'SELECT field_id, field_key, field_name, parent_id, data_table, multi, row_count, distinct_count FROM ' . $this->db->table( 'nf_fields' ) . ' WHERE parent_id = 0 ORDER BY field_id' );
		foreach ( $rows as $r ) {
			$name = (string) $r['field_name'];
			if ( isset( $fields[ $name ] ) ) {
				continue;
			}
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
			'slots'    => $this->pivot->slots( $pivotId ),
			'fields'   => $fields,
			'rowSlots' => $rowSlots,
			'stats'    => $stats,
		);
	}
}
