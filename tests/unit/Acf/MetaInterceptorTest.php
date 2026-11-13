<?php
/**
 * Meta API interception tests (WP 2.4).
 *
 * @package NF
 */

use NF\Acf\MetaInterceptor;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * Core meta API answered from NF tables.
 */
class MetaInterceptorTest extends WP_UnitTestCase {

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Post ID.
	 *
	 * @var int
	 */
	private int $post;

	/**
	 * Create tables, fields and the interceptor.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		update_option( NF\Install\Upgrader::OPTION, NF\Install\Upgrader::SCHEMA_VERSION );
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t24%' ) );
		$fields      = new FieldMap( $db );
		$this->store = new Store( $db, $fields );
		$fields->register( 'field_t24a', 't24_price', DataTable::Int );
		$fields->register( 'field_t24b', 't24_tags', DataTable::Str, true );
		$fields->register( 'field_t24s', 't24_series', DataTable::Rows );
		( new MetaInterceptor( $this->store, $db, static fn(): bool => true ) )->register();
		$this->post = self::factory()->post->create();
	}

	/**
	 * Clean up.
	 */
	public function tear_down() {
		$this->store->deleteObject( ObjectType::Post, $this->post );
		parent::tear_down();
	}

	/**
	 * Scalar write and read go to NF, not wp_postmeta.
	 */
	public function test_scalar_round_trip(): void {
		update_post_meta( $this->post, 't24_price', '42' );
		$this->assertSame( '42', get_post_meta( $this->post, 't24_price', true ) );
		$this->assertSame( 42, $this->store->get( ObjectType::Post, $this->post, 'field_t24a' ) );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test asserts nothing reached core meta.
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", 't24_price' ) ) );
	}

	/**
	 * Multi-valued fields keep their list; add refuses a second scalar value.
	 */
	public function test_multi_and_add(): void {
		update_post_meta( $this->post, 't24_tags', array( 'a', 'b' ) );
		$this->assertSame( array( 'a', 'b' ), get_post_meta( $this->post, 't24_tags', true ) );
		add_post_meta( $this->post, 't24_price', '1', true );
		$this->assertFalse( add_post_meta( $this->post, 't24_price', '2', true ) );
	}

	/**
	 * Reference rows are discarded on write and read back from the field map.
	 */
	public function test_reference_rows(): void {
		update_post_meta( $this->post, 't24_price', '5' );
		update_post_meta( $this->post, '_t24_price', 'field_other' );
		$this->assertSame( 'field_t24a', get_post_meta( $this->post, '_t24_price', true ) );
	}

	/**
	 * A Series reference row does not throw and falls through to core.
	 */
	public function test_series_reference_row_falls_through(): void {
		$this->assertSame( '', get_post_meta( $this->post, '_t24_series', true ) );
	}

	/**
	 * Delete removes the value.
	 */
	public function test_delete(): void {
		update_post_meta( $this->post, 't24_price', '5' );
		delete_post_meta( $this->post, 't24_price' );
		$this->assertNull( $this->store->get( ObjectType::Post, $this->post, 'field_t24a' ) );
		$this->assertSame( '', get_post_meta( $this->post, 't24_price', true ) );
	}

	/**
	 * Full-array reads include NF fields, so duplicate tools copy them.
	 */
	public function test_full_array_read_and_copy(): void {
		update_post_meta( $this->post, 't24_price', '9' );
		update_post_meta( $this->post, 't24_tags', array( 'x' ) );
		$all = get_post_meta( $this->post );
		$this->assertSame( array( '9' ), $all['t24_price'] );
		$this->assertSame( array( array( 'x' ) ), $all['t24_tags'] );
		$copy = self::factory()->post->create();
		foreach ( $all as $k => $vals ) {
			foreach ( $vals as $v ) {
				add_post_meta( $copy, $k, $v );
			}
		}
		$this->assertSame( 9, $this->store->get( ObjectType::Post, $copy, 'field_t24a' ) );
		$this->assertSame( array( 'x' ), $this->store->get( ObjectType::Post, $copy, 'field_t24b' ) );
		$this->store->deleteObject( ObjectType::Post, $copy );
	}

	/**
	 * Unmanaged keys fall through to core.
	 */
	public function test_unmanaged_key_passes_through(): void {
		update_post_meta( $this->post, 'other_key', 'v' );
		$this->assertSame( 'v', get_post_meta( $this->post, 'other_key', true ) );
	}
}
