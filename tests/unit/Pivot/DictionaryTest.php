<?php
/**
 * Dictionary and string slots (WP 2.2a).
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
 * Dictionary ids are stable, reads never write, and s-slots hold dictionary ids that rebuild reproduces.
 */
class DictionaryTest extends WP_UnitTestCase {

	private const TYPE = 'nf_dict_event';

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
	 * Field id of the "venue" field.
	 *
	 * @var int
	 */
	private int $venue_id;

	/**
	 * Post IDs created.
	 *
	 * @var int[]
	 */
	private array $ids = array();

	/**
	 * Fields, pivot set and an s1 slot.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		register_post_type( self::TYPE );
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t22a%' ) );
		$fields = new FieldMap( $db );
		$fields->flush();
		$venue          = $fields->register( 'field_t22a_venue', 'venue', DataTable::Str );
		$this->venue_id = $venue->id;
		$fields->register( 'field_t22a_notes', 'notes', DataTable::Str, true );
		$fields->register( 'field_t22a_count', 'count', DataTable::Int );
		$this->store    = new Store( $db, $fields );
		$this->pivot    = new Pivot( $db, $fields, $this->store );
		$this->pivot_id = $this->pivot->setId( ObjectType::Post, self::TYPE );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot_slots' ) . ' WHERE pivot_id = ?', array( $this->pivot_id ) );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot' ) . ' WHERE pivot_id = ?', array( $this->pivot_id ) );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_dict' ) . ' WHERE field_id = ?', array( $this->venue_id ) );
		$this->pivot->assignSlot( $this->pivot_id, 'field_t22a_venue', 's1' );
	}

	/**
	 * Remove stored values and rows (DDL in set_up commits the suite's transaction).
	 */
	public function tear_down() {
		foreach ( $this->ids as $id ) {
			$this->store->deleteObject( ObjectType::Post, $id );
			wp_delete_post( $id, true );
		}
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot' ) . ' WHERE pivot_id = ?', array( $this->pivot_id ) );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot_slots' ) . ' WHERE pivot_id = ?', array( $this->pivot_id ) );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_dict' ) . ' WHERE field_id = ?', array( $this->venue_id ) );
		parent::tear_down();
	}

	/**
	 * Create a post with a venue and sync its pivot row.
	 *
	 * @param string|null $venue Venue value, or null for none.
	 * @return int
	 */
	private function event( ?string $venue ): int {
		$id          = self::factory()->post->create( array( 'post_type' => self::TYPE ) );
		$this->ids[] = $id;
		if ( null !== $venue ) {
			$this->store->set( ObjectType::Post, $id, 'field_t22a_venue', $venue );
		}
		$this->pivot->sync( $this->pivot_id, $id, array( 'status' => 1 ) );
		return $id;
	}

	/**
	 * The s1 value of a pivot row.
	 *
	 * @param int $id Object id.
	 * @return string|null
	 */
	private function s1( int $id ): ?string {
		$rows = nf_db()->select( 'SELECT s1 FROM ' . nf_db()->table( 'nf_pivot' ) . ' WHERE pivot_id = ? AND object_id = ?', array( $this->pivot_id, $id ) );
		return null === $rows[0]['s1'] ? null : (string) $rows[0]['s1'];
	}

	/**
	 * Ids are stable per (field, value), distinct across values and fields, and reversible.
	 */
	public function test_ids_are_stable_and_reversible(): void {
		$dict = new Dictionary( nf_db() );
		$a    = $dict->id( $this->venue_id, 'Main Hall' );
		$this->assertSame( $a, $dict->id( $this->venue_id, 'Main Hall' ) );
		$this->assertNotSame( $a, $dict->id( $this->venue_id, 'Annex' ) );
		$this->assertSame( 'Main Hall', $dict->value( $a ) );
		$this->assertSame( $a, $dict->find( $this->venue_id, 'Main Hall' ) );
	}

	/**
	 * The find method is read-only; the id method rejects values the column cannot hold.
	 */
	public function test_find_never_writes_and_long_values_are_refused(): void {
		$dict = new Dictionary( nf_db() );
		$this->assertNull( $dict->find( $this->venue_id, 'Never seen' ) );
		$this->assertNull( $dict->find( $this->venue_id, 'Never seen' ) );
		$count = nf_db()->select( 'SELECT COUNT(*) AS c FROM ' . nf_db()->table( 'nf_dict' ) . ' WHERE field_id = ?', array( $this->venue_id ) );
		$this->assertSame( '0', (string) $count[0]['c'] );
		$this->assertTrue( Dictionary::fits( str_repeat( 'é', 191 ) ) );
		$this->assertFalse( Dictionary::fits( str_repeat( 'a', 192 ) ) );
		$this->expectException( InvalidArgumentException::class );
		$dict->id( $this->venue_id, str_repeat( 'a', 192 ) );
	}

	/**
	 * A synced row holds the dictionary id; equal values share an id; no value, or a value over 191 characters, is NULL.
	 */
	public function test_sync_writes_dictionary_ids(): void {
		$a    = $this->event( 'Main Hall' );
		$b    = $this->event( 'Main Hall' );
		$c    = $this->event( 'Annex' );
		$none = $this->event( null );
		$long = $this->event( str_repeat( 'x', 200 ) );
		$dict = $this->pivot->dictionary();

		$this->assertSame( (string) $dict->find( $this->venue_id, 'Main Hall' ), $this->s1( $a ) );
		$this->assertSame( $this->s1( $a ), $this->s1( $b ) );
		$this->assertNotSame( $this->s1( $a ), $this->s1( $c ) );
		$this->assertNull( $this->s1( $none ) );
		$this->assertNull( $this->s1( $long ) );

		// Changing a value moves the slot; clearing it makes the slot NULL again.
		$this->store->set( ObjectType::Post, $a, 'field_t22a_venue', 'Annex' );
		$this->pivot->sync( $this->pivot_id, $a, array( 'status' => 1 ) );
		$this->assertSame( $this->s1( $c ), $this->s1( $a ) );
		$this->store->delete( ObjectType::Post, $a, 'field_t22a_venue' );
		$this->pivot->sync( $this->pivot_id, $a, array( 'status' => 1 ) );
		$this->assertNull( $this->s1( $a ) );
	}

	/**
	 * Rebuild reproduces the write-through rows exactly.
	 */
	public function test_rebuild_equals_write_through(): void {
		foreach ( array( 'Main Hall', 'Annex', 'Main Hall', null, 'Café' ) as $venue ) {
			$this->event( $venue );
		}
		$sql    = 'SELECT object_id, status, s1 FROM ' . nf_db()->table( 'nf_pivot' ) . ' WHERE pivot_id = ? ORDER BY object_id';
		$before = nf_db()->select( $sql, array( $this->pivot_id ) );
		$this->assertCount( 5, $before );
		$this->assertSame( 5, $this->pivot->rebuild( $this->pivot_id ) );
		$this->assertSame( $before, nf_db()->select( $sql, array( $this->pivot_id ) ) );
	}

	/**
	 * Only top-level single-valued str fields take an s-slot; letters must match the data table.
	 */
	public function test_slot_assignment_rules(): void {
		foreach ( array(
			array( 'field_t22a_notes', 's2' ),  // Multi-valued.
			array( 'field_t22a_venue', 'i2' ),  // Wrong letter.
			array( 'field_t22a_venue', 's9' ),  // No such column.
			array( 'field_t22a_count', 's2' ),  // Int field in an s-slot.
		) as list( $key, $slot ) ) {
			try {
				$this->pivot->assignSlot( $this->pivot_id, $key, $slot );
				$this->fail( "{$key} -> {$slot} should be refused" );
			} catch ( InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		$this->pivot->assignSlot( $this->pivot_id, 'field_t22a_venue', 's8' );
		$this->assertArrayHasKey( 's8', $this->pivot->slots( $this->pivot_id ) );
	}
}
