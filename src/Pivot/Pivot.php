<?php
/**
 * Minimal object pivot: sets, slots, write-through sync and rebuild.
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
 * One flat nf_pivot row per object (design: "Pivot and lookup tables"). Spike scope: object pivot only,
 * single-valued int / dec / date fields, no dictionary, bitmasks or rollups. The pivot is derived: the typed
 * tables stay the source of truth and rebuild() recreates every row.
 */
final class Pivot {

	/**
	 * Slot column prefix per data table (spike types only).
	 */
	private const SLOT_PREFIX = array(
		'int'  => 'i',
		'dec'  => 'n',
		'date' => 't',
	);

	/**
	 * Core columns copied from the object.
	 */
	public const CORE = array( 'status', 'author_id', 'parent_id', 'published', 'modified', 'menu_order' );

	/**
	 * Constructor.
	 *
	 * @param BackendInterface $db     Backend.
	 * @param FieldMap         $fields Field map.
	 * @param Store            $store  Store.
	 */
	public function __construct( private BackendInterface $db, private FieldMap $fields, private Store $store ) {}

	/**
	 * Pivot set id for an object type and subtype, created when missing.
	 *
	 * @param ObjectType $type    Object type.
	 * @param string     $subtype Post type, taxonomy, etc.
	 * @return int
	 */
	public function setId( ObjectType $type, string $subtype ): int {
		$found = $this->findSetId( $type, $subtype );
		if ( null !== $found ) {
			return $found;
		}
		$t = $this->db->table( 'nf_pivot_sets' );
		$this->db->execute( "INSERT INTO {$t} (object_type, subtype) VALUES (?, ?)", array( $type->value, $subtype ) );
		return $this->db->insertId();
	}

	/**
	 * Pivot set id, or null when there is none. Read-only: query paths use this so a read never writes.
	 *
	 * @param ObjectType $type    Object type.
	 * @param string     $subtype Post type, taxonomy, etc.
	 * @return int|null
	 */
	public function findSetId( ObjectType $type, string $subtype ): ?int {
		$rows = $this->db->select( 'SELECT pivot_id FROM ' . $this->db->table( 'nf_pivot_sets' ) . ' WHERE object_type = ? AND subtype = ?', array( $type->value, $subtype ) );
		return $rows ? (int) $rows[0]['pivot_id'] : null;
	}

	/**
	 * Put a single-valued field into a slot ("i1", "n2", "t1"...). The slot letter must match the field's data table.
	 *
	 * @param int    $pivotId  Pivot set id.
	 * @param string $fieldKey Field key.
	 * @param string $slot     Slot name.
	 * @return void
	 * @throws \InvalidArgumentException When the field or slot is not valid for the pivot.
	 */
	public function assignSlot( int $pivotId, string $fieldKey, string $slot ): void {
		$def = $this->fields->byKey( $fieldKey );
		if ( null === $def || $def->multi || 0 !== $def->parentId ) {
			throw new \InvalidArgumentException( 'Only registered top-level single-valued fields can have a slot.' );
		}
		$letter = self::SLOT_PREFIX[ $def->table->value ] ?? null;
		if ( null === $letter || 1 !== preg_match( '/^' . $letter . '[1-9]$/', $slot ) || ! $this->slotColumnExists( $slot ) ) {
			throw new \InvalidArgumentException( 'Slot does not exist or does not match the field type.' );
		}
		$this->db->execute(
			$this->db->dialect()->upsert( $this->db->table( 'nf_pivot_slots' ), array( 'pivot_id', 'repeater_id', 'slot' ), array( 'field_id' ) ),
			array( $pivotId, 0, $slot, $def->id )
		);
	}

	/**
	 * Slots of a set: slot name => field (key, name, table).
	 *
	 * @param int $pivotId Pivot set id.
	 * @return array<string,array{id:int,key:string,name:string,table:DataTable}>
	 */
	public function slots( int $pivotId ): array {
		$rows = $this->db->select(
			'SELECT s.slot, f.field_id, f.field_key, f.field_name, f.data_table FROM ' . $this->db->table( 'nf_pivot_slots' ) . ' s JOIN ' . $this->db->table( 'nf_fields' ) .
			' f ON f.field_id = s.field_id WHERE s.pivot_id = ? AND s.repeater_id = 0 ORDER BY s.slot',
			array( $pivotId )
		);
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (string) $r['slot'] ] = array(
				'id'    => (int) $r['field_id'],
				'key'   => (string) $r['field_key'],
				'name'  => (string) $r['field_name'],
				'table' => DataTable::from( (string) $r['data_table'] ),
			);
		}
		return $out;
	}

	/**
	 * Write-through: rewrite one object's pivot row from the typed tables and bump the set's write_version, in one transaction.
	 *
	 * @param int                 $pivotId  Pivot set id.
	 * @param int                 $objectId Object id.
	 * @param array<string,mixed> $core     status (int), author_id, parent_id, published, modified (Y-m-d H:i:s or null), menu_order.
	 * @return void
	 */
	public function sync( int $pivotId, int $objectId, array $core ): void {
		$this->db->transaction(
			function () use ( $pivotId, $objectId, $core ): void {
				$d      = $this->db->dialect();
				$t      = $this->db->table( 'nf_pivot' );
				$cols   = array();
				$values = array( $pivotId, $objectId );
				$nulls  = array();
				foreach ( self::CORE as $c ) {
					$val = $core[ $c ] ?? ( in_array( $c, array( 'published', 'modified' ), true ) ? null : 0 );
					if ( null === $val ) {
						$nulls[] = $c;
						continue;
					}
					$cols[]   = $c;
					$values[] = $val;
				}
				foreach ( $this->slots( $pivotId ) as $slot => $field ) {
					$v = $this->store->get( ObjectType::Post, $objectId, $field['key'] );
					if ( null === $v ) {
						$nulls[] = $slot;
						continue;
					}
					$cols[]   = $slot;
					$values[] = DataTable::Int === $field['table'] ? (int) $v : (string) $v;
				}
				$this->db->execute( $d->upsert( $t, array( 'pivot_id', 'object_id' ), $cols ), $values );
				// The adapter never binds NULL, so unset columns are cleared with a literal (columns come from the contract).
				if ( $nulls ) {
					$set = implode( ', ', array_map( fn( string $c ): string => $d->quoteIdentifier( $c ) . ' = NULL', $nulls ) );
					$this->db->execute( "UPDATE {$t} SET {$set} WHERE pivot_id = ? AND object_id = ?", array( $pivotId, $objectId ) );
				}
				$this->bump( $pivotId );
			}
		);
	}

	/**
	 * Remove an object from the pivot.
	 *
	 * @param int $pivotId  Pivot set id.
	 * @param int $objectId Object id.
	 * @return void
	 */
	public function remove( int $pivotId, int $objectId ): void {
		$this->db->transaction(
			function () use ( $pivotId, $objectId ): void {
				$this->db->execute( 'DELETE FROM ' . $this->db->table( 'nf_pivot' ) . ' WHERE pivot_id = ? AND object_id = ?', array( $pivotId, $objectId ) );
				$this->bump( $pivotId );
			}
		);
	}

	/**
	 * Rewrite the slot columns of every row in the set from the typed tables (core columns are kept).
	 * Sync bugs cost a rebuild, never data.
	 *
	 * @param int $pivotId Pivot set id.
	 * @return int Rows rebuilt.
	 */
	public function rebuild( int $pivotId ): int {
		$t    = $this->db->table( 'nf_pivot' );
		$ids  = array_map( 'intval', array_column( $this->db->select( "SELECT object_id FROM {$t} WHERE pivot_id = ? ORDER BY object_id", array( $pivotId ) ), 'object_id' ) );
		$core = array();
		foreach ( $this->db->select( "SELECT * FROM {$t} WHERE pivot_id = ?", array( $pivotId ) ) as $r ) {
			$core[ (int) $r['object_id'] ] = array_intersect_key( $r, array_flip( self::CORE ) );
		}
		foreach ( $ids as $id ) {
			$this->sync( $pivotId, $id, $core[ $id ] );
		}
		return count( $ids );
	}

	/**
	 * Current write_version of a set (read with the page of IDs so page and total agree).
	 *
	 * @param int $pivotId Pivot set id.
	 * @return int
	 */
	public function writeVersion( int $pivotId ): int {
		$rows = $this->db->select( 'SELECT write_version FROM ' . $this->db->table( 'nf_pivot_sets' ) . ' WHERE pivot_id = ?', array( $pivotId ) );
		return (int) ( $rows[0]['write_version'] ?? 0 );
	}

	/**
	 * Bump write_version.
	 *
	 * @param int $pivotId Pivot set id.
	 * @return void
	 */
	private function bump( int $pivotId ): void {
		$this->db->execute( 'UPDATE ' . $this->db->table( 'nf_pivot_sets' ) . ' SET write_version = write_version + 1 WHERE pivot_id = ?', array( $pivotId ) );
	}

	/**
	 * Whether the nf_pivot table has this slot column (i1-i8, n1-n4, t1-t6).
	 *
	 * @param string $slot Slot name.
	 * @return bool
	 */
	private function slotColumnExists( string $slot ): bool {
		$max = array(
			'i' => 8,
			'n' => 4,
			't' => 6,
		);
		return (int) substr( $slot, 1 ) <= ( $max[ $slot[0] ] ?? 0 );
	}
}
