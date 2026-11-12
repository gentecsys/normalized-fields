<?php
/**
 * Archive of revision and autosave snapshots.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Storage;

use NF\Backend\BackendInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Each revision or autosave is one nf_archive row holding a JSON snapshot of every top-level field,
 * keyed by field key (design: "How the pivot is filled", Revisions). The typed tables never hold
 * revision rows. Restoring writes a snapshot back through the Store.
 */
final class Archive {

	/**
	 * Constructor.
	 *
	 * @param BackendInterface $db     Backend.
	 * @param FieldMap         $fields Field map.
	 * @param Store            $store  Store.
	 */
	public function __construct( private BackendInterface $db, private FieldMap $fields, private Store $store ) {}

	/**
	 * Snapshot the live values of an object into an archive row, replacing any earlier snapshot of that ID.
	 *
	 * @param ObjectType $type      Object type.
	 * @param int        $parentId  Live object ID.
	 * @param int        $archiveId Revision or autosave ID.
	 * @return void
	 */
	public function capture( ObjectType $type, int $parentId, int $archiveId ): void {
		$data = array();
		foreach ( $this->fields->children( 0 ) as $def ) {
			$data[ $def->key ] = DataTable::Rows === $def->table
				? $this->store->getRows( $type, $parentId, $def->key )
				: $this->store->get( $type, $parentId, $def->key );
		}
		$this->write( $type, $parentId, $archiveId, $data );
	}

	/**
	 * Store an explicit snapshot (field key => value; a Series value is a list of rows).
	 *
	 * @param ObjectType          $type      Object type.
	 * @param int                 $parentId  Live object ID.
	 * @param int                 $archiveId Revision or autosave ID.
	 * @param array<string,mixed> $data      Values keyed by field key.
	 * @return void
	 * @throws \InvalidArgumentException When the data cannot be encoded.
	 */
	public function write( ObjectType $type, int $parentId, int $archiveId, array $data ): void {
		$json = wp_json_encode( $data );
		if ( false === $json ) {
			throw new \InvalidArgumentException( 'Snapshot cannot be encoded.' );
		}
		$t = $this->db->table( 'nf_archive' );
		$this->db->transaction(
			function () use ( $t, $type, $parentId, $archiveId, $json ): void {
				$this->db->execute( 'DELETE FROM ' . $t . ' WHERE object_type = ? AND object_id = ?', array( $type->value, $archiveId ) );
				$this->db->execute(
					'INSERT INTO ' . $t . ' (object_type, object_id, parent_id, snapshot) VALUES (?, ?, ?, ?)',
					array( $type->value, $archiveId, $parentId, $json )
				);
			}
		);
	}

	/**
	 * Read a snapshot (for previews and the revision compare screen). Does not touch the typed tables.
	 *
	 * @param ObjectType $type      Object type.
	 * @param int        $archiveId Revision or autosave ID.
	 * @return array<string,mixed>|null Values keyed by field key, or null when there is no snapshot.
	 */
	public function read( ObjectType $type, int $archiveId ): ?array {
		$rows = $this->db->select(
			'SELECT snapshot FROM ' . $this->db->table( 'nf_archive' ) . ' WHERE object_type = ? AND object_id = ?',
			array( $type->value, $archiveId )
		);
		if ( array() === $rows ) {
			return null;
		}
		$data = json_decode( (string) $rows[0]['snapshot'], true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Archive IDs of a live object, oldest first.
	 *
	 * @param ObjectType $type     Object type.
	 * @param int        $parentId Live object ID.
	 * @return int[]
	 */
	public function ids( ObjectType $type, int $parentId ): array {
		$rows = $this->db->select(
			'SELECT object_id FROM ' . $this->db->table( 'nf_archive' ) . ' WHERE object_type = ? AND parent_id = ? ORDER BY object_id',
			array( $type->value, $parentId )
		);
		return array_map( static fn( array $r ): int => (int) $r['object_id'], $rows );
	}

	/**
	 * Write a snapshot back into the typed tables of a live object. Fields in the snapshot with no value
	 * are cleared; fields registered after the snapshot was taken are left alone.
	 *
	 * @param ObjectType $type      Object type.
	 * @param int        $archiveId Revision or autosave ID.
	 * @param int        $targetId  Live object ID to restore into.
	 * @return bool False when there is no snapshot.
	 */
	public function restore( ObjectType $type, int $archiveId, int $targetId ): bool {
		$data = $this->read( $type, $archiveId );
		if ( null === $data ) {
			return false;
		}
		$this->db->transaction(
			function () use ( $data, $type, $targetId ): void {
				foreach ( $data as $key => $value ) {
					$def = $this->fields->byKey( (string) $key );
					if ( null === $def || 0 !== $def->parentId ) {
						continue;
					}
					if ( DataTable::Rows === $def->table ) {
						$this->store->setRows( $type, $targetId, $def->key, is_array( $value ) ? $value : array() );
					} else {
						$this->store->set( $type, $targetId, $def->key, $value );
					}
				}
			}
		);
		return true;
	}

	/**
	 * Delete one snapshot (a revision was deleted).
	 *
	 * @param ObjectType $type      Object type.
	 * @param int        $archiveId Revision or autosave ID.
	 * @return void
	 */
	public function delete( ObjectType $type, int $archiveId ): void {
		$this->db->execute( 'DELETE FROM ' . $this->db->table( 'nf_archive' ) . ' WHERE object_type = ? AND object_id = ?', array( $type->value, $archiveId ) );
	}

	/**
	 * Delete every snapshot of a live object (the post was deleted).
	 *
	 * @param ObjectType $type     Object type.
	 * @param int        $parentId Live object ID.
	 * @return void
	 */
	public function deleteForParent( ObjectType $type, int $parentId ): void {
		$this->db->execute( 'DELETE FROM ' . $this->db->table( 'nf_archive' ) . ' WHERE object_type = ? AND parent_id = ?', array( $type->value, $parentId ) );
	}
}
