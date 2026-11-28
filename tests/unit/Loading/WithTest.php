<?php
/**
 * Eager loading by dotted path (WP 3.5d2).
 *
 * @package NF
 */

use NF\Loading\FieldLoader;
use NF\Loading\Primer;
use NF\Loading\ResultSetRegistry;
use NF\Loading\With;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * With::run() walks relation paths and fills the loader before output.
 */
class WithTest extends WP_UnitTestCase {

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
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t35w%' ) );
		$fields->flush();
		$fields->register( 'field_t35w_venue', 'venue', DataTable::Int );
		$fields->register( 'field_t35w_city', 'city', DataTable::Str );
		$this->store = new Store( $db, $fields );
		add_filter(
			'nf/loading/describe_field',
			static function ( $field, array $q ) {
				$map = array(
					'venue' => array( 'key' => 'field_t35w_venue', 'type' => 'post_object' ),
					'city'  => array( 'key' => 'field_t35w_city', 'type' => 'text' ),
				);
				return $map[ $q['name'] ] ?? $field;
			},
			10,
			2
		);
	}

	/**
	 * Remove this test's rows.
	 */
	public function tear_down() {
		remove_all_filters( 'nf/loading/describe_field' );
		foreach ( array( 9201, 9202, 9301, 9302 ) as $id ) {
			$this->store->deleteObject( ObjectType::Post, $id );
		}
		parent::tear_down();
	}

	/**
	 * `venue.city` loads the venue for every card, then the city of those venues, so later reads cost nothing.
	 *
	 * @return void
	 */
	public function test_dotted_path_loads_related_fields_up_front(): void {
		global $wpdb;
		$this->store->set( ObjectType::Post, 9201, 'field_t35w_venue', 9301 );
		$this->store->set( ObjectType::Post, 9202, 'field_t35w_venue', 9302 );
		$this->store->set( ObjectType::Post, 9301, 'field_t35w_city', 'Oslo' );
		$this->store->set( ObjectType::Post, 9302, 'field_t35w_city', 'Bergen' );
		$registry = new ResultSetRegistry();
		$loader   = new FieldLoader( $this->store, $registry, new Primer() );
		( new With( $loader, new Primer(), $registry ) )->run( array( 9201, 9202 ), array( 'venue.city', 'nonsense.path' ) );
		$before = $wpdb->num_queries;
		$this->assertSame( 9301, $loader->get( 9201, 'field_t35w_venue' ) );
		$this->assertSame( 'Bergen', $loader->get( 9302, 'field_t35w_city' ) );
		$this->assertSame( 0, $wpdb->num_queries - $before );
	}

	/**
	 * The `nf_with` query var of a WP_Query runs the paths on its results.
	 *
	 * @return void
	 */
	public function test_nf_with_query_var(): void {
		$ids = self::factory()->post->create_many( 2 );
		$this->store->set( ObjectType::Post, $ids[0], 'field_t35w_city', 'Oslo' );
		$loader = new FieldLoader( $this->store, new ResultSetRegistry() );
		$with   = new With( $loader, new Primer(), new ResultSetRegistry() );
		$with->register();
		new WP_Query(
			array(
				'post__in' => $ids,
				'nf_with'  => array( 'city' ),
			)
		);
		remove_filter( 'the_posts', array( $with, 'onPosts' ), 10 );
		$this->assertTrue( $loader->has( $ids[0], 'field_t35w_city' ) );
		foreach ( $ids as $id ) {
			$this->store->deleteObject( ObjectType::Post, $id );
		}
	}
}
