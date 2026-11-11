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
	 */
	public function __construct( private BackendInterface $db, private FieldMap $fields ) {}

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
		if ( null === $def || ! $def->table->hasValues() ) {
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
		return array_map( fn( array $r ): mixed => $def->table->fromDb( $r['value'] ), $rows );
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
		$this->db->execute(
			'DELETE FROM ' . $this->db->table( 'nf_rows' ) . ' WHERE object_type = ? AND object_id = ? AND field_id = ?',
			array( $type->value, $id, $def->id )
		);
	}
}
