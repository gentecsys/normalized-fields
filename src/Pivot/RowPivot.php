<?php
/**
 * Row pivot and rollups for Series (repeater) rows.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Pivot;

use NF\Backend\BackendInterface;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

defined( 'ABSPATH' ) || exit;

/**
 * One nf_row_pivot row per Series row, plus min / max / count rollups of single-valued
 * sub-fields (design: "Pivot and lookup tables"). Derived data: the typed tables stay the
 * source of truth. Pivot::sync() calls syncRows() and rollups() in its own transaction.
 */
final class RowPivot {

	/**
	 * Rollup modes.
	 */
	public const MODES = array( 'min', 'max', 'count' );

	/**
	 * Slot letter per data table, and how many such slots nf_row_pivot has.
	 */
	private const ROW_SLOTS = array(
		'int'  => array( 'i', 4 ),
		'dec'  => array( 'n', 2 ),
		'date' => array( 't', 4 ),
		'str'  => array( 's', 4 ),
	);

	/**
	 * Constructor.
	 *
	 * @param BackendInterface $db         Backend.
	 * @param FieldMap         $fields     Field map.
	 * @param Store            $store      Store.
	 * @param Dictionary       $dictionary Dictionary for s-slots.
	 */
	public function __construct( private BackendInterface $db, private FieldMap $fields, private Store $store, private Dictionary $dictionary ) {}

	/**
	 * Put a single-valued Series sub-field into a row-pivot slot.
	 *
	 * @param int    $pivotId   Pivot set id.
	 * @param string $seriesKey Series field key.
	 * @param string $subKey    Sub-field key.
	 * @param string $slot      Slot ("i1", "t2", ...).
	 * @return void
	 * @throws \InvalidArgumentException When the fields or slot do not fit.
	 */
	public function assignSlot( int $pivotId, string $seriesKey, string $subKey, string $slot ): void {
		$series = $this->fields->byKey( $seriesKey );
		$sub    = $this->sub( $series, $subKey );
		$spec   = self::ROW_SLOTS[ $sub->table->value ] ?? null;
		if ( $sub->multi || null === $spec || 1 !== preg_match( '/^' . $spec[0] . '([1-9])$/', $slot, $m ) || (int) $m[1] > $spec[1] ) {
			throw new \InvalidArgumentException( 'Slot does not exist or does not match the sub-field type.' );
		}
		$this->saveSlot( $pivotId, $series->id, $slot, $sub->id, 'none' );
	}

	/**
	 * Roll a single-valued sub-field up into an object-pivot slot: min / max keep the sub-field's slot type, count uses an "i" slot.
	 *
	 * @param int    $pivotId   Pivot set id.
	 * @param string $seriesKey Series field key.
	 * @param string $subKey    Sub-field key.
	 * @param string $slot      Object-pivot slot.
	 * @param string $mode      min, max or count.
	 * @return void
	 * @throws \InvalidArgumentException When the fields, mode or slot do not fit.
	 */
	public function assignRollup( int $pivotId, string $seriesKey, string $subKey, string $slot, string $mode ): void {
		$series = $this->fields->byKey( $seriesKey );
		$sub    = $this->sub( $series, $subKey );
		if ( ! in_array( $mode, self::MODES, true ) || $sub->multi ) {
			throw new \InvalidArgumentException( 'Rollups need a single-valued sub-field and a mode of min, max or count.' );
		}
		if ( 'count' === $mode ) {
			$letter = 'i';
			$max    = 8;
		} else {
			// Strings have no meaningful order in a dictionary id, so min / max cover int, dec and date.
			$letter = array(
				'int'  => 'i',
				'dec'  => 'n',
				'date' => 't',
			)[ $sub->table->value ] ?? null;
			$max    = array(
				'i' => 8,
				'n' => 4,
				't' => 6,
			)[ $letter ] ?? 0;
		}
		if ( null === $letter || 1 !== preg_match( '/^' . $letter . '([1-9])$/', $slot, $m ) || (int) $m[1] > $max ) {
			throw new \InvalidArgumentException( 'Slot does not exist or does not match the rollup.' );
		}
		$this->saveSlot( $pivotId, 0, $slot, $sub->id, $mode );
	}

	/**
	 * Rewrite one object's row-pivot rows for every Series that has row slots in this set.
	 *
	 * @param int $pivotId  Pivot set id.
	 * @param int $objectId Object id.
	 * @return void
	 */
	public function syncRows( int $pivotId, int $objectId ): void {
		$d     = $this->db->dialect();
		$t     = $this->db->table( 'nf_row_pivot' );
		$byRep = array();
		foreach ( $this->rowSlots( $pivotId ) as $r ) {
			$sid = $r['series_id'];
			if ( ! isset( $byRep[ $sid ] ) ) {
				$byRep[ $sid ] = array(
					'key'   => $r['series_key'],
					'slots' => array(),
				);
			}
			$byRep[ $sid ]['slots'][ $r['slot'] ] = $r;
		}
		foreach ( $byRep as $seriesId => $series ) {
			$this->db->execute( "DELETE FROM {$t} WHERE pivot_id = ? AND object_id = ? AND field_id = ?", array( $pivotId, $objectId, $seriesId ) );
			$ids  = $this->rowIds( $objectId, $seriesId );
			$rows = $ids ? $this->store->getRows( ObjectType::Post, $objectId, $series['key'] ) : array();
			foreach ( $ids as $i => $rowId ) {
				$cols   = array( 'row_id', 'pivot_id', 'object_id', 'field_id' );
				$values = array( $rowId, $pivotId, $objectId, $seriesId );
				foreach ( $series['slots'] as $slot => $s ) {
					$v = $rows[ $i ][ $s['name'] ] ?? null;
					if ( DataTable::Str === $s['table'] && null !== $v ) {
						$v = Dictionary::fits( (string) $v ) ? $this->dictionary->id( $s['field_id'], (string) $v ) : null;
					}
					if ( null === $v ) {
						continue; // The adapter never binds NULL; omitted columns default to NULL.
					}
					$cols[]   = $slot;
					$values[] = DataTable::Int === $s['table'] || DataTable::Str === $s['table'] ? (int) $v : (string) $v;
				}
				$marks = implode( ', ', array_fill( 0, count( $cols ), '?' ) );
				$names = implode( ', ', array_map( fn( string $c ): string => $d->quoteIdentifier( $c ), $cols ) );
				$this->db->execute( "INSERT INTO {$t} ({$names}) VALUES ({$marks})", $values );
			}
		}
	}

	/**
	 * Rollup value of one object-pivot slot, from the live rows (null when no row has a value; count is 0 then).
	 *
	 * @param int    $objectId  Object id.
	 * @param string $seriesKey Series field key.
	 * @param string $subName   Sub-field name.
	 * @param string $mode      min, max or count.
	 * @return int|string|null
	 */
	public function rollup( int $objectId, string $seriesKey, string $subName, string $mode ): int|string|null {
		$best = null;
		$n    = 0;
		foreach ( $this->store->getRows( ObjectType::Post, $objectId, $seriesKey ) as $row ) {
			$v = $row[ $subName ] ?? null;
			if ( null === $v ) {
				continue;
			}
			++$n;
			if ( null === $best || ( 'min' === $mode && $this->cmp( $v, $best ) < 0 ) || ( 'max' === $mode && $this->cmp( $v, $best ) > 0 ) ) {
				$best = $v;
			}
		}
		return 'count' === $mode ? $n : $best;
	}

	/**
	 * Delete an object's row-pivot rows.
	 *
	 * @param int $pivotId  Pivot set id.
	 * @param int $objectId Object id.
	 * @return void
	 */
	public function removeRows( int $pivotId, int $objectId ): void {
		$this->db->execute( 'DELETE FROM ' . $this->db->table( 'nf_row_pivot' ) . ' WHERE pivot_id = ? AND object_id = ?', array( $pivotId, $objectId ) );
	}

	/**
	 * Compare two sub-field values: numbers numerically, date strings in their fixed format as strings.
	 *
	 * @param mixed $a A.
	 * @param mixed $b B.
	 * @return int
	 */
	private function cmp( mixed $a, mixed $b ): int {
		return is_numeric( $a ) && is_numeric( $b ) ? (float) $a <=> (float) $b : strcmp( (string) $a, (string) $b );
	}

	/**
	 * Resolve a sub-field of a Series.
	 *
	 * @param \NF\Storage\FieldDef|null $series Series definition.
	 * @param string                    $subKey Sub-field key.
	 * @return \NF\Storage\FieldDef
	 * @throws \InvalidArgumentException When it is not a sub-field of that Series.
	 */
	private function sub( ?\NF\Storage\FieldDef $series, string $subKey ): \NF\Storage\FieldDef {
		$sub = $this->fields->byKey( $subKey );
		if ( null === $series || 0 !== $series->parentId || null === $sub || $sub->parentId !== $series->id || ! $sub->table->hasValues() ) {
			throw new \InvalidArgumentException( 'Expected a registered Series and one of its value sub-fields.' );
		}
		return $sub;
	}

	/**
	 * Save a slot assignment.
	 *
	 * @param int    $pivotId    Pivot set id.
	 * @param int    $repeaterId Series field id, 0 for the object pivot.
	 * @param string $slot       Slot.
	 * @param int    $fieldId    Field id.
	 * @param string $mode       Rollup mode or "none".
	 * @return void
	 */
	private function saveSlot( int $pivotId, int $repeaterId, string $slot, int $fieldId, string $mode ): void {
		$this->db->execute(
			$this->db->dialect()->upsert( $this->db->table( 'nf_pivot_slots' ), array( 'pivot_id', 'repeater_id', 'slot' ), array( 'field_id', 'rollup' ) ),
			array( $pivotId, $repeaterId, $slot, $fieldId, $mode )
		);
	}

	/**
	 * Row-slot assignments of a set.
	 *
	 * @param int $pivotId Pivot set id.
	 * @return array<int,array{slot:string,field_id:int,name:string,table:DataTable,series_id:int,series_key:string}>
	 */
	private function rowSlots( int $pivotId ): array {
		$rows = $this->db->select(
			'SELECT s.slot, s.repeater_id, f.field_id, f.field_name, f.data_table, p.field_key AS series_key FROM ' . $this->db->table( 'nf_pivot_slots' ) . ' s JOIN ' .
			$this->db->table( 'nf_fields' ) . ' f ON f.field_id = s.field_id JOIN ' . $this->db->table( 'nf_fields' ) . ' p ON p.field_id = s.repeater_id WHERE s.pivot_id = ? AND s.repeater_id > 0 ORDER BY s.repeater_id, s.slot',
			array( $pivotId )
		);
		$out  = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'slot'       => (string) $r['slot'],
				'field_id'   => (int) $r['field_id'],
				'name'       => (string) $r['field_name'],
				'table'      => DataTable::from( (string) $r['data_table'] ),
				'series_id'  => (int) $r['repeater_id'],
				'series_key' => (string) $r['series_key'],
			);
		}
		return $out;
	}

	/**
	 * Top-level row ids of a Series in row order (the order Store::getRows returns).
	 *
	 * @param int $objectId Object id.
	 * @param int $seriesId Series field id.
	 * @return int[]
	 */
	private function rowIds( int $objectId, int $seriesId ): array {
		$rows = $this->db->select(
			'SELECT row_id FROM ' . $this->db->table( 'nf_rows' ) . ' WHERE object_type = ? AND object_id = ? AND field_id = ? AND parent_row_id = 0 ORDER BY row_order',
			array( ObjectType::Post->value, $objectId, $seriesId )
		);
		return array_map( 'intval', array_column( $rows, 'row_id' ) );
	}
}
