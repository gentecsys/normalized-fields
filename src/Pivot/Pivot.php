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
use NF\Storage\Status;
use NF\Storage\Store;

defined( 'ABSPATH' ) || exit;

/**
 * One flat nf_pivot row per object (design: "Pivot and lookup tables"). Object pivot only,
 * single-valued int / dec / date / str fields (str through the dictionary); bitmasks cover multi-valued str fields; Series rows go to RowPivot with min / max / count rollups. The pivot is derived: the typed
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
		'str'  => 's',
	);

	/**
	 * Bitmask slot letter (multi-valued str fields).
	 */
	private const MASK_LETTER = 'm';

	/**
	 * Option prefix of the per-set flag saying the count rollup was built.
	 */
	private const COUNTS_READY = 'nf_counts_ready_';

	/**
	 * Core columns copied from the object.
	 */
	public const CORE = array( 'status', 'author_id', 'parent_id', 'published', 'modified', 'menu_order' );

	/**
	 * Row pivot.
	 *
	 * @var RowPivot
	 */
	private RowPivot $rows;

	/**
	 * Count rollup, created on first use.
	 *
	 * @var Counts|null
	 */
	private ?Counts $counts = null;

	/**
	 * Constructor.
	 *
	 * @param BackendInterface $db     Backend.
	 * @param FieldMap         $fields Field map.
	 * @param Store            $store  Store.
	 * @param Dictionary|null  $dictionary Dictionary for s-slots; defaults to one on the same backend.
	 */
	public function __construct( private BackendInterface $db, private FieldMap $fields, private Store $store, private ?Dictionary $dictionary = null ) {
		$this->dictionary ??= new Dictionary( $db );
		$this->rows         = new RowPivot( $db, $fields, $store, $this->dictionary );
	}

	/**
	 * Count rollup (nf_count) service.
	 *
	 * @return Counts
	 */
	public function counts(): Counts {
		return $this->counts ??= new Counts( $this->db );
	}

	/**
	 * Recompute the set's count rollup from its pivot rows.
	 *
	 * @param int $pivotId Pivot set id.
	 * @return void
	 */
	public function rebuildCounts( int $pivotId ): void {
		$this->counts()->rebuild( $pivotId, $this->counts()->dimensions( array_keys( $this->slots( $pivotId ) ) ) );
		update_option( self::COUNTS_READY . $pivotId, 1, false );
	}

	/**
	 * Whether the rollup of a set was built, so exact totals may be read from it.
	 * Reads nothing from NF tables when the installed schema is behind.
	 *
	 * @param int $pivotId Pivot set id.
	 * @return bool
	 */
	public function countsReady( int $pivotId ): bool {
		return \NF\Install\Upgrader::isCurrent() && (bool) get_option( self::COUNTS_READY . $pivotId, false );
	}

	/**
	 * Pivot row of one object (status, published and the dimension slots), or null.
	 *
	 * @param int      $pivotId  Pivot set id.
	 * @param int      $objectId Object id.
	 * @param string[] $dims     Dimension slots.
	 * @return array<string,mixed>|null
	 */
	private function countRow( int $pivotId, int $objectId, array $dims ): ?array {
		$d    = $this->db->dialect();
		$cols = implode( ', ', array_map( array( $d, 'quoteIdentifier' ), array_merge( array( 'status', 'published' ), $dims ) ) );
		$rows = $this->db->select( "SELECT {$cols} FROM " . $this->db->table( 'nf_pivot' ) . ' WHERE pivot_id = ? AND object_id = ?', array( $pivotId, $objectId ) );
		return $rows[0] ?? null;
	}

	/**
	 * Row pivot and rollups (Series rows).
	 *
	 * @return RowPivot
	 */
	public function rows(): RowPivot {
		return $this->rows;
	}

	/**
	 * The dictionary used for s-slots.
	 *
	 * @return Dictionary
	 */
	public function dictionary(): Dictionary {
		return $this->dictionary;
	}

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
	 * Object type of a set (posts when the set is unknown).
	 *
	 * @param int $pivotId Pivot set id.
	 * @return ObjectType
	 */
	public function setType( int $pivotId ): ObjectType {
		$set = $this->db->select( 'SELECT object_type FROM ' . $this->db->table( 'nf_pivot_sets' ) . ' WHERE pivot_id = ?', array( $pivotId ) );
		return $set ? ( ObjectType::tryFrom( (int) $set[0]['object_type'] ) ?? ObjectType::Post ) : ObjectType::Post;
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
		if ( null === $def || 0 !== $def->parentId ) {
			throw new \InvalidArgumentException( 'Only registered top-level fields can have a slot.' );
		}
		if ( $def->multi ) {
			// Multi-valued str fields (checkbox, multi-select) take a bitmask slot.
			if ( DataTable::Str !== $def->table ) {
				throw new \InvalidArgumentException( 'Only multi-valued string fields can have a bitmask slot.' );
			}
			$letter = self::MASK_LETTER;
		} else {
			$letter = self::SLOT_PREFIX[ $def->table->value ] ?? null;
		}
		if ( null === $letter || 1 !== preg_match( '/^' . $letter . '[1-9]$/', $slot ) || ! $this->slotColumnExists( $slot ) ) {
			throw new \InvalidArgumentException( 'Slot does not exist or does not match the field type.' );
		}
		$this->db->execute(
			$this->db->dialect()->upsert( $this->db->table( 'nf_pivot_slots' ), array( 'pivot_id', 'repeater_id', 'slot' ), array( 'field_id', 'rollup' ) ),
			array( $pivotId, 0, $slot, $def->id, 'none' )
		);
	}

	/**
	 * Slots of a set: slot name => field (key, name, table).
	 *
	 * @param int $pivotId Pivot set id.
	 * @return array<string,array{id:int,key:string,name:string,table:DataTable,rollup:string,parent:string}>
	 */
	public function slots( int $pivotId ): array {
		$rows = $this->db->select(
			'SELECT s.slot, s.rollup, f.field_id, f.field_key, f.field_name, f.data_table, p.field_key AS parent_key FROM ' . $this->db->table( 'nf_pivot_slots' ) . ' s JOIN ' . $this->db->table( 'nf_fields' ) .
			' f ON f.field_id = s.field_id LEFT JOIN ' . $this->db->table( 'nf_fields' ) . ' p ON p.field_id = f.parent_id WHERE s.pivot_id = ? AND s.repeater_id = 0 ORDER BY s.slot',
			array( $pivotId )
		);
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (string) $r['slot'] ] = array(
				'id'     => (int) $r['field_id'],
				'key'    => (string) $r['field_key'],
				'name'   => (string) $r['field_name'],
				'table'  => DataTable::from( (string) $r['data_table'] ),
				'rollup' => (string) $r['rollup'],
				'parent' => (string) ( $r['parent_key'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Slots assigned but not yet rebuilt for existing objects. Queries ignore them (probe fallback) until the rebuild finishes;
	 * write-through still fills them. The list lives in the per-site option `nf_pivot_pending_<id>` (not autoloaded).
	 *
	 * @param int $pivotId Pivot set id.
	 * @return string[]
	 */
	public function pendingSlots( int $pivotId ): array {
		$list = get_option( 'nf_pivot_pending_' . $pivotId, array() );
		return is_array( $list ) ? array_values( array_filter( array_map( 'strval', $list ) ) ) : array();
	}

	/**
	 * Add slots to the pending list (they stay hidden from queries until clearPending()).
	 *
	 * @param int      $pivotId Pivot set id.
	 * @param string[] $slots   Slot names.
	 * @return void
	 */
	public function markPending( int $pivotId, array $slots ): void {
		$all = array_values( array_unique( array_merge( $this->pendingSlots( $pivotId ), $slots ) ) );
		update_option( 'nf_pivot_pending_' . $pivotId, $all, false );
	}

	/**
	 * Clear pending slots: they were rebuilt. Pass the snapshot taken when the rebuild started, so slots assigned while it ran stay pending.
	 *
	 * @param int           $pivotId Pivot set id.
	 * @param string[]|null $slots   Slots to clear; null clears all.
	 * @return void
	 */
	public function clearPending( int $pivotId, ?array $slots = null ): void {
		$left = null === $slots ? array() : array_values( array_diff( $this->pendingSlots( $pivotId ), $slots ) );
		if ( array() === $left ) {
			delete_option( 'nf_pivot_pending_' . $pivotId );
			return;
		}
		update_option( 'nf_pivot_pending_' . $pivotId, $left, false );
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
		$type = $this->setType( $pivotId );
		$this->db->transaction(
			function () use ( $pivotId, $objectId, $core, $type ): void {
				$d      = $this->db->dialect();
				$t      = $this->db->table( 'nf_pivot' );
				$slots  = $this->slots( $pivotId );
				$dims   = $this->counts()->dimensions( array_keys( $slots ) );
				$old    = $this->countRow( $pivotId, $objectId, $dims );
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
				foreach ( $slots as $slot => $field ) {
					if ( 'none' !== $field['rollup'] ) {
						$v = $this->rows->rollup( $objectId, $field['parent'], $field['name'], $field['rollup'], $type );
						if ( null === $v ) {
							$nulls[] = $slot;
							continue;
						}
						$cols[]   = $slot;
						$values[] = is_int( $v ) ? $v : (string) $v;
						continue;
					}
					$v = $this->store->get( $type, $objectId, $field['key'] );
					if ( self::MASK_LETTER === $slot[0] ) {
						// Bitmask: OR of the value bits, NOT NULL (0 when empty); sent unsigned so bit 63 survives.
						$cols[]   = $slot;
						$values[] = $this->mask( $field['id'], is_array( $v ) ? $v : array() );
						continue;
					}
					if ( null === $v ) {
						$nulls[] = $slot;
						continue;
					}
					if ( DataTable::Str === $field['table'] ) {
						// Dictionary id; a value longer than the dictionary column has no id, so its slot stays NULL.
						$v = Dictionary::fits( (string) $v ) ? $this->dictionary->id( $field['id'], (string) $v ) : null;
						if ( null === $v ) {
							$nulls[] = $slot;
							continue;
						}
					}
					$cols[]   = $slot;
					$values[] = DataTable::Int === $field['table'] || DataTable::Str === $field['table'] ? (int) $v : (string) $v;
				}
				$this->db->execute( $d->upsert( $t, array( 'pivot_id', 'object_id' ), $cols ), $values );
				// The adapter never binds NULL, so unset columns are cleared with a literal (columns come from the contract).
				if ( $nulls ) {
					$set = implode( ', ', array_map( fn( string $c ): string => $d->quoteIdentifier( $c ) . ' = NULL', $nulls ) );
					$this->db->execute( "UPDATE {$t} SET {$set} WHERE pivot_id = ? AND object_id = ?", array( $pivotId, $objectId ) );
				}
				$this->counts()->apply( $pivotId, $old, $this->countRow( $pivotId, $objectId, $dims ), $dims );
				$this->rows->syncRows( $pivotId, $objectId, $type );
				$this->bump( $pivotId );
			}
		);
	}

	/**
	 * Bitmask of values as an unsigned decimal string. Values with no bit (too long, or the field has more than 64) are left out.
	 *
	 * @param int      $fieldId Field id.
	 * @param string[] $values  Values.
	 * @return string
	 */
	private function mask( int $fieldId, array $values ): string {
		$mask = 0;
		foreach ( $values as $value ) {
			$bit = $this->dictionary->bit( $fieldId, (string) $value );
			if ( null !== $bit ) {
				$mask |= 1 << $bit;
			}
		}
		return sprintf( '%u', $mask );
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
				$dims = $this->counts()->dimensions( array_keys( $this->slots( $pivotId ) ) );
				$this->counts()->apply( $pivotId, $this->countRow( $pivotId, $objectId, $dims ), null, $dims );
				$this->db->execute( 'DELETE FROM ' . $this->db->table( 'nf_pivot' ) . ' WHERE pivot_id = ? AND object_id = ?', array( $pivotId, $objectId ) );
				$this->rows->removeRows( $pivotId, $objectId );
				$this->bump( $pivotId );
			}
		);
	}

	/**
	 * Default number of objects rebuilt per chunk.
	 */
	public const REBUILD_CHUNK = 500;

	/**
	 * Rewrite the slot columns of every row in the set from the typed tables (core columns are kept), in chunks of
	 * object ids so memory stays flat on big sets, then drop row-pivot rows of Series that no longer have row slots.
	 * Sync bugs cost a rebuild, never data.
	 *
	 * @param int           $pivotId  Pivot set id.
	 * @param int           $chunk    Objects per chunk.
	 * @param callable|null $progress Called with (rebuilt so far) after each chunk.
	 * @return int Rows rebuilt.
	 */
	public function rebuild( int $pivotId, int $chunk = self::REBUILD_CHUNK, ?callable $progress = null ): int {
		$total = 0;
		$after = 0;
		do {
			$res    = $this->rebuildChunk( $pivotId, $after, $chunk );
			$after  = $res['last'];
			$total += $res['count'];
			if ( $res['count'] > 0 && null !== $progress ) {
				$progress( $total );
			}
		} while ( $res['count'] >= max( 1, $chunk ) );
		$this->pruneRowPivot( $pivotId );
		$this->rebuildCounts( $pivotId );
		return $total;
	}

	/**
	 * Rebuild one chunk: the next $limit objects of the set with an id above $afterObjectId, in id order.
	 * A resumable job stores "last" and calls again until "count" is below the limit.
	 *
	 * @param int $pivotId       Pivot set id.
	 * @param int $afterObjectId Last object id already rebuilt (0 to start).
	 * @param int $limit         Objects in the chunk.
	 * @return array{count:int,last:int}
	 */
	public function rebuildChunk( int $pivotId, int $afterObjectId, int $limit = self::REBUILD_CHUNK ): array {
		$limit = max( 1, $limit );
		$t     = $this->db->table( 'nf_pivot' );
		$rows  = $this->db->select( "SELECT * FROM {$t} WHERE pivot_id = ? AND object_id > ? ORDER BY object_id LIMIT {$limit}", array( $pivotId, $afterObjectId ) );
		$last  = $afterObjectId;
		foreach ( $rows as $r ) {
			$last = (int) $r['object_id'];
			$this->sync( $pivotId, $last, array_intersect_key( $r, array_flip( self::CORE ) ) );
		}
		return array(
			'count' => count( $rows ),
			'last'  => $last,
		);
	}

	/**
	 * Rebuild a set from WordPress core plus the typed tables: every object of the set's type and subtype gets its
	 * base row, rows of objects that no longer exist (or are of another type) are removed, slots and row pivot are refilled.
	 *
	 * @param int           $pivotId  Pivot set id.
	 * @param int           $chunk    Objects per chunk.
	 * @param callable|null $progress Called with (objects so far) after each chunk.
	 * @return int Objects rebuilt.
	 */
	public function rebuildFromCore( int $pivotId, int $chunk = self::REBUILD_CHUNK, ?callable $progress = null ): int {
		$total    = 0;
		$after    = 0;
		$snapshot = $this->pendingSlots( $pivotId );
		do {
			$res    = $this->rebuildFromCoreChunk( $pivotId, $after, $chunk );
			$after  = $res['last'];
			$total += $res['count'];
			if ( $res['count'] > 0 && null !== $progress ) {
				$progress( $total );
			}
		} while ( ! $res['done'] );
		$this->pruneRowPivot( $pivotId );
		$this->rebuildCounts( $pivotId );
		$this->clearPending( $pivotId, $snapshot );
		return $total;
	}

	/**
	 * One chunk of rebuildFromCore(): the next $limit core objects of the set above $afterObjectId, in id order (keyset over
	 * core IDs through $wpdb; no join between core and NF tables). Orphan pivot rows inside the covered id range are
	 * removed; the last chunk also covers everything above the last core id.
	 *
	 * @param int $pivotId       Pivot set id.
	 * @param int $afterObjectId Last object id already handled (0 to start).
	 * @param int $limit         Objects in the chunk.
	 * @return array{count:int,last:int,done:bool,pruned:int}
	 */
	public function rebuildFromCoreChunk( int $pivotId, int $afterObjectId, int $limit = self::REBUILD_CHUNK ): array {
		$limit = max( 1, $limit );
		$set   = $this->db->select( 'SELECT object_type, subtype FROM ' . $this->db->table( 'nf_pivot_sets' ) . ' WHERE pivot_id = ?', array( $pivotId ) );
		if ( ! $set || ! in_array( (int) $set[0]['object_type'], array( ObjectType::Post->value, ObjectType::Term->value, ObjectType::User->value ), true ) ) {
			return array(
				'count'  => 0,
				'last'   => $afterObjectId,
				'done'   => true,
				'pruned' => 0,
			);
		}
		$objects = $this->coreObjects( ObjectType::from( (int) $set[0]['object_type'] ), (string) $set[0]['subtype'], $afterObjectId, $limit );
		$done    = count( $objects ) < $limit;
		$last    = $objects ? (int) array_key_last( $objects ) : $afterObjectId;
		foreach ( $objects as $id => $core ) {
			$this->sync( $pivotId, (int) $id, $core );
		}
		$t      = $this->db->table( 'nf_pivot' );
		$sql    = "SELECT object_id FROM {$t} WHERE pivot_id = ? AND object_id > ?";
		$params = array( $pivotId, $afterObjectId );
		if ( ! $done ) {
			$sql     .= ' AND object_id <= ?';
			$params[] = $last;
		}
		$pruned = 0;
		foreach ( $this->db->select( $sql, $params ) as $r ) {
			$id = (int) $r['object_id'];
			if ( ! isset( $objects[ $id ] ) ) {
				$this->remove( $pivotId, $id );
				++$pruned;
			}
		}
		return array(
			'count'  => count( $objects ),
			'last'   => $last,
			'done'   => $done,
			'pruned' => $pruned,
		);
	}

	/**
	 * Core columns of the next objects of a set, keyed by object id in id order.
	 *
	 * @param ObjectType $type    Object type.
	 * @param string     $subtype Post type or taxonomy.
	 * @param int        $after   Last id handled.
	 * @param int        $limit   Maximum objects.
	 * @return array<int,array<string,mixed>>
	 */
	private function coreObjects( ObjectType $type, string $subtype, int $after, int $limit ): array {
		global $wpdb;
		$out = array();
		if ( ObjectType::Post === $type ) {
			$sql  = $wpdb->prepare( "SELECT ID, post_status, post_author, post_parent, post_date, post_modified, menu_order FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID LIMIT %d", $subtype, $after, $limit );
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $rows as $r ) {
				$status                = Status::fromName( (string) $r['post_status'] );
				$out[ (int) $r['ID'] ] = array(
					'status'     => null !== $status ? $status->value : 100,
					'author_id'  => (int) $r['post_author'],
					'parent_id'  => (int) $r['post_parent'],
					'published'  => $this->date( (string) $r['post_date'] ),
					'modified'   => $this->date( (string) $r['post_modified'] ),
					'menu_order' => (int) $r['menu_order'],
				);
			}
		}
		if ( ObjectType::Term === $type ) {
			// Terms: published in the pivot status; parent from term_taxonomy; no author, dates or menu order.
			$sql  = $wpdb->prepare( "SELECT term_id, parent FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND term_id > %d ORDER BY term_id LIMIT %d", $subtype, $after, $limit );
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $rows as $r ) {
				$out[ (int) $r['term_id'] ] = array(
					'status'    => Status::Publish->value,
					'parent_id' => (int) $r['parent'],
				);
			}
		} elseif ( ObjectType::User === $type ) {
			// Users: one set for all users (the subtype is only a label); registration date goes to published.
			$sql  = $wpdb->prepare( "SELECT ID, user_registered FROM {$wpdb->users} WHERE ID > %d ORDER BY ID LIMIT %d", $after, $limit );
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $rows as $r ) {
				$out[ (int) $r['ID'] ] = array(
					'status'    => Status::Publish->value,
					'published' => $this->date( (string) $r['user_registered'] ),
				);
			}
		}
		return $out;
	}

	/**
	 * A core date, or null for the zero date.
	 *
	 * @param string $date Date string.
	 * @return string|null
	 */
	private function date( string $date ): ?string {
		return '' === $date || str_starts_with( $date, '0000-00-00' ) ? null : $date;
	}

	/**
	 * Delete row-pivot rows of Series that have no row slot in the set any more. Returns rows deleted.
	 *
	 * @param int $pivotId Pivot set id.
	 * @return int
	 */
	public function pruneRowPivot( int $pivotId ): int {
		return $this->db->execute(
			'DELETE FROM ' . $this->db->table( 'nf_row_pivot' ) . ' WHERE pivot_id = ? AND field_id NOT IN (SELECT repeater_id FROM ' . $this->db->table( 'nf_pivot_slots' ) . ' WHERE pivot_id = ? AND repeater_id > 0)',
			array( $pivotId, $pivotId )
		);
	}

	/**
	 * Delete dictionary entries no string slot refers to. Entries with a bitmask bit are kept (bits are never reused
	 * and a mask in any slot may still carry them). A deleted id is not reused while the server runs (MySQL 5.7 may reuse the highest ids after a restart; nothing references pruned ids).
	 * Run it after a rebuild and not during heavy writes: a value written between the scan and the delete loses its
	 * entry, and its slot reads as a miss until the next rebuild.
	 *
	 * @return int Entries deleted.
	 */
	public function pruneDictionary(): int {
		$used = array();
		foreach ( $this->db->select( 'SELECT DISTINCT pivot_id, repeater_id, slot, field_id FROM ' . $this->db->table( 'nf_pivot_slots' ) . " WHERE slot LIKE 's%'" ) as $s ) {
			$col = (string) $s['slot'];
			if ( 1 !== preg_match( '/^s[1-9]$/', $col ) ) {
				continue;
			}
			$table = $this->db->table( (int) $s['repeater_id'] > 0 ? 'nf_row_pivot' : 'nf_pivot' );
			foreach ( $this->db->select( "SELECT DISTINCT {$col} AS d FROM {$table} WHERE pivot_id = ? AND {$col} IS NOT NULL", array( (int) $s['pivot_id'] ) ) as $r ) {
				$used[ (int) $r['d'] ] = true;
			}
		}
		$dict    = $this->db->table( 'nf_dict' );
		$deleted = 0;
		$stale   = array();
		foreach ( $this->db->select( "SELECT dict_id FROM {$dict} WHERE bit IS NULL" ) as $r ) {
			if ( ! isset( $used[ (int) $r['dict_id'] ] ) ) {
				$stale[] = (int) $r['dict_id'];
			}
		}
		foreach ( array_chunk( $stale, 500 ) as $ids ) {
			$deleted += $this->db->execute( "DELETE FROM {$dict} WHERE bit IS NULL AND dict_id IN (" . implode( ',', array_fill( 0, count( $ids ), '?' ) ) . ')', $ids );
		}
		return $deleted;
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
		/**
		 * Fires after a pivot set's write_version is bumped (inside the write's transaction).
		 *
		 * @param int $pivotId Pivot set id.
		 */
		do_action( 'nf/pivot/changed', $pivotId ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	/**
	 * Whether the nf_pivot table has this slot column (i1-i8, n1-n4, t1-t6, s1-s8).
	 *
	 * @param string $slot Slot name.
	 * @return bool
	 */
	private function slotColumnExists( string $slot ): bool {
		$max = array(
			'i' => 8,
			'n' => 4,
			't' => 6,
			's' => 8,
			'm' => 4,
		);
		return (int) substr( $slot, 1 ) <= ( $max[ $slot[0] ] ?? 0 );
	}
}
