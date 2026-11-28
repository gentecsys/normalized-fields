<?php
/**
 * N+1 detector and the 20-card listing gate (WP 3.5e).
 *
 * @package NF
 */

use NF\Loading\Detector;
use NF\Loading\FieldLoader;
use NF\Loading\Primer;
use NF\Loading\ResultSetRegistry;
use NF\Loading\With;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * Lone loads past the threshold fire `nf/debug/n_plus_one`; a 20-card listing stays within 15 queries.
 */
class DetectorTest extends WP_UnitTestCase {

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Post IDs created by a test.
	 *
	 * @var int[]
	 */
	private array $ids = array();

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
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t35e%' ) );
		$fields->flush();
		$fields->register( 'field_t35e_title', 'title', DataTable::Str );
		$fields->register( 'field_t35e_venue', 'venue', DataTable::Int );
		$fields->register( 'field_t35e_city', 'city', DataTable::Str );
		$this->store = new Store( $db, $fields );
		add_filter( 'nf/debug/enabled', '__return_true' );
		add_filter(
			'nf/loading/describe_field',
			static function ( $field, array $q ) {
				$map = array(
					'venue' => array(
						'key'  => 'field_t35e_venue',
						'type' => 'post_object',
					),
					'city'  => array(
						'key'  => 'field_t35e_city',
						'type' => 'text',
					),
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
		remove_all_actions( 'nf/debug/n_plus_one' );
		foreach ( $this->ids as $id ) {
			$this->store->deleteObject( ObjectType::Post, $id );
		}
		parent::tear_down();
	}

	/**
	 * Six lone loads of one field fire the action once; five do not.
	 *
	 * @return void
	 */
	public function test_fires_after_five_lone_loads(): void {
		$this->ids = self::factory()->post->create_many( 7 );
		$fired     = array();
		add_action(
			'nf/debug/n_plus_one',
			static function ( $field, $count, $caller ) use ( &$fired ) {
				$fired[] = array( $field, $count, $caller );
			},
			10,
			3
		);
		$loader = new FieldLoader( $this->store, new ResultSetRegistry(), null, new Detector() );
		foreach ( array_slice( $this->ids, 0, 5 ) as $id ) {
			$loader->get( $id, 'field_t35e_title' );
		}
		$this->assertSame( array(), $fired );
		$loader->get( $this->ids[5], 'field_t35e_title' );
		$loader->get( $this->ids[6], 'field_t35e_title' );
		$this->assertCount( 1, $fired );
		$this->assertSame( 'field_t35e_title', $fired[0][0] );
		$this->assertSame( 6, $fired[0][1] );
		$this->assertArrayHasKey( 'line', $fired[0][2] );
	}

	/**
	 * Reads that go through a result set never count as lone loads.
	 *
	 * @return void
	 */
	public function test_set_loads_do_not_fire(): void {
		$this->ids = self::factory()->post->create_many( 10 );
		$fired     = 0;
		add_action(
			'nf/debug/n_plus_one',
			static function () use ( &$fired ) {
				++$fired;
			}
		);
		$registry = new ResultSetRegistry();
		$registry->register( $this->ids );
		$loader = new FieldLoader( $this->store, $registry, null, new Detector() );
		foreach ( $this->ids as $id ) {
			$loader->get( $id, 'field_t35e_title' );
		}
		$this->assertSame( 0, $fired );
	}

	/**
	 * With detection off, nothing fires.
	 *
	 * @return void
	 */
	public function test_off_outside_debug_mode(): void {
		remove_filter( 'nf/debug/enabled', '__return_true' );
		add_filter( 'nf/debug/enabled', '__return_false' );
		$this->ids = self::factory()->post->create_many( 8 );
		$fired     = 0;
		add_action(
			'nf/debug/n_plus_one',
			static function () use ( &$fired ) {
				++$fired;
			}
		);
		$loader = new FieldLoader( $this->store, new ResultSetRegistry(), null, new Detector() );
		foreach ( $this->ids as $id ) {
			$loader->get( $id, 'field_t35e_title' );
		}
		$this->assertSame( 0, $fired );
		remove_filter( 'nf/debug/enabled', '__return_false' );
	}

	/**
	 * A 20-card listing (title, venue, venue city) costs at most 15 queries, with and without with().
	 *
	 * @return void
	 */
	public function test_twenty_card_listing_within_budget(): void {
		global $wpdb;
		$cards     = self::factory()->post->create_many( 20 );
		$venues    = self::factory()->post->create_many( 8 );
		$this->ids = array_merge( $cards, $venues );
		foreach ( $venues as $i => $venue ) {
			$this->store->set( ObjectType::Post, $venue, 'field_t35e_city', 'City ' . $i );
		}
		foreach ( $cards as $i => $card ) {
			$this->store->set( ObjectType::Post, $card, 'field_t35e_title', 'Card ' . $i );
			$this->store->set( ObjectType::Post, $card, 'field_t35e_venue', $venues[ $i % 8 ] );
		}
		$fired = 0;
		add_action(
			'nf/debug/n_plus_one',
			static function () use ( &$fired ) {
				++$fired;
			}
		);
		$render = function ( FieldLoader $loader ) use ( $cards ): void {
			foreach ( $cards as $card ) {
				$loader->get( $card, 'field_t35e_title' );
				$venue = $loader->get( $card, 'field_t35e_venue' );
				$this->assertGreaterThan( 0, $venue );
				$loader->get( (int) $venue, 'field_t35e_city' );
			}
		};

		// Without with(): the venues are read lazily, but through the batched loader.
		$registry = new ResultSetRegistry();
		$registry->register( $cards );
		$loader = new FieldLoader( $this->store, $registry, new Primer(), new Detector() );
		$before = $wpdb->num_queries;
		$render( $loader );
		$lazy = $wpdb->num_queries - $before;
		$this->assertLessThanOrEqual( 15, $lazy );
		$fired_lazy = $fired;

		// With with(): the venue path is loaded up front.
		$registry = new ResultSetRegistry();
		$registry->register( $cards );
		$loader = new FieldLoader( $this->store, $registry, new Primer(), new Detector() );
		$before = $wpdb->num_queries;
		( new With( $loader, new Primer(), $registry ) )->run( $cards, array( 'venue.city' ) );
		$render( $loader );
		$eager = $wpdb->num_queries - $before;
		$this->assertLessThanOrEqual( 15, $eager );
		$this->assertSame( 1, $fired_lazy, 'unprimed venue city reads are lone loads' );
		$this->assertSame( $fired_lazy, $fired );
	}
}
