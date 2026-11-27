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
		$fields->register( 'field_t35b_rel', 'related', DataTable::Int, true );
		$this->store = new Store( $db, $fields );
	}

	/**
	 * Remove this test's rows.
	 */
	public function tear_down() {
		foreach ( array( 9101, 9102, 9103, 9111, 9112, 9113, 9121 ) as $id ) {
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
}
