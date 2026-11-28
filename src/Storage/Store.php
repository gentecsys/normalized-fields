<?php
/**
 * Read/write store for scalar, multi-valued and Series fields.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Storage;

use NF\Backend\BackendInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Typed-table store (design: "Storage by field type"). Pivot sync is added by the pivot WPs.
 * Series rows are one level deep here; nested Series are a later WP.
 */
final class Store {

	/**
	 * Longest value kept whole in nf_str; longer values keep this prefix there and the full value in nf_text.
	 */
	private const STR_MAX = 191;

	/**
	 * Constructor.
	 *
	 * @param BackendInterface $db     Backend.
	 * @param FieldMap         $fields Field map.
	 * @param RowSorter|null   $sorter Series row sorter.
	 */
	public function __construct( private BackendInterface $db, private FieldMap $fields, private ?RowSorter $sorter = null ) {}

	/**
	 * Option listing the field IDs that have held an off-type value (see offTypeIds()).
	 */
	public const OFF_TYPE_OPTION = 'nf_offtype_fields';

	/**
	 * Memo of the option, keyed by field ID.
	 *
	 * @var array<int,true>|null
	 */
	private ?array $offType = null;

	/**
	 * Read a field. Scalar fields return the value or null; multi-valued fields return a list in order.
	 *
	 * @param ObjectType $type Object type.
	 * @param int        $id   Object ID.
	 * @param string     $key  Field key.
	 * @return mixed
	 */
	public function get( ObjectType $type, int $id, string $key ): mixed {
		$def = $this->valueField( $key );
		if ( DataTable::None === $def->table ) {
			return $this->readGroup( $def, $type, $id );
		}
		$out = $this->readValues( $def, $type, $id, 0 );
		return $def->multi ? $out : ( $out[0] ?? null );
	}

	/**
	 * Read one field for many objects: one query per typed table touched (plus one for long text and one for
	 * off-type values when present), whatever the number of objects.
	 *
	 * @param ObjectType $type Object type.
	 * @param int[]      $ids  Object IDs.
	 * @param string     $key  Field key.
	 * @return array<int,mixed> Value per object ID, shaped as get() returns it (null or an empty list when unset).
	 */
	public function getMany( ObjectType $type, array $ids, string $key ): array {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$def = $this->valueField( $key );
		if ( array() === $ids ) {
			return array();
		}
		return $this->readMany( $def, $type, $ids );
	}

	/**
	 * Batch read for a value or group-like field.
	 *
	 * @param FieldDef   $def  Field.
	 * @param ObjectType $type Object type.
	 * @param int[]      $ids  Object IDs.
	 * @return array<int,mixed>
	 */
	private function readMany( FieldDef $def, ObjectType $type, array $ids ): array {
		$out = array();
		if ( DataTable::None === $def->table ) {
			foreach ( $ids as $id ) {
				$out[ $id ] = array();
			}
			foreach ( $this->fields->children( $def->id ) as $child ) {
				foreach ( $this->readMany( $child, $type, $ids ) as $id => $value ) {
					$out[ $id ][ $child->name ] = $value;
				}
			}
			return $out;
		}
		$by = $this->readValuesMany( $def, $type, $ids );
		foreach ( $ids as $id ) {
			$vals       = $by[ $id ] ?? array();
			$out[ $id ] = $def->multi ? $vals : ( $vals[0] ?? null );
		}
		return $out;
	}

	/**
	 * Values per object for (field, row 0) in position order.
	 *
	 * @param FieldDef   $def  Field.
	 * @param ObjectType $type Object type.
	 * @param int[]      $ids  Object IDs.
	 * @return array<int,array<int,mixed>>
	 */
	private function readValuesMany( FieldDef $def, ObjectType $type, array $ids ): array {
		$marks = implode( ',', array_fill( 0, count( $ids ), '?' ) );
		$rows  = $this->db->select(
			'SELECT object_id, position, value FROM ' . $this->db->table( $def->table->table() ) . ' WHERE object_type = ? AND field_id = ? AND row_id = 0 AND object_id IN (' . $marks . ') ORDER BY object_id, position',
			array_merge( array( $type->value, $def->id ), $ids )
		);
		if ( DataTable::Str === $def->table ) {
			$rows = $this->withSpillMany( $def, $type, $rows );
		}
		$by = array();
		foreach ( $rows as $r ) {
			$by[ (int) $r['object_id'] ][] = $def->table->fromDb( $r['value'] );
		}
		if ( $this->hasOffType( $def ) ) {
			$missing = array_values( array_diff( $ids, array_keys( $by ) ) );
			if ( array() !== $missing ) {
				$marks = implode( ',', array_fill( 0, count( $missing ), '?' ) );
				$json  = $this->db->select(
					'SELECT object_id, value FROM ' . $this->db->table( 'nf_json' ) . ' WHERE object_type = ? AND field_id = ? AND row_id = 0 AND position = 0 AND object_id IN (' . $marks . ')',
					array_merge( array( $type->value, $def->id ), $missing )
				);
				foreach ( $json as $r ) {
					$by[ (int) $r['object_id'] ] = array( DataTable::Json->fromDb( $r['value'] ) );
				}
			}
		}
		return $by;
	}

	/**
	 * Replace 191-char prefixes with the full value from nf_text, for many objects at once.
	 *
	 * @param FieldDef                       $def  Field.
	 * @param ObjectType                     $type Object type.
	 * @param array<int,array<string,mixed>> $rows nf_str rows ordered by object and position.
	 * @return array<int,array<string,mixed>>
	 */
	private function withSpillMany( FieldDef $def, ObjectType $type, array $rows ): array {
		$long = array();
		foreach ( $rows as $r ) {
			if ( mb_strlen( (string) $r['value'] ) >= self::STR_MAX ) {
				$long[ (int) $r['object_id'] ] = true;
			}
		}
		if ( array() === $long ) {
			return $rows;
		}
		$need  = array_keys( $long );
		$marks = implode( ',', array_fill( 0, count( $need ), '?' ) );
		$full  = $this->db->select(
			'SELECT object_id, position, value FROM ' . $this->db->table( 'nf_text' ) . ' WHERE object_type = ? AND field_id = ? AND row_id = 0 AND object_id IN (' . $marks . ')',
			array_merge( array( $type->value, $def->id ), $need )
		);
		$by    = array();
		foreach ( $full as $f ) {
			$by[ (int) $f['object_id'] ][ (int) $f['position'] ] = $f['value'];
		}
		$index = array();
		foreach ( $rows as $i => $r ) {
			$oid = (int) $r['object_id'];
			$pos = $index[ $oid ] ?? 0;
			if ( isset( $by[ $oid ][ $pos ] ) ) {
				$rows[ $i ]['value'] = $by[ $oid ][ $pos ];
			}
			$index[ $oid ] = $pos + 1;
		}
		return $rows;
	}

	/**
	 * Write a field, replacing what was there. Null or an empty list removes it.
	 *
	 * @param ObjectType $type  Object type.
	 * @param int        $id    Object ID.
	 * @param string     $key   Field key.
	 * @param mixed      $value Scalar, or a list for multi-valued fields.
	 * @return void
	 */
	public function set( ObjectType $type, int $id, string $key, mixed $value ): void {
		$def = $this->valueField( $key );
		$this->db->transaction(
			function () use ( $def, $type, $id, $value ): void {
				if ( DataTable::None === $def->table ) {
					$this->writeGroup( $def, $type, $id, $value );
					return;
				}
				$this->writeValues( $def, $type, $id, 0, $value );
			}
		);
		$this->changed( $type, $id );
	}

	/**
	 * Delete a field's value (and rows, for a Series).
	 *
	 * @param ObjectType $type Object type.
	 * @param int        $id   Object ID.
	 * @param string     $key  Field key.
	 * @return void
	 */
	public function delete( ObjectType $type, int $id, string $key ): void {
		$def = $this->fields->byKey( $key );
		if ( null === $def ) {
			return;
		}
		$this->db->transaction(
			function () use ( $def, $type, $id ): void {
				if ( DataTable::Rows === $def->table ) {
					$this->deleteRows( $def, $type, $id );
				} elseif ( DataTable::None === $def->table ) {
					$this->writeGroup( $def, $type, $id, null );
				} else {
					$this->deleteValues( $def, $type, $id, 0 );
				}
			}
		);
		$this->changed( $type, $id );
	}

	/**
	 * Read Series rows as a list of arrays keyed by sub-field name.
	 *
	 * @param ObjectType $type Object type.
	 * @param int        $id   Object ID.
	 * @param string     $key  Series field key.
	 * @return array<int,array<string,mixed>>
	 */
	public function getRows( ObjectType $type, int $id, string $key ): array {
		$out  = $this->readRows( $type, $id, $key );
		$rule = $this->sorter?->rule( $key );
		if ( null !== $rule && RowSortRule::ON_READ === $rule->mode ) {
			return $this->sorter->sort( $out, $rule, $key, $id );
		}
		return $out;
	}

	/**
	 * Read Series rows for many objects: one query for the row ids plus one per sub-field typed table (and one
	 * for long text or off-type values when present), whatever the number of objects.
	 *
	 * @param ObjectType $type Object type.
	 * @param int[]      $ids  Object IDs.
	 * @param string     $key  Series field key.
	 * @return array<int,array<int,array<string,mixed>>> Rows per object ID, shaped as getRows() returns them.
	 */
	public function getRowsMany( ObjectType $type, array $ids, string $key ): array {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		$def = $this->rowsField( $key );
		if ( array() === $ids ) {
			return array();
		}
		$marks   = implode( ',', array_fill( 0, count( $ids ), '?' ) );
		$rowRows = $this->db->select(
			'SELECT object_id, row_id FROM ' . $this->db->table( 'nf_rows' ) . ' WHERE object_type = ? AND field_id = ? AND parent_row_id = 0 AND object_id IN (' . $marks . ') ORDER BY object_id, row_order',
			array_merge( array( $type->value, $def->id ), $ids )
		);
		$out     = array();
		$owner   = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = array();
		}
		foreach ( $rowRows as $r ) {
			$owner[ (int) $r['row_id'] ] = (int) $r['object_id'];
		}
		$cells = array();
		if ( array() !== $owner ) {
			foreach ( $this->fields->children( $def->id ) as $sub ) {
				foreach ( $this->readSubValuesMany( $sub, $type, $ids, $owner ) as $row_id => $vals ) {
					$cells[ $row_id ][ $sub->name ] = $vals;
				}
			}
		}
		$rule = $this->sorter?->rule( $key );
		foreach ( $rowRows as $r ) {
			$row_id = (int) $r['row_id'];
			$row    = array();
			foreach ( $this->fields->children( $def->id ) as $sub ) {
				$vals              = $cells[ $row_id ][ $sub->name ] ?? array();
				$row[ $sub->name ] = $sub->multi ? $vals : ( $vals[0] ?? null );
			}
			$out[ (int) $r['object_id'] ][] = $row;
		}
		if ( null !== $rule && RowSortRule::ON_READ === $rule->mode ) {
			foreach ( $out as $id => $rows ) {
				$out[ $id ] = $this->sorter->sort( $rows, $rule, $key, $id );
			}
		}
		return $out;
	}

	/**
	 * Sub-field values for many rows: one query, plus long text and off-type lookups only when needed.
	 *
	 * @param FieldDef       $sub   Sub-field.
	 * @param ObjectType     $type  Object type.
	 * @param int[]          $ids   Object IDs.
	 * @param array<int,int> $owner Object ID by row ID.
	 * @return array<int,array<int,mixed>> Values by row ID in position order.
	 */
	private function readSubValuesMany( FieldDef $sub, ObjectType $type, array $ids, array $owner ): array {
		$marks = implode( ',', array_fill( 0, count( $ids ), '?' ) );
		$rows  = $this->db->select(
			'SELECT object_id, row_id, position, value FROM ' . $this->db->table( $sub->table->table() ) . ' WHERE object_type = ? AND field_id = ? AND object_id IN (' . $marks . ') ORDER BY object_id, row_id, position',
			array_merge( array( $type->value, $sub->id ), $ids )
		);
		$rows  = array_values( array_filter( $rows, static fn( array $r ): bool => isset( $owner[ (int) $r['row_id'] ] ) ) );
		if ( DataTable::Str === $sub->table ) {
			$long = false;
			foreach ( $rows as $r ) {
				if ( mb_strlen( (string) $r['value'] ) >= self::STR_MAX ) {
					$long = true;
					break;
				}
			}
			if ( $long ) {
				$full = array();
				foreach ( $this->db->select(
					'SELECT object_id, row_id, position, value FROM ' . $this->db->table( 'nf_text' ) . ' WHERE object_type = ? AND field_id = ? AND object_id IN (' . $marks . ')',
					array_merge( array( $type->value, $sub->id ), $ids )
				) as $f ) {
					$full[ (int) $f['row_id'] ][ (int) $f['position'] ] = $f['value'];
				}
				$seen = array();
				foreach ( $rows as $i => $r ) {
					$rid = (int) $r['row_id'];
					$pos = $seen[ $rid ] ?? 0;
					if ( isset( $full[ $rid ][ $pos ] ) ) {
						$rows[ $i ]['value'] = $full[ $rid ][ $pos ];
					}
					$seen[ $rid ] = $pos + 1;
				}
			}
		}
		$by = array();
		foreach ( $rows as $r ) {
			$by[ (int) $r['row_id'] ][] = $sub->table->fromDb( $r['value'] );
		}
		if ( $this->hasOffType( $sub ) ) {
			foreach ( $this->db->select(
				'SELECT object_id, row_id, value FROM ' . $this->db->table( 'nf_json' ) . ' WHERE object_type = ? AND field_id = ? AND position = 0 AND object_id IN (' . $marks . ')',
				array_merge( array( $type->value, $sub->id ), $ids )
			) as $j ) {
				$rid = (int) $j['row_id'];
				if ( isset( $owner[ $rid ] ) && ! isset( $by[ $rid ] ) ) {
					$by[ $rid ] = array( DataTable::Json->fromDb( $j['value'] ) );
				}
			}
		}
		return $by;
	}

	/**
	 * Series rows in stored (`row_order`) order, bypassing any read-time sort rule: row i is the i-th row id.
	 *
	 * @param ObjectType $type Object type.
	 * @param int        $id   Object ID.
	 * @param string     $key  Series field key.
	 * @return array<int,array<string,mixed>>
	 */
	public function getStoredRows( ObjectType $type, int $id, string $key ): array {
		return $this->readRows( $type, $id, $key );
	}

	/**
	 * Read Series rows in stored order.
	 *
	 * @param ObjectType $type Object type.
	 * @param int        $id   Object ID.
	 * @param string     $key  Series field key.
	 * @return array<int,array<string,mixed>>
	 */
	private function readRows( ObjectType $type, int $id, string $key ): array {
		$def  = $this->rowsField( $key );
		$subs = $this->fields->children( $def->id );
		$rows = $this->db->select(
			'SELECT row_id FROM ' . $this->db->table( 'nf_rows' ) . ' WHERE object_type = ? AND object_id = ? AND field_id = ? AND parent_row_id = 0 ORDER BY row_order',
			array( $type->value, $id, $def->id )
		);
		$out  = array();
		foreach ( $rows as $r ) {
			$row_id = (int) $r['row_id'];
			$row    = array();
			foreach ( $subs as $sub ) {
				$vals = $this->readValues( $sub, $type, $id, $row_id );
				if ( $sub->multi ) {
					$row[ $sub->name ] = $vals;
				} elseif ( array() !== $vals ) {
					$row[ $sub->name ] = $vals[0];
				} else {
					$row[ $sub->name ] = null;
				}
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Sort the stored Series rows once and rewrite `row_order` in one statement (editor action, or any mode on demand).
	 *
	 * @param ObjectType       $type Object type.
	 * @param int              $id   Object ID.
	 * @param string           $key  Series field key.
	 * @param RowSortRule|null $rule Rule; defaults to the field's own rule.
	 * @return bool False when there is no rule or no sorter.
	 */
	public function sortRows( ObjectType $type, int $id, string $key, ?RowSortRule $rule = null ): bool {
		$rule ??= $this->sorter?->rule( $key );
		if ( null === $this->sorter || null === $rule ) {
			return false;
		}
		$def    = $this->rowsField( $key );
		$ids    = $this->db->select(
			'SELECT row_id FROM ' . $this->db->table( 'nf_rows' ) . ' WHERE object_type = ? AND object_id = ? AND field_id = ? AND parent_row_id = 0 ORDER BY row_order',
			array( $type->value, $id, $def->id )
		);
		$rows   = $this->readRows( $type, $id, $key );
		$rowIds = array_map( static fn( array $r ): int => (int) $r['row_id'], $ids );
		$tagged = array();
		foreach ( $rows as $i => $row ) {
			$row['__nf_row_id'] = $rowIds[ $i ];
			$tagged[]           = $row;
		}
		$sorted = $this->sorter->sort( $tagged, $rule->withMode( RowSortRule::MANUAL ), $key, $id );
		$order  = array();
		foreach ( $sorted as $row ) {
			if ( isset( $row['__nf_row_id'] ) ) {
				$order[] = (int) $row['__nf_row_id'];
			}
		}
		if ( count( $order ) !== count( $rowIds ) || array_diff( $order, $rowIds ) !== array() ) {
			return false; // A filter dropped or rebuilt rows: keep the stored order.
		}
		if ( $order === $rowIds ) {
			return true;
		}
		$case = 'CASE row_id';
		$args = array();
		foreach ( $order as $pos => $row_id ) {
			$case  .= ' WHEN ? THEN ?';
			$args[] = $row_id;
			$args[] = $pos;
		}
		$case .= ' END';
		$marks = implode( ',', array_fill( 0, count( $order ), '?' ) );
		$this->db->execute(
			'UPDATE ' . $this->db->table( 'nf_rows' ) . ' SET row_order = ' . $case . ' WHERE object_type = ? AND object_id = ? AND field_id = ? AND parent_row_id = 0 AND row_id IN (' . $marks . ')',
			array_merge( $args, array( $type->value, $id, $def->id ), $order )
		);
		/**
		 * Fires after rows were re-sorted and stored.
		 *
		 * @param array{key:string} $field Field info.
		 * @param int               $id    Object ID.
		 * @param int[]             $order New row_id order.
		 */
		do_action( 'nf/repeater/sorted', array( 'key' => $key ), $id, $order ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->changed( $type, $id );
		return true;
	}

	/**
	 * Replace all Series rows. Row order is the list order. Sub-fields must be registered with the Series as parent.
	 *
	 * @param ObjectType                     $type Object type.
	 * @param int                            $id   Object ID.
	 * @param string                         $key  Series field key.
	 * @param array<int,array<string,mixed>> $rows Rows keyed by sub-field name.
	 * @return void
	 * @throws \InvalidArgumentException On an unknown sub-field.
	 */
	public function setRows( ObjectType $type, int $id, string $key, array $rows ): void {
		$def    = $this->rowsField( $key );
		$byName = array();
		$rule   = $this->sorter?->rule( $key );
		if ( null !== $rule && RowSortRule::ON_SAVE === $rule->mode ) {
			$rows = $this->sorter->sort( $rows, $rule, $key, $id );
		}
		foreach ( $this->fields->children( $def->id ) as $sub ) {
			if ( ! $sub->table->hasValues() ) {
				throw new \InvalidArgumentException( 'Nested Series are not supported yet.' );
			}
			$byName[ $sub->name ] = $sub;
		}
		$this->db->transaction(
			function () use ( $def, $type, $id, $rows, $byName ): void {
				$this->deleteRows( $def, $type, $id );
				$order = 0;
				foreach ( array_values( $rows ) as $row ) {
					$this->db->execute(
						'INSERT INTO ' . $this->db->table( 'nf_rows' ) . ' (object_type, object_id, field_id, parent_row_id, row_order) VALUES (?, ?, ?, 0, ?)',
						array( $type->value, $id, $def->id, $order++ )
					);
					$row_id = $this->db->insertId();
					foreach ( $row as $name => $value ) {
						if ( ! isset( $byName[ $name ] ) ) {
							throw new \InvalidArgumentException( 'Unknown Series sub-field: ' . sanitize_key( (string) $name ) );
						}
						$this->writeValues( $byName[ $name ], $type, $id, $row_id, $value );
					}
				}
			}
		);
		$this->changed( $type, $id );
	}

	/**
	 * Remove every NF value of an object (post deletion).
	 *
	 * @param ObjectType $type Object type.
	 * @param int        $id   Object ID.
	 * @return void
	 */
	public function deleteObject( ObjectType $type, int $id ): void {
		$this->db->transaction(
			function () use ( $type, $id ): void {
				foreach ( DataTable::cases() as $t ) {
					if ( $t->hasValues() ) {
						$this->db->execute( 'DELETE FROM ' . $this->db->table( $t->table() ) . ' WHERE object_type = ? AND object_id = ?', array( $type->value, $id ) );
					}
				}
				$this->db->execute( 'DELETE FROM ' . $this->db->table( 'nf_rows' ) . ' WHERE object_type = ? AND object_id = ?', array( $type->value, $id ) );
			}
		);
		$this->changed( $type, $id );
	}

	/**
	 * Tell listeners (the loader's value cache) that an object's stored values changed.
	 *
	 * @param ObjectType $type Object type.
	 * @param int        $id   Object ID.
	 * @return void
	 */
	private function changed( ObjectType $type, int $id ): void {
		do_action( 'nf/store/changed', $type->value, $id ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	/**
	 * Look up a field that stores values directly.
	 *
	 * @param string $key Field key.
	 * @return FieldDef
	 * @throws \InvalidArgumentException When unknown or not a value field.
	 */
	private function valueField( string $key ): FieldDef {
		$def = $this->fields->byKey( $key );
		if ( null === $def || ( ! $def->table->hasValues() && DataTable::None !== $def->table ) ) {
			throw new \InvalidArgumentException( 'Not a value field: ' . sanitize_key( $key ) );
		}
		return $def;
	}

	/**
	 * Look up a Series field.
	 *
	 * @param string $key Field key.
	 * @return FieldDef
	 * @throws \InvalidArgumentException When unknown or not a Series.
	 */
	private function rowsField( string $key ): FieldDef {
		$def = $this->fields->byKey( $key );
		if ( null === $def || DataTable::Rows !== $def->table ) {
			throw new \InvalidArgumentException( 'Not a Series field: ' . sanitize_key( $key ) );
		}
		return $def;
	}

	/**
	 * Read a group-like field (group, link, Google Map): its children keyed by name, stored on row 0 of the parent.
	 *
	 * @param FieldDef   $def  Parent field (data table `none`).
	 * @param ObjectType $type Object type.
	 * @param int        $id   Object ID.
	 * @return array<string,mixed>
	 */
	private function readGroup( FieldDef $def, ObjectType $type, int $id ): array {
		$out = array();
		foreach ( $this->fields->children( $def->id ) as $child ) {
			if ( DataTable::None === $child->table ) {
				$out[ $child->name ] = $this->readGroup( $child, $type, $id );
				continue;
			}
			$vals                = $this->readValues( $child, $type, $id, 0 );
			$out[ $child->name ] = $child->multi ? $vals : ( $vals[0] ?? null );
		}
		return $out;
	}

	/**
	 * Replace a group-like field. Children missing from $value (or a null value) are removed.
	 *
	 * @param FieldDef   $def   Parent field (data table `none`).
	 * @param ObjectType $type  Object type.
	 * @param int        $id    Object ID.
	 * @param mixed      $value Array keyed by child name, or null.
	 * @return void
	 */
	private function writeGroup( FieldDef $def, ObjectType $type, int $id, mixed $value ): void {
		$map = is_array( $value ) ? $value : array();
		foreach ( $this->fields->children( $def->id ) as $child ) {
			$part = $map[ $child->name ] ?? null;
			if ( DataTable::None === $child->table ) {
				$this->writeGroup( $child, $type, $id, $part );
			} else {
				$this->writeValues( $child, $type, $id, 0, $part );
			}
		}
	}

	/**
	 * Read values for (object, field, row) in position order.
	 *
	 * @param FieldDef   $def    Field.
	 * @param ObjectType $type   Object type.
	 * @param int        $id     Object ID.
	 * @param int        $row_id Row ID, 0 outside a Series.
	 * @return array<int,mixed>
	 */
	private function readValues( FieldDef $def, ObjectType $type, int $id, int $row_id ): array {
		$rows = $this->db->select(
			'SELECT value FROM ' . $this->db->table( $def->table->table() ) . ' WHERE object_type = ? AND object_id = ? AND field_id = ? AND row_id = ? ORDER BY position',
			array( $type->value, $id, $def->id, $row_id )
		);
		if ( DataTable::Str === $def->table ) {
			$rows = $this->withSpill( $def, $type, $id, $row_id, $rows );
		}
		if ( array() === $rows && $this->hasOffType( $def ) ) {
			$json = $this->db->select(
				'SELECT value FROM ' . $this->db->table( 'nf_json' ) . ' WHERE object_type = ? AND object_id = ? AND field_id = ? AND row_id = ? AND position = 0',
				array( $type->value, $id, $def->id, $row_id )
			);
			return array() === $json ? array() : array( DataTable::Json->fromDb( $json[0]['value'] ) );
		}
		return array_map( fn( array $r ): mixed => $def->table->fromDb( $r['value'] ), $rows );
	}

	/**
	 * Field IDs that have held an off-type value: an array in a scalar field (an importer that writes arrays
	 * into an ACF text field). Only these fields pay for the nf_json lookup on an empty read.
	 *
	 * @return array<int,true>
	 */
	private function offTypeIds(): array {
		if ( null === $this->offType ) {
			$ids           = get_option( self::OFF_TYPE_OPTION, array() );
			$this->offType = array_fill_keys( array_map( 'intval', is_array( $ids ) ? $ids : array() ), true );
		}
		return $this->offType;
	}

	/**
	 * Whether a scalar field may have values in nf_json.
	 *
	 * @param FieldDef $def Field.
	 * @return bool
	 */
	private function hasOffType( FieldDef $def ): bool {
		return DataTable::Json !== $def->table && isset( $this->offTypeIds()[ $def->id ] );
	}

	/**
	 * Whether a value cannot go into the field's typed table and is kept in nf_json instead, as ACF returns it.
	 *
	 * @param FieldDef $def   Field.
	 * @param mixed    $value Value.
	 * @return bool
	 */
	private static function isOffType( FieldDef $def, mixed $value ): bool {
		return ! $def->multi && DataTable::Json !== $def->table && $def->table->hasValues() && ( is_array( $value ) || is_object( $value ) );
	}

	/**
	 * Record that a field holds an off-type value.
	 *
	 * @param FieldDef $def Field.
	 * @return void
	 */
	private function markOffType( FieldDef $def ): void {
		if ( isset( $this->offTypeIds()[ $def->id ] ) ) {
			return;
		}
		$this->offType[ $def->id ] = true;
		update_option( self::OFF_TYPE_OPTION, array_keys( (array) $this->offType ), true );
	}

	/**
	 * Replace 191-char prefixes with the full value from nf_text.
	 *
	 * @param FieldDef                       $def    Field.
	 * @param ObjectType                     $type   Object type.
	 * @param int                            $id     Object ID.
	 * @param int                            $row_id Row ID.
	 * @param array<int,array<string,mixed>> $rows   nf_str rows in position order.
	 * @return array<int,array<string,mixed>>
	 */
	private function withSpill( FieldDef $def, ObjectType $type, int $id, int $row_id, array $rows ): array {
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
			'SELECT position, value FROM ' . $this->db->table( 'nf_text' ) . ' WHERE object_type = ? AND object_id = ? AND field_id = ? AND row_id = ?',
			array( $type->value, $id, $def->id, $row_id )
		);
		$by   = array();
		foreach ( $full as $f ) {
			$by[ (int) $f['position'] ] = $f['value'];
		}
		foreach ( $rows as $i => $r ) {
			if ( isset( $by[ $i ] ) ) {
				$rows[ $i ]['value'] = $by[ $i ];
			}
		}
		return $rows;
	}

	/**
	 * Replace values for (object, field, row).
	 *
	 * @param FieldDef   $def    Field.
	 * @param ObjectType $type   Object type.
	 * @param int        $id     Object ID.
	 * @param int        $row_id Row ID.
	 * @param mixed      $value  Scalar or list.
	 * @return void
	 */
	private function writeValues( FieldDef $def, ObjectType $type, int $id, int $row_id, mixed $value ): void {
		$this->deleteValues( $def, $type, $id, $row_id );
		if ( self::isOffType( $def, $value ) ) {
			$this->markOffType( $def );
			$this->db->execute(
				'INSERT INTO ' . $this->db->table( 'nf_json' ) . ' (object_type, object_id, field_id, row_id, position, value) VALUES (?, ?, ?, ?, 0, ?)',
				array( $type->value, $id, $def->id, $row_id, DataTable::Json->toDb( $value ) )
			);
			return;
		}
		$list = $def->multi ? ( is_array( $value ) ? array_values( $value ) : ( null === $value ? array() : array( $value ) ) ) : ( null === $value ? array() : array( $value ) );
		$t    = $this->db->table( $def->table->table() );
		foreach ( $list as $pos => $v ) {
			$db_value = $def->table->toDb( $v, true );
			if ( DataTable::Str === $def->table && mb_strlen( (string) $db_value ) > self::STR_MAX ) {
				$this->db->execute(
					'INSERT INTO ' . $this->db->table( 'nf_text' ) . ' (object_type, object_id, field_id, row_id, position, value) VALUES (?, ?, ?, ?, ?, ?)',
					array( $type->value, $id, $def->id, $row_id, $pos, (string) $db_value )
				);
				$db_value = mb_substr( (string) $db_value, 0, self::STR_MAX );
			}
			$this->db->execute(
				'INSERT INTO ' . $t . ' (object_type, object_id, field_id, row_id, position, value) VALUES (?, ?, ?, ?, ?, ?)',
				array( $type->value, $id, $def->id, $row_id, $pos, $db_value )
			);
		}
	}

	/**
	 * Delete values for (object, field, row).
	 *
	 * @param FieldDef   $def    Field.
	 * @param ObjectType $type   Object type.
	 * @param int        $id     Object ID.
	 * @param int        $row_id Row ID.
	 * @return void
	 */
	private function deleteValues( FieldDef $def, ObjectType $type, int $id, int $row_id ): void {
		$this->db->execute(
			'DELETE FROM ' . $this->db->table( $def->table->table() ) . ' WHERE object_type = ? AND object_id = ? AND field_id = ? AND row_id = ?',
			array( $type->value, $id, $def->id, $row_id )
		);
		if ( DataTable::Str === $def->table ) {
			$this->db->execute(
				'DELETE FROM ' . $this->db->table( 'nf_text' ) . ' WHERE object_type = ? AND object_id = ? AND field_id = ? AND row_id = ?',
				array( $type->value, $id, $def->id, $row_id )
			);
		}
		if ( $this->hasOffType( $def ) ) {
			$this->db->execute(
				'DELETE FROM ' . $this->db->table( 'nf_json' ) . ' WHERE object_type = ? AND object_id = ? AND field_id = ? AND row_id = ?',
				array( $type->value, $id, $def->id, $row_id )
			);
		}
	}

	/**
	 * Delete all rows of a Series and their values.
	 *
	 * @param FieldDef   $def  Series field.
	 * @param ObjectType $type Object type.
	 * @param int        $id   Object ID.
	 * @return void
	 */
	private function deleteRows( FieldDef $def, ObjectType $type, int $id ): void {
		$rows = $this->db->select(
			'SELECT row_id FROM ' . $this->db->table( 'nf_rows' ) . ' WHERE object_type = ? AND object_id = ? AND field_id = ?',
			array( $type->value, $id, $def->id )
		);
		if ( array() === $rows ) {
			return;
		}
		$ids   = array_map( static fn( array $r ): int => (int) $r['row_id'], $rows );
		$marks = implode( ', ', array_fill( 0, count( $ids ), '?' ) );
		$seen  = array();
		foreach ( $this->fields->children( $def->id ) as $sub ) {
			if ( $sub->table->hasValues() && ! isset( $seen[ $sub->table->value ] ) ) {
				$seen[ $sub->table->value ] = true;
				$this->db->execute(
					'DELETE FROM ' . $this->db->table( $sub->table->table() ) . ' WHERE object_type = ? AND object_id = ? AND row_id IN (' . $marks . ')',
					array_merge( array( $type->value, $id ), $ids )
				);
				if ( DataTable::Str === $sub->table && ! isset( $seen['text'] ) ) {
					$seen['text'] = true;
					$this->db->execute(
						'DELETE FROM ' . $this->db->table( 'nf_text' ) . ' WHERE object_type = ? AND object_id = ? AND row_id IN (' . $marks . ')',
						array_merge( array( $type->value, $id ), $ids )
					);
				}
			}
		}
		foreach ( $this->fields->children( $def->id ) as $sub ) {
			if ( $this->hasOffType( $sub ) && ! isset( $seen['json'] ) ) {
				$seen['json'] = true;
				$this->db->execute(
					'DELETE FROM ' . $this->db->table( 'nf_json' ) . ' WHERE object_type = ? AND object_id = ? AND row_id IN (' . $marks . ')',
					array_merge( array( $type->value, $id ), $ids )
				);
			}
		}
		$this->db->execute(
			'DELETE FROM ' . $this->db->table( 'nf_rows' ) . ' WHERE object_type = ? AND object_id = ? AND field_id = ?',
			array( $type->value, $id, $def->id )
		);
	}
}
