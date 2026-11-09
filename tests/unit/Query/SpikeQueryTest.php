<?php
/**
 * Differential tests (WP 1.3): the pivot / typed-table translation returns what WP_Query returns on wp_postmeta.
 *
 * @package NF
 */

use NF\Pivot\Pivot;
use NF\Query\SpikeQuery;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Status;
use NF\Storage\Store;

/**
 * Same IDs, order, found_posts and max_num_pages as WP_Query, including the pagination cases.
 */
class SpikeQueryTest extends WP_UnitTestCase {

	private const TYPE = 'nf_spike_event';

	/**
	 * Services under test.
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
	 * Query translator.
	 *
	 * @var SpikeQuery
	 */
	private SpikeQuery $query;

	/**
	 * Pivot set id.
	 *
	 * @var int
	 */
	private int $pivotId;

	/**
	 * Post IDs created, for cleanup.
	 *
	 * @var int[]
	 */
	private array $ids = array();

	/**
	 * Tables, fields, pivot set and 23 events: 3 drafts / unfeatured mixed in, artists on every third.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		register_post_type( self::TYPE );
		$db     = nf_db();
		$fields = new FieldMap( $db );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t13%' ) );
		$fields->flush();
		$fields->register( 'field_t13_featured', 'featured', DataTable::Int );
		$fields->register( 'field_t13_date', 'event_date', DataTable::Date );
		$fields->register( 'field_t13_price', 'price', DataTable::Dec );
		$fields->register( 'field_t13_artists', 'artists', DataTable::Int, true );
		$this->store = new Store( $db, $fields );
		$this->pivot = new Pivot( $db, $fields, $this->store );
		$this->query = new SpikeQuery( $db, $this->pivot );

		$this->pivotId = $this->pivot->setId( ObjectType::Post, self::TYPE );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot_slots' ) . ' WHERE pivot_id = ?', array( $this->pivotId ) );
		$this->pivot->assignSlot( $this->pivotId, 'field_t13_featured', 'i1' );
		$this->pivot->assignSlot( $this->pivotId, 'field_t13_date', 't1' );
		$this->pivot->assignSlot( $this->pivotId, 'field_t13_price', 'n1' );

		for ( $i = 1; $i <= 23; $i++ ) {
			$status   = 0 === $i % 11 ? 'draft' : 'publish';
			$id       = self::factory()->post->create(
				array(
					'post_type'   => self::TYPE,
					'post_status' => $status,
					'post_date'   => gmdate( 'Y-m-d H:i:s', 1700000000 + $i * 3600 ),
				)
			);
			$this->ids[] = $id;
			$featured    = (int) ( 0 === $i % 2 );
			$day         = 1 + ( ( $i * 7 ) % 23 ); // Unique days 1-23 of Nov 2026, scrambled.
			$ymd         = sprintf( '202611%02d', $day );
			$price       = (string) ( 10 + $i );
			$artists     = 0 === $i % 3 ? array( 901, 900 + $i ) : array( 900 + $i );

			// ACF format in wp_postmeta.
			update_post_meta( $id, 'featured', (string) $featured );
			update_post_meta( $id, 'event_date', $ymd );
			update_post_meta( $id, 'price', $price );
			update_post_meta( $id, 'artists', array_map( 'strval', $artists ) );

			// NF stores.
			$this->store->set( ObjectType::Post, $id, 'field_t13_featured', $featured );
			$this->store->set( ObjectType::Post, $id, 'field_t13_date', sprintf( '2026-11-%02d 00:00:00', $day ) );
			$this->store->set( ObjectType::Post, $id, 'field_t13_price', $price );
			$this->store->set( ObjectType::Post, $id, 'field_t13_artists', $artists );
			$post = get_post( $id );
			$this->pivot->sync( $this->pivotId, $id, $this->core( $post ) );
		}
	}

	/**
	 * Core pivot columns of a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string,mixed>
	 */
	private function core( WP_Post $post ): array {
		return array(
			'status'     => Status::fromName( $post->post_status )->value,
			'author_id'  => (int) $post->post_author,
			'parent_id'  => (int) $post->post_parent,
			'published'  => $post->post_date,
			'modified'   => $post->post_modified,
			'menu_order' => (int) $post->menu_order,
		);
	}

	/**
	 * Remove NF rows (they survive the test transaction after DDL).
	 */
	public function tear_down() {
		$db = nf_db();
		foreach ( $this->ids as $id ) {
			$this->store->deleteObject( ObjectType::Post, $id );
		}
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot' ) . ' WHERE pivot_id = ?', array( $this->pivotId ) );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot_slots' ) . ' WHERE pivot_id = ?', array( $this->pivotId ) );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot_sets' ) . ' WHERE pivot_id = ?', array( $this->pivotId ) );
		unregister_post_type( self::TYPE );
		parent::tear_down();
	}

	/**
	 * Assert NF and WP_Query agree for the same arguments.
	 *
	 * @param array<string,mixed> $args WP_Query arguments.
	 */
	private function assertParity( array $args ): void {
		$args = array_merge(
			array(
				'post_type' => self::TYPE,
				'fields'    => 'ids',
			),
			$args
		);
		$core = new WP_Query( $args );
		$nf   = $this->query->run( $args );
		$this->assertNotNull( $nf, 'Arguments must be inside the spike subset.' );
		$this->assertSame( array_map( 'intval', $core->posts ), $nf['ids'], 'IDs and order' );
		$this->assertSame( (int) $core->found_posts, $nf['found_posts'], 'found_posts' );
		$this->assertSame( (int) $core->max_num_pages, $nf['max_num_pages'], 'max_num_pages' );
	}

	/**
	 * Query 1: featured events from a date, sorted by date. Every pagination case from the design doc.
	 */
	public function test_featured_from_date_sorted_with_pagination() {
		$base = array(
			'meta_query' => array(
				array(
					'key'   => 'featured',
					'value' => 1,
				),
				array(
					'key'     => 'event_date',
					'value'   => '20261105',
					'compare' => '>=',
					'type'    => 'DATE',
				),
			),
			'meta_key'   => 'event_date',
			'orderby'    => 'meta_value',
			'order'      => 'ASC',
		);
		$this->assertParity( $base + array( 'posts_per_page' => 3 ) );
		$this->assertParity( $base + array( 'posts_per_page' => 3, 'paged' => 2 ) );
		$this->assertParity( $base + array( 'posts_per_page' => 3, 'paged' => 4 ) );      // Last page.
		$this->assertParity( $base + array( 'posts_per_page' => 3, 'paged' => 9 ) );      // Past the last page.
		$this->assertParity( $base + array( 'posts_per_page' => 3, 'offset' => 2 ) );
		$this->assertParity( $base + array( 'posts_per_page' => 3, 'offset' => 2, 'paged' => 3 ) ); // Offset wins.
		$this->assertParity( $base + array( 'posts_per_page' => -1 ) );
		$this->assertParity( $base + array( 'nopaging' => true ) );
		$this->assertParity( $base + array( 'posts_per_page' => 3, 'no_found_rows' => true ) );
	}

	/**
	 * Query 1 variants: the ID-ordered default order and a decimal slot.
	 */
	public function test_default_order_and_decimal_filter() {
		$this->assertParity( array( 'posts_per_page' => 5 ) );
		$this->assertParity(
			array(
				'posts_per_page' => 4,
				'paged'          => 2,
				'meta_query'     => array(
					array(
						'key'     => 'price',
						'value'   => 20,
						'compare' => '<',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
	}

	/**
	 * Query 2: reverse relationship (which events use artist X), default date order.
	 */
	public function test_reverse_relationship() {
		$reverse = array(
			'meta_query' => array(
				array(
					'key'     => 'artists',
					'value'   => '"901"',
					'compare' => 'LIKE',
				),
			),
		);
		$this->assertParity( $reverse + array( 'posts_per_page' => 3 ) );
		$this->assertParity( $reverse + array( 'posts_per_page' => 3, 'paged' => 2 ) );
		$this->assertParity( $reverse + array( 'posts_per_page' => -1 ) );
		$this->assertParity(
			array(
				'meta_query'     => array(
					array(
						'key'     => 'artists',
						'value'   => '"905"',
						'compare' => 'LIKE',
					),
				),
				'posts_per_page' => 10,
			)
		);
	}

	/**
	 * Arguments outside the subset are refused, not guessed.
	 */
	public function test_unsupported_arguments_return_null() {
		$this->assertNull( $this->query->run( array( 'post_type' => self::TYPE, 's' => 'x', 'orderby' => 'rand' ) ) );
		$this->assertNull(
			$this->query->run(
				array(
					'post_type'  => self::TYPE,
					'meta_query' => array(
						'relation' => 'OR',
						array( 'key' => 'featured', 'value' => 1 ),
					),
				)
			)
		);
		$this->assertNull( $this->query->run( array( 'post_type' => 'no_such_type_with_pivot' ) ), 'No pivot set: read-only, nothing created.' );
	}

	/**
	 * A read never writes: no pivot set is created for an unknown type, write_version does not move.
	 */
	public function test_query_does_not_write() {
		$before = $this->pivot->writeVersion( $this->pivotId );
		$this->query->run( array( 'post_type' => self::TYPE ) );
		$this->assertSame( $before, $this->pivot->writeVersion( $this->pivotId ) );
		$this->assertNull( $this->pivot->findSetId( ObjectType::Post, 'no_such_type_with_pivot' ) );
	}

	/**
	 * Rebuild equals write-through, row for row.
	 */
	public function test_rebuild_equals_write_through() {
		$db   = nf_db();
		$sql  = 'SELECT * FROM ' . $db->table( 'nf_pivot' ) . ' WHERE pivot_id = ? ORDER BY object_id';
		$live = $db->select( $sql, array( $this->pivotId ) );
		$this->assertCount( 23, $live );
		$v = $this->pivot->writeVersion( $this->pivotId );
		$db->execute( 'UPDATE ' . $db->table( 'nf_pivot' ) . ' SET i1 = NULL, t1 = NULL, n1 = NULL WHERE pivot_id = ?', array( $this->pivotId ) );
		$this->assertSame( 23, $this->pivot->rebuild( $this->pivotId ) );
		$this->assertSame( $live, $db->select( $sql, array( $this->pivotId ) ) );
		$this->assertGreaterThan( $v, $this->pivot->writeVersion( $this->pivotId ) );
	}

	/**
	 * Slots are validated against the field's type.
	 */
	public function test_slot_type_mismatch_is_refused() {
		$this->expectException( InvalidArgumentException::class );
		$this->pivot->assignSlot( $this->pivotId, 'field_t13_price', 'i2' );
	}
}
