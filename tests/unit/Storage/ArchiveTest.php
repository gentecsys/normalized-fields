<?php
/**
 * Archive tests (WP 2.3): snapshot, preview, restore.
 *
 * @package NF
 */

use NF\Storage\Archive;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * Snapshots live outside the typed tables; restore writes them back.
 */
class ArchiveTest extends WP_UnitTestCase {

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Archive under test.
	 *
	 * @var Archive
	 */
	private Archive $archive;

	/**
	 * Register fields on a clean slate.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		$db      = nf_db();
		$fields  = new FieldMap( $db );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t23%' ) );
		$fields->flush();
		$fields->register( 'field_t23_title', 'title', DataTable::Str );
		$fields->register( 'field_t23_rel', 'related', DataTable::Int, true );
		$s = $fields->register( 'field_t23_sessions', 'sessions', DataTable::Rows );
		$fields->register( 'field_t23_s_name', 'name', DataTable::Str, false, $s->id );
		$this->store   = new Store( $db, $fields );
		$this->archive = new Archive( $db, $fields, $this->store );
	}

	/**
	 * Clean up.
	 */
	public function tear_down() {
		foreach ( array( 201, 202, 203 ) as $id ) {
			$this->store->deleteObject( ObjectType::Post, $id );
			$this->archive->delete( ObjectType::Post, $id );
		}
		parent::tear_down();
	}

	/**
	 * Fill the live post.
	 */
	private function fill(): void {
		$this->store->set( ObjectType::Post, 201, 'field_t23_title', 'Original' );
		$this->store->set( ObjectType::Post, 201, 'field_t23_rel', array( 5, 6 ) );
		$this->store->setRows( ObjectType::Post, 201, 'field_t23_sessions', array( array( 'name' => 'A' ), array( 'name' => 'B' ) ) );
	}

	/**
	 * A snapshot is readable without touching the typed tables, and later live edits do not change it.
	 */
	public function test_capture_and_preview() {
		$this->fill();
		$this->archive->capture( ObjectType::Post, 201, 202 );
		$this->store->set( ObjectType::Post, 201, 'field_t23_title', 'Edited' );

		$snap = $this->archive->read( ObjectType::Post, 202 );
		$this->assertSame( 'Original', $snap['field_t23_title'] );
		$this->assertSame( array( 5, 6 ), $snap['field_t23_rel'] );
		$this->assertSame( 'B', $snap['field_t23_sessions'][1]['name'] );
		$this->assertNull( $this->store->get( ObjectType::Post, 202, 'field_t23_title' ), 'No typed rows for the revision.' );
		$this->assertSame( array( 202 ), $this->archive->ids( ObjectType::Post, 201 ) );
		$this->assertNull( $this->archive->read( ObjectType::Post, 999 ) );
	}

	/**
	 * Restore writes every value back, including cleared ones and Series rows.
	 */
	public function test_restore() {
		$this->fill();
		$this->archive->capture( ObjectType::Post, 201, 202 );
		$this->store->set( ObjectType::Post, 201, 'field_t23_title', 'Edited' );
		$this->store->set( ObjectType::Post, 201, 'field_t23_rel', array( 9 ) );
		$this->store->setRows( ObjectType::Post, 201, 'field_t23_sessions', array( array( 'name' => 'Z' ) ) );

		$this->assertTrue( $this->archive->restore( ObjectType::Post, 202, 201 ) );
		$this->assertSame( 'Original', $this->store->get( ObjectType::Post, 201, 'field_t23_title' ) );
		$this->assertSame( array( 5, 6 ), $this->store->get( ObjectType::Post, 201, 'field_t23_rel' ) );
		$this->assertSame( array( 'A', 'B' ), array_column( $this->store->getRows( ObjectType::Post, 201, 'field_t23_sessions' ), 'name' ) );
		$this->assertFalse( $this->archive->restore( ObjectType::Post, 999, 201 ) );
	}

	/**
	 * A field empty at snapshot time is cleared by restore; recapturing replaces the snapshot.
	 */
	public function test_restore_clears_and_recapture() {
		$this->store->set( ObjectType::Post, 201, 'field_t23_title', 'Only' );
		$this->archive->capture( ObjectType::Post, 201, 202 );
		$this->store->set( ObjectType::Post, 201, 'field_t23_rel', array( 1 ) );
		$this->archive->restore( ObjectType::Post, 202, 201 );
		$this->assertSame( array(), $this->store->get( ObjectType::Post, 201, 'field_t23_rel' ) );

		$this->archive->capture( ObjectType::Post, 201, 202 );
		$this->assertCount( 1, $this->archive->ids( ObjectType::Post, 201 ) );
	}

	/**
	 * Deleting removes one snapshot or all of a parent's.
	 */
	public function test_delete() {
		$this->fill();
		$this->archive->capture( ObjectType::Post, 201, 202 );
		$this->archive->capture( ObjectType::Post, 201, 203 );
		$this->archive->delete( ObjectType::Post, 202 );
		$this->assertSame( array( 203 ), $this->archive->ids( ObjectType::Post, 201 ) );
		$this->archive->deleteForParent( ObjectType::Post, 201 );
		$this->assertSame( array(), $this->archive->ids( ObjectType::Post, 201 ) );
	}
}
