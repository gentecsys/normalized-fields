<?php
/**
 * Row pivot and rollups (WP 2.2c).
 *
 * @package NF
 */

use NF\Pivot\Pivot;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * One nf_row_pivot row per Series row; min / max / count rollups; rebuild equals write-through.
 */
class RowPivotTest extends WP_UnitTestCase {

	private const TYPE = 'nf_rowp_event';

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
	 * Series field id.
	 *
	 * @var int
	 */
	private int $series_id;

	/**
	 * Post IDs created.
	 *
	 * @var int[]
	 */
	private array $ids = array();

	/**
	 * Fields, set and slots: sessions(date, venue, seats) with row slots t1, s1, i1 and rollups min t1, max t2, count i1.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		register_post_type( self::TYPE );
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t22c%' ) );
		$fields = new FieldMap( $db );
		$fields->flush();
		$series          = $fields->register( 'field_t22c_sessions', 'sessions', DataTable::Rows );
		$this->series_id = $series->id;
		$fields->register( 'field_t22c_date', 'date', DataTable::Date, false, $series->id );
		$fields->register( 'field_t22c_venue', 'venue', DataTable::Str, false, $series->id );
		$fields->register( 'field_t22c_seats', 'seats', DataTable::Int, false, $series->id );
		$fields->register( 'field_t22c_tags', 'tags', DataTable::Str, true, $series->id );
		$this->store    = new Store( $db, $fields );
		$this->pivot    = new Pivot( $db, $fields, $this->store );
		$this->pivot_id = $this->pivot->setId( ObjectType::Post, self::TYPE );
		$this->clean();
		$rp = $this->pivot->rows();
		$rp->assignSlot( $this->pivot_id, 'field_t22c_sessions', 'field_t22c_date', 't1' );
		$rp->assignSlot( $this->pivot_id, 'field_t22c_sessions', 'field_t22c_venue', 's1' );
		$rp->assignSlot( $this->pivot_id, 'field_t22c_sessions', 'field_t22c_seats', 'i1' );
		$rp->assignRollup( $this->pivot_id, 'field_t22c_sessions', 'field_t22c_date', 't1', 'min' );
		$rp->assignRollup( $this->pivot_id, 'field_t22c_sessions', 'field_t22c_date', 't2', 'max' );
		$rp->assignRollup( $this->pivot_id, 'field_t22c_sessions', 'field_t22c_seats', 'i1', 'count' );
	}

	/**
	 * Remove stored values, rows and pivot data.
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
	 * Delete pivot rows, row pivot rows and slots of the test set.
	 */
	private function clean(): void {
		$db = nf_db();
		foreach ( array( 'nf_pivot', 'nf_row_pivot', 'nf_pivot_slots' ) as $t ) {
			$db->execute( 'DELETE FROM ' . $db->table( $t ) . ' WHERE pivot_id = ?', array( $this->pivot_id ) );
		}
	}

	/**
	 * Create an event with Series rows and sync its pivot.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return int
	 */
	private function event( array $rows ): int {
		$id          = self::factory()->post->create( array( 'post_type' => self::TYPE ) );
		$this->ids[] = $id;
		$this->store->setRows( ObjectType::Post, $id, 'field_t22c_sessions', $rows );
		$this->pivot->sync( $this->pivot_id, $id, array( 'status' => 1 ) );
		return $id;
	}

	/**
	 * Row-pivot rows of an object in row id order.
	 *
	 * @param int $id Object id.
	 * @return array<int,array<string,mixed>>
	 */
	private function rowRows( int $id ): array {
		return nf_db()->select( 'SELECT row_id, field_id, t1, s1, i1, i2 FROM ' . nf_db()->table( 'nf_row_pivot' ) . ' WHERE pivot_id = ? AND object_id = ? ORDER BY row_id', array( $this->pivot_id, $id ) );
	}

	/**
	 * Rollup columns of an object's pivot row.
	 *
	 * @param int $id Object id.
	 * @return array<string,mixed>
	 */
	private function rollups( int $id ): array {
		$r = nf_db()->select( 'SELECT t1, t2, i1 FROM ' . nf_db()->table( 'nf_pivot' ) . ' WHERE pivot_id = ? AND object_id = ?', array( $this->pivot_id, $id ) );
		return $r[0];
	}

	/**
	 * Sample rows.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function sessions(): array {
		return array(
			array(
				'date'  => '2026-11-05 19:00:00',
				'venue' => 'Hall',
				'seats' => 100,
			),
			array(
				'date'  => '2026-11-03 10:00:00',
				'venue' => 'Park',
			),
			array(
				'date'  => '2026-11-09 20:30:00',
				'venue' => 'Hall',
				'seats' => 40,
			),
		);
	}

	/**
	 * One row-pivot row per Series row, slots filled, missing values NULL, same venue same dictionary id.
	 */
	public function test_one_row_per_series_row(): void {
		$id   = $this->event( $this->sessions() );
		$rows = $this->rowRows( $id );
		$this->assertCount( 3, $rows );
		$this->assertSame( '2026-11-05 19:00:00', $rows[0]['t1'] );
		$this->assertSame( '2026-11-03 10:00:00', $rows[1]['t1'] );
		$this->assertSame( '100', (string) $rows[0]['i1'] );
		$this->assertNull( $rows[1]['i1'] );
		$this->assertSame( $rows[0]['s1'], $rows[2]['s1'] );
		$this->assertNotSame( $rows[0]['s1'], $rows[1]['s1'] );
		$this->assertSame( (string) $this->series_id, (string) nf_db()->select( 'SELECT field_id FROM ' . nf_db()->table( 'nf_row_pivot' ) . ' WHERE object_id = ? LIMIT 1', array( $id ) )[0]['field_id'] );
	}

	/**
	 * Min, max and count land in the object pivot row; no rows gives NULL dates and a count of 0.
	 */
	public function test_rollups(): void {
		$id = $this->event( $this->sessions() );
		$r  = $this->rollups( $id );
		$this->assertSame( '2026-11-03 10:00:00', $r['t1'] );
		$this->assertSame( '2026-11-09 20:30:00', $r['t2'] );
		$this->assertSame( '2', (string) $r['i1'] );
		$empty = $this->event( array() );
		$r     = $this->rollups( $empty );
		$this->assertNull( $r['t1'] );
		$this->assertNull( $r['t2'] );
		$this->assertSame( '0', (string) $r['i1'] );
		$this->assertCount( 0, $this->rowRows( $empty ) );
	}

	/**
	 * Rewriting the rows replaces row-pivot rows and rollups; remove() clears them.
	 */
	public function test_update_and_remove(): void {
		$id = $this->event( $this->sessions() );
		$this->store->setRows( ObjectType::Post, $id, 'field_t22c_sessions', array( array( 'date' => '2027-01-01 00:00:00' ) ) );
		$this->pivot->sync( $this->pivot_id, $id, array( 'status' => 1 ) );
		$this->assertCount( 1, $this->rowRows( $id ) );
		$r = $this->rollups( $id );
		$this->assertSame( '2027-01-01 00:00:00', $r['t1'] );
		$this->assertSame( $r['t1'], $r['t2'] );
		$this->assertSame( '0', (string) $r['i1'] );
		$this->pivot->remove( $this->pivot_id, $id );
		$this->assertCount( 0, $this->rowRows( $id ) );
	}

	/**
	 * Rebuild reproduces row-pivot rows and rollups exactly, and rollups equal live counts of the typed tables.
	 */
	public function test_rebuild_equals_write_through_and_live_counts(): void {
		$a = $this->event( $this->sessions() );
		$this->event( array( array( 'seats' => 5 ) ) );
		$this->event( array() );
		$q1     = 'SELECT object_id, row_id, field_id, t1, s1, i1 FROM ' . nf_db()->table( 'nf_row_pivot' ) . ' WHERE pivot_id = ? ORDER BY row_id';
		$q2     = 'SELECT object_id, status, t1, t2, i1 FROM ' . nf_db()->table( 'nf_pivot' ) . ' WHERE pivot_id = ? ORDER BY object_id';
		$rows   = nf_db()->select( $q1, array( $this->pivot_id ) );
		$pivots = nf_db()->select( $q2, array( $this->pivot_id ) );
		$this->assertSame( 4, count( $rows ) );
		$this->assertSame( 3, $this->pivot->rebuild( $this->pivot_id ) );
		$this->assertSame( $rows, nf_db()->select( $q1, array( $this->pivot_id ) ) );
		$this->assertSame( $pivots, nf_db()->select( $q2, array( $this->pivot_id ) ) );
		$live = nf_db()->select(
			'SELECT COUNT(*) AS n FROM ' . nf_db()->table( 'nf_int' ) . ' WHERE object_type = ? AND object_id = ? AND field_id = ? AND row_id <> 0',
			array( ObjectType::Post->value, $a, (int) nf_db()->select( 'SELECT field_id FROM ' . nf_db()->table( 'nf_fields' ) . ' WHERE field_key = ?', array( 'field_t22c_seats' ) )[0]['field_id'] )
		);
		$this->assertSame( (string) $live[0]['n'], (string) $this->rollups( $a )['i1'] );
	}

	/**
	 * Multi-valued, wrong-type and out-of-range assignments are refused.
	 */
	public function test_assignment_rules(): void {
		$rp = $this->pivot->rows();
		foreach ( array(
			array( 'field_t22c_tags', 't2', null ),   // Multi-valued sub-field.
			array( 'field_t22c_date', 's2', null ),   // Wrong letter.
			array( 'field_t22c_seats', 'i5', null ),  // Past the row-pivot columns.
			array( 'field_t22c_venue', 's2', 'min' ), // Strings do not roll up min / max.
			array( 'field_t22c_seats', 'i2', 'sum' ), // Unknown mode.
		) as list( $sub, $slot, $mode ) ) {
			try {
				null === $mode ? $rp->assignSlot( $this->pivot_id, 'field_t22c_sessions', $sub, $slot ) : $rp->assignRollup( $this->pivot_id, 'field_t22c_sessions', $sub, $slot, $mode );
				$this->fail( "{$sub} {$slot} should be refused" );
			} catch ( InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}
}
