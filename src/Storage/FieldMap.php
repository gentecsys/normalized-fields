<?php
/**
 * Field map: field key to field_id.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Storage;

use NF\Backend\BackendInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Reads and registers nf_fields rows. Cached in memory per request (keyed by physical table, so switch_blog is safe).
 */
final class FieldMap {

	/**
	 * In-memory cache, keyed by physical table so switch_blog is safe.
	 *
	 * @var array<string,FieldDef>
	 */
	private array $byKey = array();

	/**
	 * Constructor.
	 *
	 * @param BackendInterface $db Backend.
	 */
	public function __construct( private BackendInterface $db ) {}

	/**
	 * Find a field by key.
	 *
	 * @param string $key Field key.
	 * @return FieldDef|null
	 */
	public function byKey( string $key ): ?FieldDef {
		$table = $this->db->table( 'nf_fields' );
		$memo  = $table . '|' . $key;
		if ( isset( $this->byKey[ $memo ] ) ) {
			return $this->byKey[ $memo ];
		}
		$rows = $this->db->select( 'SELECT field_id, field_key, field_name, parent_id, data_table, multi FROM ' . $table . ' WHERE field_key = ?', array( $key ) );
		if ( array() === $rows ) {
			return null;
		}
		$def                = $this->hydrate( $rows[0] );
		$this->byKey[ $memo ] = $def;
		return $def;
	}

	/**
	 * Register a field, or return the existing one. A key never changes its type here; use a migration.
	 *
	 * @param string    $key      Field key.
	 * @param string    $name     Field name.
	 * @param DataTable $table    Data table.
	 * @param bool      $multi    Multi-valued.
	 * @param int       $parentId Parent field_id.
	 * @return FieldDef
	 * @throws \InvalidArgumentException When the key exists with a different type.
	 */
	public function register( string $key, string $name, DataTable $table, bool $multi = false, int $parentId = 0 ): FieldDef {
		$existing = $this->byKey( $key );
		if ( null !== $existing ) {
			if ( $existing->table !== $table || $existing->multi !== $multi ) {
				throw new \InvalidArgumentException( 'Field key already registered with a different storage type.' );
			}
			return $existing;
		}
		$t = $this->db->table( 'nf_fields' );
		$this->db->execute(
			'INSERT INTO ' . $t . ' (field_key, field_name, parent_id, data_table, multi) VALUES (?, ?, ?, ?, ?)',
			array( $key, $name, $parentId, $table->value, $multi ? 1 : 0 )
		);
		$def = new FieldDef( $this->db->insertId(), $key, $name, $parentId, $table, $multi );
		$this->byKey[ $t . '|' . $key ] = $def;
		return $def;
	}

	/**
	 * Sub-fields of a field (Series columns), in registration order.
	 *
	 * @param int $parentId Parent field_id.
	 * @return FieldDef[]
	 */
	public function children( int $parentId ): array {
		$rows = $this->db->select( 'SELECT field_id, field_key, field_name, parent_id, data_table, multi FROM ' . $this->db->table( 'nf_fields' ) . ' WHERE parent_id = ? ORDER BY field_id', array( $parentId ) );
		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Drop the in-memory cache.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->byKey = array();
	}

	/**
	 * Build a definition from a row.
	 *
	 * @param array<string,scalar|null> $row Row.
	 * @return FieldDef
	 */
	private function hydrate( array $row ): FieldDef {
		return new FieldDef(
			(int) $row['field_id'],
			(string) $row['field_key'],
			(string) $row['field_name'],
			(int) $row['parent_id'],
			DataTable::from( (string) $row['data_table'] ),
			1 === (int) $row['multi']
		);
	}
}
