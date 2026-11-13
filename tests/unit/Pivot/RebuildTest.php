<?php
/**
 * Chunked rebuild, dictionary pruning, collation and fuzz (WP 2.2d).
 *
 * @package NF
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.rand_seeding,WordPress.WP.AlternativeFunctions.rand_mt_rand -- the seeded fuzz must be deterministic.

use NF\Pivot\Pivot;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * Rebuild equals write-through byte for byte; rollups equal a live COUNT(*); pruning keeps referenced entries.
 */
class RebuildTest extends WP_UnitTestCase {

	private const TYPE = 'nf_rebuild_evt';

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
	 * Field ids by name.
	 *
	 * @var array<string,int>
	 */
	private array $fid = array();

	/**
	 * Post IDs created.
	 *
	 * @var int[]
	 */
	private array $ids = array();

	/**
	 * Fields (city str, seats int, sessions Series with venue str and seats int), set and slots.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		register_post_type( self::TYPE );
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t22d%' ) );
		$fields = new FieldMap( $db );
		$fields->flush();
		$city           = $fields->register( 'field_t22d_city', 'city', DataTable::Str );
		$seats          = $fields->register( 'field_t22d_seats', 'seats', DataTable::Int );
		$series         = $fields->register( 'field_t22d_sessions', 'sessions', DataTable::Rows );
		$venue          = $fields->register( 'field_t22d_venue', 'venue', DataTable::Str, false, $series->id );
		$cap            = $fields->register( 'field_t22d_cap', 'cap', DataTable::Int, false, $series->id );
		$this->fid      = array(
			'city'  => $city->id,
			'seats' => $seats->id,
			'cap'   => $cap->id,
			'venue' => $venue->id,
		);
		$this->store    = new Store( $db, $fields );
		$this->pivot    = new Pivot( $db, $fields, $this->store );
		$this->pivot_id = $this->pivot->setId( ObjectType::Post, self::TYPE );
		$this->clean();
		$this->pivot->assignSlot( $this->pivot_id, 'field_t22d_city', 's1' );
		$this->pivot->assignSlot( $this->pivot_id, 'field_t22d_seats', 'i1' );
		$rp = $this->pivot->rows();
		$rp->assignSlot( $this->pivot_id, 'field_t22d_sessions', 'field_t22d_venue', 's1' );
		$rp->assignSlot( $this->pivot_id, 'field_t22d_sessions', 'field_t22d_cap', 'i1' );
		$rp->assignRollup( $this->pivot_id, 'field_t22d_sessions', 'field_t22d_cap', 'i2', 'count' );
		$rp->assignRollup( $this->pivot_id, 'field_t22d_sessions', 'field_t22d_cap', 'i3', 'max' );
	}

	/**
	 * Remove test data.
	 */
	public function tear_down() {
		foreach ( $this->ids as $id ) {
			$this->store->deleteObject( ObjectType::Post, $id );
			wp_delete_post( $id, true );
		}
		$this->clean();
		parent::tear_down();
	}

	/**
	 * Delete pivot data of the test set and its dictionary entries.
	 */
	private function clean(): void {
		$db = nf_db();
		foreach ( array( 'nf_pivot', 'nf_row_pivot', 'nf_pivot_slots' ) as $t ) {
			$db->execute( 'DELETE FROM ' . $db->table( $t ) . ' WHERE pivot_id = ?', array( $this->pivot_id ) );
		}
		if ( $this->fid ) {
			$db->execute( 'DELETE FROM ' . $db->table( 'nf_dict' ) . ' WHERE field_id IN (?, ?)', array( $this->fid['city'], $this->fid['venue'] ) );
		}
	}

	/**
	 * Create an object, store values and sync its pivot row.
	 *
	 * @param string|null                    $city  City.
	 * @param int|null                       $seats Seats.
	 * @param array<int,array<string,mixed>> $rows  Series rows.
	 * @return int
	 */
	private function make( ?string $city, ?int $seats, array $rows ): int {
		$id          = self::factory()->post->create( array( 'post_type' => self::TYPE ) );
		$this->ids[] = $id;
		if ( null !== $city ) {
			$this->store->set( ObjectType::Post, $id, 'field_t22d_city', $city );
		}
		if ( null !== $seats ) {
			$this->store->set( ObjectType::Post, $id, 'field_t22d_seats', $seats );
		}
		$this->store->setRows( ObjectType::Post, $id, 'field_t22d_sessions', $rows );
		$this->pivot->sync( $this->pivot_id, $id, array( 'status' => 1 ) );
		return $id;
	}

	/**
	 * Snapshot of the set's pivot and row-pivot tables.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function snapshot(): array {
		$db = nf_db();
		return array(
			'pivot' => $db->select( 'SELECT * FROM ' . $db->table( 'nf_pivot' ) . ' WHERE pivot_id = ? ORDER BY object_id', array( $this->pivot_id ) ),
			'rows'  => $db->select( 'SELECT * FROM ' . $db->table( 'nf_row_pivot' ) . ' WHERE pivot_id = ? ORDER BY object_id, row_id', array( $this->pivot_id ) ),
		);
	}

	/**
	 * Chunks walk the set in id order and resume from "last".
	 */
	public function test_chunks_cover_the_set_once(): void {
		$a   = $this->make( 'Oslo', 1, array() );
		$b   = $this->make( 'Rome', 2, array() );
		$c   = $this->make( 'Bern', 3, array() );
		$one = $this->pivot->rebuildChunk( $this->pivot_id, 0, 2 );
		$this->assertSame( array( 2, $b ), array( $one['count'], $one['last'] ) );
		$two = $this->pivot->rebuildChunk( $this->pivot_id, $one['last'], 2 );
		$this->assertSame( array( 1, $c ), array( $two['count'], $two['last'] ) );
		$this->assertSame( 0, $this->pivot->rebuildChunk( $this->pivot_id, $two['last'], 2 )['count'] );
		$seen     = array();
		$progress = function ( int $n ) use ( &$seen ): void {
			$seen[] = $n;
		};
		$this->assertSame( 3, $this->pivot->rebuild( $this->pivot_id, 2, $progress ) );
		$this->assertSame( array( 2, 3 ), $seen );
		$this->assertSame( array( $a, $b, $c ), array_map( 'intval', array_column( $this->snapshot()['pivot'], 'object_id' ) ) );
	}

	/**
	 * Seeded fuzz: random writes, deletes and rewrites; a rebuild changes nothing and count rollups equal a live COUNT(*).
	 */
	public function test_fuzz_rebuild_equals_write_through(): void {
		mt_srand( 2204 );
		$cities = array( 'Oslo', 'Rome', 'Bern', 'Lima', 'Kyiv' );
		$ids    = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$rows = array();
			for ( $r = 0, $n = mt_rand( 0, 5 ); $r < $n; $r++ ) {
				$row = array( 'venue' => 'v' . mt_rand( 1, 6 ) );
				if ( mt_rand( 0, 3 ) ) {
					$row['cap'] = mt_rand( 1, 500 );
				}
				$rows[] = $row;
			}
			$ids[] = $this->make( mt_rand( 0, 4 ) ? $cities[ mt_rand( 0, 4 ) ] : null, mt_rand( 0, 3 ) ? mt_rand( 1, 99 ) : null, $rows );
		}
		// Rewrite some objects and remove others, each through write-through.
		foreach ( $ids as $k => $id ) {
			if ( 0 === $k % 5 ) {
				$this->store->deleteObject( ObjectType::Post, $id );
				$this->pivot->remove( $this->pivot_id, $id );
			} elseif ( 0 === $k % 3 ) {
				$one = array(
					array(
						'venue' => 'v' . mt_rand( 1, 6 ),
						'cap'   => mt_rand( 1, 500 ),
					),
				);
				$this->store->setRows( ObjectType::Post, $id, 'field_t22d_sessions', $one );
				$this->store->set( ObjectType::Post, $id, 'field_t22d_city', $cities[ mt_rand( 0, 4 ) ] );
				$this->pivot->sync( $this->pivot_id, $id, array( 'status' => 1 ) );
			}
		}
		$before = $this->snapshot();
		$db     = nf_db();
		// Corrupt the derived tables, then rebuild in small chunks: the result must equal the write-through state.
		$db->execute( 'UPDATE ' . $db->table( 'nf_pivot' ) . ' SET i1 = 12345, i2 = 777, i3 = 888 WHERE pivot_id = ?', array( $this->pivot_id ) );
		$db->execute( 'UPDATE ' . $db->table( 'nf_pivot' ) . ' SET s1 = NULL WHERE pivot_id = ? AND object_id % 2 = 0', array( $this->pivot_id ) );
		$db->execute( 'UPDATE ' . $db->table( 'nf_row_pivot' ) . ' SET i1 = 4242, s1 = NULL WHERE pivot_id = ?', array( $this->pivot_id ) );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_row_pivot' ) . ' WHERE pivot_id = ? AND row_id % 3 = 0', array( $this->pivot_id ) );
		$this->assertNotSame( $before, $this->snapshot() );
		$this->pivot->rebuild( $this->pivot_id, 7 );
		$this->assertSame( $before, $this->snapshot() );
		foreach ( $before['pivot'] as $p ) {
			$max = $db->select(
				'SELECT MAX(value) AS m FROM ' . $db->table( 'nf_int' ) . ' WHERE object_type = ? AND object_id = ? AND field_id = ? AND row_id <> 0',
				array( ObjectType::Post->value, (int) $p['object_id'], $this->fid['cap'] )
			);
			$this->assertSame( null === $max[0]['m'] ? null : (string) $max[0]['m'], null === $p['i3'] ? null : (string) $p['i3'], 'max rollup for object ' . $p['object_id'] );
		}
		foreach ( $before['pivot'] as $p ) {
			$live = $db->select(
				'SELECT COUNT(*) AS n FROM ' . $db->table( 'nf_int' ) . ' WHERE object_type = ? AND object_id = ? AND field_id = ? AND row_id <> 0',
				array( ObjectType::Post->value, (int) $p['object_id'], $this->fid['cap'] )
			);
			$this->assertSame( (string) $live[0]['n'], (string) ( $p['i2'] ?? '0' ), 'count rollup for object ' . $p['object_id'] );
		}
	}

	/**
	 * Row-pivot rows of a Series whose row slots were removed are dropped by a rebuild.
	 */
	public function test_rebuild_prunes_row_pivot_without_slots(): void {
		$rows = array(
			array(
				'venue' => 'a',
				'cap'   => 5,
			),
		);
		$this->make( 'Oslo', 1, $rows );
		$this->assertCount( 1, $this->snapshot()['rows'] );
		nf_db()->execute( 'DELETE FROM ' . nf_db()->table( 'nf_pivot_slots' ) . ' WHERE pivot_id = ? AND repeater_id > 0', array( $this->pivot_id ) );
		$this->pivot->rebuild( $this->pivot_id );
		$this->assertCount( 0, $this->snapshot()['rows'] );
	}

	/**
	 * Pruning removes unused string entries only and keeps referenced ones; ids are not reused.
	 */
	public function test_prune_dictionary_keeps_referenced_entries(): void {
		$id  = $this->make( 'Oslo', 1, array( array( 'venue' => 'Hall' ) ) );
		$dic = $this->pivot->dictionary();
		$old = $dic->id( $this->fid['city'], 'Gone' );
		$row = $dic->id( $this->fid['venue'], 'Unused' );
		$this->assertGreaterThanOrEqual( 2, $this->pivot->pruneDictionary() );
		$this->assertNull( $dic->find( $this->fid['city'], 'Gone' ) );
		$this->assertNull( $dic->find( $this->fid['venue'], 'Unused' ) );
		$this->assertNotNull( $dic->find( $this->fid['city'], 'Oslo' ) );
		$this->assertNotNull( $dic->find( $this->fid['venue'], 'Hall' ) );
		$this->assertGreaterThan( max( $old, $row ), $dic->id( $this->fid['city'], 'Gone' ) );
		$this->assertSame( 1, $this->pivot->pruneDictionary() );
		unset( $id );
	}

	/**
	 * Entries that carry a bitmask bit survive pruning.
	 */
	public function test_prune_keeps_bit_entries(): void {
		$dic = $this->pivot->dictionary();
		$bit = $dic->bit( $this->fid['city'], 'tagged' );
		$this->assertNotNull( $bit );
		$this->pivot->pruneDictionary();
		$this->assertSame( $bit, $dic->findBit( $this->fid['city'], 'tagged' ) );
	}

	/**
	 * Documented behaviour: lookups follow the nf_dict.value column collation. On a case-insensitive collation
	 * (the WordPress default) "Oslo" and "oslo" are one entry, as for a "=" match on wp_postmeta.
	 */
	public function test_dictionary_follows_column_collation(): void {
		$db  = nf_db();
		$col = $db->select(
			'SELECT COLLATION_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
			array( $db->table( 'nf_dict' ), 'value' )
		);
		$ci  = str_ends_with( strtolower( (string) $col[0]['c'] ), '_ci' );
		$dic = $this->pivot->dictionary();
		$a   = $dic->id( $this->fid['city'], 'Oslo' );
		$b   = $dic->id( $this->fid['city'], 'oslo' );
		if ( $ci ) {
			$this->assertSame( $a, $b );
			$this->assertSame( 'Oslo', $dic->value( $a ) );
		} else {
			$this->assertNotSame( $a, $b );
		}
	}
}
