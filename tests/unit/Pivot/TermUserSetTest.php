<?php
/**
 * Term and user pivot sets (WP 3.0h).
 *
 * @package NF
 */

use NF\Pivot\Pivot;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * Term and user sets are rebuilt from core plus the typed tables, reading values with the set's object type.
 */
class TermUserSetTest extends WP_UnitTestCase {

	private const TAX = 'nf_t30h_tax';

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Pivot.
	 *
	 * @var Pivot
	 */
	private Pivot $pivot;

	/**
	 * Register the fields.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		register_taxonomy( self::TAX, 'post', array( 'hierarchical' => true ) );
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t30h%' ) );
		$fields = new FieldMap( $db );
		$fields->flush();
		$fields->register( 'field_t30h_rank', 'rank', DataTable::Int );
		$this->store = new Store( $db, $fields );
		$this->pivot = new Pivot( $db, $fields, $this->store );
	}

	/**
	 * A term set takes parent and typed values from the term, not from a post with the same id.
	 */
	public function test_term_set_rebuilds_from_core(): void {
		$parent = self::factory()->term->create( array( 'taxonomy' => self::TAX ) );
		$child  = self::factory()->term->create(
			array(
				'taxonomy' => self::TAX,
				'parent'   => $parent,
			)
		);
		$this->store->set( ObjectType::Term, $child, 'field_t30h_rank', 7 );
		$set = $this->pivot->setId( ObjectType::Term, self::TAX );
		$this->pivot->assignSlot( $set, 'field_t30h_rank', 'i1' );
		$this->assertSame( ObjectType::Term, $this->pivot->setType( $set ) );
		$this->pivot->rebuildFromCore( $set );
		$db   = nf_db();
		$rows = $db->select( 'SELECT object_id, parent_id, status, i1 FROM ' . $db->table( 'nf_pivot' ) . ' WHERE pivot_id = ? ORDER BY object_id', array( $set ) );
		$by   = array_column( $rows, null, 'object_id' );
		$this->assertSame( $parent, (int) $by[ $child ]['parent_id'] );
		$this->assertSame( 7, (int) $by[ $child ]['i1'] );
		$this->assertNull( $by[ $parent ]['i1'] );
		$this->assertSame( 1, (int) $by[ $child ]['status'] );
	}

	/**
	 * A user set holds every user, with the typed value read as a user value.
	 */
	public function test_user_set_rebuilds_from_core(): void {
		$uid = self::factory()->user->create();
		$this->store->set( ObjectType::User, $uid, 'field_t30h_rank', 3 );
		$set = $this->pivot->setId( ObjectType::User, 'user' );
		$this->pivot->assignSlot( $set, 'field_t30h_rank', 'i1' );
		$this->pivot->rebuildFromCore( $set );
		$db  = nf_db();
		$row = $db->select( 'SELECT i1, published FROM ' . $db->table( 'nf_pivot' ) . ' WHERE pivot_id = ? AND object_id = ?', array( $set, $uid ) );
		$this->assertSame( 3, (int) $row[0]['i1'] );
		$this->assertNotNull( $row[0]['published'] );
	}
}
