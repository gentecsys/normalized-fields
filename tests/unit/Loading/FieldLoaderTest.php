<?php
/**
 * Batch read and lazy loader (WP 3.5b).
 *
 * @package NF
 */

use NF\Loading\FieldLoader;
use NF\Loading\ResultSetRegistry;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * Store::getMany() matches get(); the loader batches a set in one query per table.
 */
class FieldLoaderTest extends WP_UnitTestCase {

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Set up tables and fields.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		$db     = nf_db();
		$fields = new FieldMap( $db );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t35b%' ) );
		$fields->flush();
		$fields->register( 'field_t35b_title', 'title', DataTable::Str );
		$fields->register( 'field_t35b_cap', 'cap', DataTable::Int );
		$fields->register( 'field_t35b_related', 'related', DataTable::Int, true );
		$series = $fields->register( 'field_t35d_rows', 'rows', DataTable::Rows );
		$fields->register( 'field_t35d_rows_name', 'name', DataTable::Str, false, $series->id );
		$fields->register( 'field_t35d_rows_note', 'note', DataTable::Str, false, $series->id );
		$this->store = new Store( $db, $fields );
	}

	/**
	 * Remove this test's rows.
	 */
	public function tear_down() {
		foreach ( array( 9101, 9102, 9103, 9111, 9112, 9113, 9121, 9131, 9132, 9133 ) as $id ) {
			$this->store->deleteObject( ObjectType::Post, $id );
		}
		parent::tear_down();
	}

	/**
	 * Count queries run by a callback.
	 *
	 * @param callable $callback Callback.
	 * @return int
	 */
	private function queries( callable $callback ): int {
		global $wpdb;
		$before = $wpdb->num_queries;
		$callback();
		return $wpdb->num_queries - $before;
	}

	/**
	 * The batch read returns what get() returns, including unset fields and long text.
	 *
	 * @return void
	 */
	public function test_get_many_matches_get(): void {
		$long = str_repeat( 'x', 300 );
		$this->store->set( ObjectType::Post, 9101, 'field_t35b_title', 'one' );
		$this->store->set( ObjectType::Post, 9102, 'field_t35b_title', $long );
		$this->store->set( ObjectType::Post, 9101, 'field_t35b_related', array( 5, 6 ) );
		foreach ( array( 'field_t35b_title', 'field_t35b_related', 'field_t35b_cap' ) as $key ) {
			$many = $this->store->getMany( ObjectType::Post, array( 9101, 9102, 9103 ), $key );
			foreach ( array( 9101, 9102, 9103 ) as $id ) {
				$this->assertSame( $this->store->get( ObjectType::Post, $id, $key ), $many[ $id ], "$key $id" );
			}
		}
	}

	/**
	 * Loading a field for a set costs the same queries for 3 posts as for 1.
	 *
	 * @return void
	 */
	public function test_set_loads_in_constant_queries(): void {
		foreach ( array( 9111, 9112, 9113 ) as $id ) {
			$this->store->set( ObjectType::Post, $id, 'field_t35b_cap', $id );
		}
		$registry = new ResultSetRegistry();
		$registry->register( array( 9111, 9112, 9113 ) );
		$loader = new FieldLoader( $this->store, $registry );
		$n      = $this->queries( fn() => $loader->get( 9111, 'field_t35b_cap' ) );
		$this->assertSame( 1, $n );
		$this->assertSame( 0, $this->queries( fn() => $loader->get( 9112, 'field_t35b_cap' ) ) );
		$this->assertSame( 9113, $loader->get( 9113, 'field_t35b_cap' ) );
		$this->assertTrue( $registry->isLoaded( 'field_t35b_cap', 9112 ) );
	}

	/**
	 * A post in no set loads alone; forget() reloads.
	 *
	 * @return void
	 */
	public function test_lone_post_and_forget(): void {
		$this->store->set( ObjectType::Post, 9121, 'field_t35b_cap', 1 );
		$loader = new FieldLoader( $this->store, new ResultSetRegistry() );
		$this->assertSame( 1, $loader->get( 9121, 'field_t35b_cap' ) );
		$this->store->set( ObjectType::Post, 9121, 'field_t35b_cap', 2 );
		$this->assertSame( 1, $loader->get( 9121, 'field_t35b_cap' ), 'Held for the request.' );
		$loader->forget( 9121 );
		$this->assertSame( 2, $loader->get( 9121, 'field_t35b_cap' ) );
	}

	/**
	 * A store write drops the held value, so a read after a write in the same request is fresh.
	 *
	 * @return void
	 */
	public function test_write_then_read_returns_the_new_value(): void {
		$this->store->set( ObjectType::Post, 9121, 'field_t35b_cap', 1 );
		$loader = new FieldLoader( $this->store, new ResultSetRegistry() );
		$loader->register();
		$this->assertSame( 1, $loader->get( 9121, 'field_t35b_cap' ) );
		$this->store->set( ObjectType::Post, 9121, 'field_t35b_cap', 2 );
		$this->assertSame( 2, $loader->get( 9121, 'field_t35b_cap' ) );
		$this->store->delete( ObjectType::Post, 9121, 'field_t35b_cap' );
		$this->assertNull( $loader->get( 9121, 'field_t35b_cap' ) );
		$this->store->set( ObjectType::Post, 9121, 'field_t35b_cap', 3 );
		$this->assertSame( 3, $loader->get( 9121, 'field_t35b_cap' ) );
		$this->store->deleteObject( ObjectType::Post, 9121 );
		$this->assertNull( $loader->get( 9121, 'field_t35b_cap' ) );
		remove_action( 'nf/store/changed', array( $loader, 'onStoreChanged' ), 10 );
		remove_action( 'switch_blog', array( $loader, 'onSwitchBlog' ) );
		remove_action( 'nf/loading/flush', array( $loader, 'flush' ) );
	}

	/**
	 * Values do not survive a site switch.
	 *
	 * @return void
	 */
	public function test_switch_blog_flushes_values(): void {
		$this->store->set( ObjectType::Post, 9121, 'field_t35b_cap', 1 );
		$loader = new FieldLoader( $this->store, new ResultSetRegistry() );
		$loader->register();
		$loader->get( 9121, 'field_t35b_cap' );
		$this->assertTrue( $loader->has( 9121, 'field_t35b_cap' ) );
		do_action( 'switch_blog', 1, 1 );
		$this->assertFalse( $loader->has( 9121, 'field_t35b_cap' ) );
		remove_action( 'nf/store/changed', array( $loader, 'onStoreChanged' ), 10 );
		remove_action( 'switch_blog', array( $loader, 'onSwitchBlog' ) );
		remove_action( 'nf/loading/flush', array( $loader, 'flush' ) );
	}

	/**
	 * An unknown key returns null instead of throwing.
	 *
	 * @return void
	 */
	public function test_unknown_key_returns_null(): void {
		$loader = new FieldLoader( $this->store, new ResultSetRegistry() );
		$this->assertNull( $loader->get( 9121, 'field_t35b_missing' ) );
	}

	/**
	 * Series rows for a set match getRows() (long text included) and cost the same queries for 3 posts as for 1.
	 *
	 * @return void
	 */
	public function test_rows_many_match_get_rows_in_constant_queries(): void {
		$long = str_repeat( 'y', 300 );
		$this->store->setRows( ObjectType::Post, 9131, 'field_t35d_rows', array( array( 'name' => 'a', 'note' => $long ), array( 'name' => 'b', 'note' => null ) ) );
		$this->store->setRows( ObjectType::Post, 9132, 'field_t35d_rows', array( array( 'name' => 'c', 'note' => 'n' ) ) );
		$many = $this->store->getRowsMany( ObjectType::Post, array( 9131, 9132, 9133 ), 'field_t35d_rows' );
		foreach ( array( 9131, 9132, 9133 ) as $id ) {
			$this->assertSame( $this->store->getRows( ObjectType::Post, $id, 'field_t35d_rows' ), $many[ $id ], (string) $id );
		}
		$registry = new ResultSetRegistry();
		$registry->register( array( 9131, 9132, 9133 ) );
		$loader = new FieldLoader( $this->store, $registry );
		$first  = $this->queries( fn() => $loader->rows( 9131, 'field_t35d_rows' ) );
		$this->assertLessThanOrEqual( 5, $first );
		$this->assertSame( 0, $this->queries( fn() => $loader->rows( 9132, 'field_t35d_rows' ) ) );
		$this->assertSame( 'c', $loader->rows( 9132, 'field_t35d_rows' )[0]['name'] );
		$this->assertSame( array(), $loader->rows( 9133, 'field_t35d_rows' ) );
		$this->assertSame( array(), $loader->rows( 9133, 'field_t35d_missing' ) );
	}

	/**
	 * A write drops the held Series rows.
	 *
	 * @return void
	 */
	public function test_rows_are_fresh_after_a_write(): void {
		$loader = new FieldLoader( $this->store, new ResultSetRegistry() );
		$loader->register();
		$this->store->setRows( ObjectType::Post, 9131, 'field_t35d_rows', array( array( 'name' => 'a', 'note' => null ) ) );
		$this->assertSame( 'a', $loader->rows( 9131, 'field_t35d_rows' )[0]['name'] );
		$this->store->setRows( ObjectType::Post, 9131, 'field_t35d_rows', array( array( 'name' => 'z', 'note' => null ) ) );
		$this->assertSame( 'z', $loader->rows( 9131, 'field_t35d_rows' )[0]['name'] );
		remove_action( 'nf/store/changed', array( $loader, 'onStoreChanged' ), 10 );
		remove_action( 'switch_blog', array( $loader, 'onSwitchBlog' ) );
		remove_action( 'nf/loading/flush', array( $loader, 'flush' ) );
	}
}
