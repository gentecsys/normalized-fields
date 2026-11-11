<?php
/**
 * Store round-trip tests (WP 1.1): scalar, relationship (multi) and Series.
 *
 * @package NF
 */

use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * Write, read, update, delete against the real database.
 */
class StoreTest extends WP_UnitTestCase {

	/**
	 * Store under test.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Field map.
	 *
	 * @var FieldMap
	 */
	private FieldMap $fields;

	/**
	 * Create tables (DDL commits implicitly, so do it before the test transaction matters) and register fields.
	 */
	public function set_up() {
		parent::set_up();
		$this->remove_temporary_table_filters();
		NF\Install\Schema::install();
		$db           = nf_db();
		$this->fields = new FieldMap( $db );
		$this->store  = new Store( $db, $this->fields );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t11%' ) );
		$this->fields->flush();
		$this->fields->register( 'field_t11_title', 'title', DataTable::Str );
		$this->fields->register( 'field_t11_price', 'price', DataTable::Dec );
		$this->fields->register( 'field_t11_start', 'start', DataTable::Date );
		$this->fields->register( 'field_t11_cap', 'cap', DataTable::Int );
		$this->fields->register( 'field_t11_body', 'body', DataTable::Text );
		$this->fields->register( 'field_t11_meta', 'meta', DataTable::Json );
		$this->fields->register( 'field_t11_rel', 'related', DataTable::Int, true );
		$s = $this->fields->register( 'field_t11_sessions', 'sessions', DataTable::Rows );
		$this->fields->register( 'field_t11_s_name', 'name', DataTable::Str, false, $s->id );
		$this->fields->register( 'field_t11_s_len', 'length', DataTable::Int, false, $s->id );
		$this->fields->register( 'field_t11_s_spk', 'speakers', DataTable::Int, true, $s->id );
	}

	/**
	 * Remove the temp-table filters so DDL and rows persist across the test (tables are cleaned explicitly).
	 */
	private function remove_temporary_table_filters(): void {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
	}

	/**
	 * Clean up this test's rows.
	 */
	public function tear_down() {
		$this->store->deleteObject( ObjectType::Post, 101 );
		$this->store->deleteObject( ObjectType::Post, 102 );
		$this->store->deleteObject( ObjectType::Term, 101 );
		parent::tear_down();
	}

	/**
	 * Every scalar type round-trips, updates and deletes.
	 */
	public function test_scalar_round_trip() {
		$this->store->set( ObjectType::Post, 101, 'field_t11_title', 'Hello' );
		$this->store->set( ObjectType::Post, 101, 'field_t11_price', '12.5' );
		$this->store->set( ObjectType::Post, 101, 'field_t11_start', '2026-03-01 10:00:00' );
		$this->store->set( ObjectType::Post, 101, 'field_t11_cap', 42 );
		$this->store->set( ObjectType::Post, 101, 'field_t11_body', str_repeat( 'x', 1000 ) );
		$this->store->set( ObjectType::Post, 101, 'field_t11_meta', array( 'a' => array( 1, 2 ) ) );

		$this->assertSame( 'Hello', $this->store->get( ObjectType::Post, 101, 'field_t11_title' ) );
		$this->assertEquals( 12.5, $this->store->get( ObjectType::Post, 101, 'field_t11_price' ) );
		$this->assertSame( '2026-03-01 10:00:00', $this->store->get( ObjectType::Post, 101, 'field_t11_start' ) );
		$this->assertSame( 42, $this->store->get( ObjectType::Post, 101, 'field_t11_cap' ) );
		$this->assertSame( 1000, strlen( $this->store->get( ObjectType::Post, 101, 'field_t11_body' ) ) );
		$this->assertSame( array( 'a' => array( 1, 2 ) ), $this->store->get( ObjectType::Post, 101, 'field_t11_meta' ) );

		$this->store->set( ObjectType::Post, 101, 'field_t11_title', 'Changed' );
		$this->assertSame( 'Changed', $this->store->get( ObjectType::Post, 101, 'field_t11_title' ) );

		$this->store->delete( ObjectType::Post, 101, 'field_t11_title' );
		$this->assertNull( $this->store->get( ObjectType::Post, 101, 'field_t11_title' ) );
		$this->assertSame( 42, $this->store->get( ObjectType::Post, 101, 'field_t11_cap' ) );
	}

	/**
	 * Values are isolated per object and object type.
	 */
	public function test_isolation() {
		$this->store->set( ObjectType::Post, 101, 'field_t11_cap', 1 );
		$this->store->set( ObjectType::Post, 102, 'field_t11_cap', 2 );
		$this->store->set( ObjectType::Term, 101, 'field_t11_cap', 3 );
		$this->assertSame( 1, $this->store->get( ObjectType::Post, 101, 'field_t11_cap' ) );
		$this->assertSame( 2, $this->store->get( ObjectType::Post, 102, 'field_t11_cap' ) );
		$this->assertSame( 3, $this->store->get( ObjectType::Term, 101, 'field_t11_cap' ) );
		$this->store->deleteObject( ObjectType::Post, 101 );
		$this->assertNull( $this->store->get( ObjectType::Post, 101, 'field_t11_cap' ) );
		$this->assertSame( 2, $this->store->get( ObjectType::Post, 102, 'field_t11_cap' ) );
	}

	/**
	 * A relationship keeps order and duplicates; update replaces; empty clears.
	 */
	public function test_relationship_order() {
		$this->store->set( ObjectType::Post, 101, 'field_t11_rel', array( 9, 3, 7, 3 ) );
		$this->assertSame( array( 9, 3, 7, 3 ), $this->store->get( ObjectType::Post, 101, 'field_t11_rel' ) );
		$this->store->set( ObjectType::Post, 101, 'field_t11_rel', array( 5 ) );
		$this->assertSame( array( 5 ), $this->store->get( ObjectType::Post, 101, 'field_t11_rel' ) );
		$this->store->set( ObjectType::Post, 101, 'field_t11_rel', array() );
		$this->assertSame( array(), $this->store->get( ObjectType::Post, 101, 'field_t11_rel' ) );
	}

	/**
	 * Series rows keep order, sub-field values and multi sub-fields; update replaces; delete cleans values.
	 */
	public function test_series_round_trip() {
		$rows = array(
			array(
				'name'     => 'Keynote',
				'length'   => 60,
				'speakers' => array( 4, 2 ),
			),
			array(
				'name'     => 'Panel',
				'length'   => 45,
				'speakers' => array(),
			),
			array(
				'name'     => 'Break',
				'length'   => null,
				'speakers' => array( 8 ),
			),
		);
		$this->store->setRows( ObjectType::Post, 101, 'field_t11_sessions', $rows );
		$this->assertSame( $rows, $this->store->getRows( ObjectType::Post, 101, 'field_t11_sessions' ) );

		$this->store->setRows( ObjectType::Post, 101, 'field_t11_sessions', array_reverse( $rows ) );
		$got = $this->store->getRows( ObjectType::Post, 101, 'field_t11_sessions' );
		$this->assertSame( 'Break', $got[0]['name'] );
		$this->assertCount( 3, $got );

		$db = nf_db();
		$this->store->delete( ObjectType::Post, 101, 'field_t11_sessions' );
		$this->assertSame( array(), $this->store->getRows( ObjectType::Post, 101, 'field_t11_sessions' ) );
		foreach ( array( 'nf_rows', 'nf_str', 'nf_int' ) as $t ) {
			$n = $db->select( 'SELECT COUNT(*) AS n FROM ' . $db->table( $t ) . ' WHERE object_type = ? AND object_id = ?', array( 1, 101 ) );
			$this->assertSame( '0', (string) $n[0]['n'], $t );
		}
	}

	/**
	 * A failed write rolls the whole Series back.
	 */
	public function test_series_write_is_atomic() {
		$this->store->setRows(
			ObjectType::Post,
			101,
			'field_t11_sessions',
			array(
				array(
					'name'     => 'Keep',
					'length'   => 1,
					'speakers' => array(),
				),
			)
		);
		try {
			$this->store->setRows( ObjectType::Post, 101, 'field_t11_sessions', array( array( 'name' => 'New' ), array( 'nope' => 1 ) ) );
			$this->fail( 'Expected an exception.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'Unknown Series sub-field', $e->getMessage() );
		}
		$got = $this->store->getRows( ObjectType::Post, 101, 'field_t11_sessions' );
		$this->assertSame( 'Keep', $got[0]['name'] );
	}

	/**
	 * Bad values and unknown fields are refused.
	 */
	public function test_validation() {
		$this->expectException( InvalidArgumentException::class );
		$this->store->set( ObjectType::Post, 101, 'field_t11_cap', 'not a number' );
	}

	/**
	 * A value over 191 chars keeps a prefix in nf_str and the full value in nf_text; update and delete clean both.
	 */
	public function test_long_string_spills_to_text() {
		$db   = nf_db();
		$long = str_repeat( 'é', 300 );
		$this->store->set( ObjectType::Post, 101, 'field_t11_title', $long );
		$this->assertSame( $long, $this->store->get( ObjectType::Post, 101, 'field_t11_title' ) );
		$prefix = $db->select( 'SELECT value FROM ' . $db->table( 'nf_str' ) . ' WHERE object_type = ? AND object_id = ? AND field_id = ?', array( ObjectType::Post->value, 101, $this->fields->byKey( 'field_t11_title' )->id ) );
		$this->assertSame( str_repeat( 'é', 191 ), $prefix[0]['value'] );

		$exact = str_repeat( 'x', 191 );
		$this->store->set( ObjectType::Post, 101, 'field_t11_title', $exact );
		$this->assertSame( $exact, $this->store->get( ObjectType::Post, 101, 'field_t11_title' ) );
		$this->assertSame( 0, $this->textRows( 101 ) );

		$this->store->set( ObjectType::Post, 101, 'field_t11_title', $long );
		$this->assertSame( 1, $this->textRows( 101 ) );
		$this->store->set( ObjectType::Post, 101, 'field_t11_title', 'short' );
		$this->assertSame( 0, $this->textRows( 101 ) );
		$this->store->set( ObjectType::Post, 101, 'field_t11_title', $long );
		$this->store->deleteObject( ObjectType::Post, 101 );
		$this->assertSame( 0, $this->textRows( 101 ) );
	}

	/**
	 * A long string inside a Series row spills too, and is removed with the Series.
	 */
	public function test_long_string_in_series_row() {
		$long = str_repeat( 'z', 250 );
		$this->store->setRows(
			ObjectType::Post,
			101,
			'field_t11_sessions',
			array(
				array(
					'name'   => $long,
					'length' => 5,
				),
			)
		);
		$rows = $this->store->getRows( ObjectType::Post, 101, 'field_t11_sessions' );
		$this->assertSame( $long, $rows[0]['name'] );
		$this->assertSame( 1, $this->textRows( 101 ) );
		$this->store->delete( ObjectType::Post, 101, 'field_t11_sessions' );
		$this->assertSame( 0, $this->textRows( 101 ) );
	}

	/**
	 * Count nf_text rows of a post.
	 *
	 * @param int $id Post ID.
	 * @return int
	 */
	private function textRows( int $id ): int {
		$db  = nf_db();
		$row = $db->select( 'SELECT COUNT(*) AS c FROM ' . $db->table( 'nf_text' ) . ' WHERE object_type = ? AND object_id = ?', array( ObjectType::Post->value, $id ) );
		return (int) $row[0]['c'];
	}

	/**
	 * A value that cannot be JSON-encoded is refused, not stored as an empty string.
	 */
	public function test_json_encode_failure_throws() {
		$this->expectException( InvalidArgumentException::class );
		$this->store->set( ObjectType::Post, 101, 'field_t11_meta', NAN );
	}

	/**
	 * Dialect output is explicit.
	 */
	public function test_dialect() {
		$d = nf_db()->dialect();
		$this->assertSame( 'LIMIT 10 OFFSET 20', $d->limit( 10, 20 ) );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $d->upsert( 'wp_nf_int', array( 'object_id' ), array( 'value' ) ) );
		$this->assertSame( '`c` IS NULL ASC, `c` DESC', $d->nullsOrder( 'c', 'DESC', 'LAST' ) );
	}

	/**
	 * Multi-valued strings (checkbox, multi-select) keep order; oEmbed-style text round-trips.
	 */
	public function test_multi_str_and_text_round_trip() {
		$this->fields->register( 'field_t11_chk', 'chk', DataTable::Str, true );
		$this->fields->register( 'field_t11_embed', 'embed', DataTable::Text );
		$this->store->set( ObjectType::Post, 101, 'field_t11_chk', array( 'b', 'a', 'c' ) );
		$this->assertSame( array( 'b', 'a', 'c' ), $this->store->get( ObjectType::Post, 101, 'field_t11_chk' ) );
		$this->store->set( ObjectType::Post, 101, 'field_t11_chk', array( 'z' ) );
		$this->assertSame( array( 'z' ), $this->store->get( ObjectType::Post, 101, 'field_t11_chk' ) );
		$this->store->set( ObjectType::Post, 101, 'field_t11_chk', null );
		$this->assertSame( array(), $this->store->get( ObjectType::Post, 101, 'field_t11_chk' ) );
		$url = 'https://www.youtube.com/watch?v=' . str_repeat( 'x', 300 );
		$this->store->set( ObjectType::Post, 101, 'field_t11_embed', $url );
		$this->assertSame( $url, $this->store->get( ObjectType::Post, 101, 'field_t11_embed' ) );
	}
}
