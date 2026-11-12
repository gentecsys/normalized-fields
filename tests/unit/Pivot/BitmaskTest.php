<?php
/**
 * Bitmask slots for multi-valued fields (WP 2.2b).
 *
 * @package NF
 */

use NF\Pivot\Dictionary;
use NF\Pivot\Pivot;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * Bits come from nf_dict, m-slots hold their OR, and rebuild reproduces write-through.
 */
class BitmaskTest extends WP_UnitTestCase {

	private const TYPE = 'nf_mask_event';

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
	 * Pivot set id.
	 *
	 * @var int
	 */
	private int $pivot_id;

	/**
	 * Field id of "category".
	 *
	 * @var int
	 */
	private int $cat_id;

	/**
	 * Post IDs created.
	 *
	 * @var int[]
	 */
	private array $ids = array();

	/**
	 * Fields, pivot set and an m1 slot.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		register_post_type( self::TYPE );
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t22b%' ) );
		$fields = new FieldMap( $db );
		$fields->flush();
		$this->cat_id = $fields->register( 'field_t22b_cat', 'cat', DataTable::Str, true )->id;
		$fields->register( 'field_t22b_ints', 'ints', DataTable::Int, true );
		$fields->register( 'field_t22b_one', 'one', DataTable::Str );
		$this->store    = new Store( $db, $fields );
		$this->pivot    = new Pivot( $db, $fields, $this->store );
		$this->pivot_id = $this->pivot->setId( ObjectType::Post, self::TYPE );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot_slots' ) . ' WHERE pivot_id = ?', array( $this->pivot_id ) );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot' ) . ' WHERE pivot_id = ?', array( $this->pivot_id ) );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_dict' ) . ' WHERE field_id = ?', array( $this->cat_id ) );
		$this->pivot->assignSlot( $this->pivot_id, 'field_t22b_cat', 'm1' );
	}

	/**
	 * Remove stored values and rows.
	 */
	public function tear_down() {
		foreach ( $this->ids as $id ) {
			$this->store->deleteObject( ObjectType::Post, $id );
			wp_delete_post( $id, true );
		}
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot' ) . ' WHERE pivot_id = ?', array( $this->pivot_id ) );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot_slots' ) . ' WHERE pivot_id = ?', array( $this->pivot_id ) );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_dict' ) . ' WHERE field_id = ?', array( $this->cat_id ) );
		parent::tear_down();
	}

	/**
	 * Create a post with categories and sync its pivot row.
	 *
	 * @param string[] $cats Categories.
	 * @return int
	 */
	private function event( array $cats ): int {
		$id          = self::factory()->post->create( array( 'post_type' => self::TYPE ) );
		$this->ids[] = $id;
		if ( $cats ) {
			$this->store->set( ObjectType::Post, $id, 'field_t22b_cat', $cats );
		}
		$this->pivot->sync( $this->pivot_id, $id, array( 'status' => 1 ) );
		return $id;
	}

	/**
	 * The m1 value of a pivot row, as an unsigned decimal string.
	 *
	 * @param int $id Object id.
	 * @return string
	 */
	private function m1( int $id ): string {
		$rows = nf_db()->select( 'SELECT m1 FROM ' . nf_db()->table( 'nf_pivot' ) . ' WHERE pivot_id = ? AND object_id = ?', array( $this->pivot_id, $id ) );
		return (string) $rows[0]['m1'];
	}

	/**
	 * Bits are assigned lowest-first, stay stable, and reads never assign.
	 */
	public function test_bits_are_stable_and_reads_do_not_write(): void {
		$dict = $this->pivot->dictionary();
		$this->assertNull( $dict->findBit( $this->cat_id, 'music' ) );
		$this->assertSame( 0, $dict->bit( $this->cat_id, 'music' ) );
		$this->assertSame( 1, $dict->bit( $this->cat_id, 'food' ) );
		$this->assertSame( 0, $dict->bit( $this->cat_id, 'music' ) );
		$this->assertSame( 1, $dict->findBit( $this->cat_id, 'food' ) );
		$this->assertNull( $dict->findBit( $this->cat_id, 'art' ) );
		$this->assertNull( $dict->bit( $this->cat_id, str_repeat( 'x', 192 ) ) );
	}

	/**
	 * Slot holds the OR of the value bits; empty is 0, never NULL.
	 */
	public function test_sync_writes_masks(): void {
		$a = $this->event( array( 'music', 'food' ) );
		$b = $this->event( array( 'food' ) );
		$c = $this->event( array() );
		$this->assertSame( '3', $this->m1( $a ) );
		$this->assertSame( '2', $this->m1( $b ) );
		$this->assertSame( '0', $this->m1( $c ) );
		$this->store->set( ObjectType::Post, $b, 'field_t22b_cat', array( 'music' ) );
		$this->pivot->sync( $this->pivot_id, $b, array( 'status' => 1 ) );
		$this->assertSame( '1', $this->m1( $b ) );
	}

	/**
	 * All 64 bits work, including the sign bit; the 65th value gets no bit and is left out.
	 */
	public function test_sixty_four_bits_and_overflow(): void {
		$all = array();
		for ( $i = 0; $i < 65; $i++ ) {
			$all[] = 'v' . $i;
		}
		$id = $this->event( $all );
		$this->assertSame( '18446744073709551615', $this->m1( $id ) );
		$this->assertNull( $this->pivot->dictionary()->findBit( $this->cat_id, 'v64' ) );
		$this->assertSame( 63, $this->pivot->dictionary()->findBit( $this->cat_id, 'v63' ) );
	}

	/**
	 * Rebuild reproduces the write-through rows exactly.
	 */
	public function test_rebuild_equals_write_through(): void {
		foreach ( array( array( 'a', 'b' ), array( 'b' ), array(), array( 'c', 'a' ) ) as $cats ) {
			$this->event( $cats );
		}
		$sql    = 'SELECT object_id, status, m1 FROM ' . nf_db()->table( 'nf_pivot' ) . ' WHERE pivot_id = ? ORDER BY object_id';
		$before = nf_db()->select( $sql, array( $this->pivot_id ) );
		$this->assertCount( 4, $before );
		$this->assertSame( 4, $this->pivot->rebuild( $this->pivot_id ) );
		$this->assertSame( $before, nf_db()->select( $sql, array( $this->pivot_id ) ) );
	}

	/**
	 * Only multi-valued str fields take an m-slot, and single-valued ones refuse it.
	 */
	public function test_slot_assignment_rules(): void {
		foreach ( array(
			array( 'field_t22b_ints', 'm2' ), // Multi-valued int.
			array( 'field_t22b_one', 'm2' ),  // Single-valued.
			array( 'field_t22b_cat', 's2' ),  // Wrong letter.
			array( 'field_t22b_cat', 'm5' ),  // No such column.
		) as list( $key, $slot ) ) {
			try {
				$this->pivot->assignSlot( $this->pivot_id, $key, $slot );
				$this->fail( "{$key} -> {$slot} should be refused" );
			} catch ( InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$this->pivot->assignSlot( $this->pivot_id, 'field_t22b_cat', 'm4' );
		$this->assertArrayHasKey( 'm4', $this->pivot->slots( $this->pivot_id ) );
	}
}
