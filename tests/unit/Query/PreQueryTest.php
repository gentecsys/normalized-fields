<?php
/**
 * WP_Query short-circuit through posts_pre_query and pagination parity (WP 3.3b).
 *
 * @package NF
 */

use NF\Pivot\Pivot;
use NF\Query\PreQuery;
use NF\Query\QueryContext;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * Same IDs, order, found_posts and max_num_pages as the arguments mean in core.
 */
class PreQueryTest extends WP_UnitTestCase {

	private const TYPE = 'nf_pre_event';

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
	 * Hook object.
	 *
	 * @var PreQuery
	 */
	private PreQuery $hook;

	/**
	 * Post IDs created, in price order 10..70.
	 *
	 * @var int[]
	 */
	private array $ids = array();

	/**
	 * Seven posts with a slotted integer field `preprice`.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		register_post_type( self::TYPE );
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t33b%' ) );
		$fields = new FieldMap( $db );
		$fields->flush();
		$fields->register( 'field_t33b_price', 'preprice', DataTable::Int );
		$this->store = new Store( $db, $fields );
		$this->pivot = new Pivot( $db, $fields, $this->store );
		$pid         = $this->pivot->setId( ObjectType::Post, self::TYPE );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot' ) . ' WHERE pivot_id = ?', array( $pid ) );
		$this->pivot->assignSlot( $pid, 'field_t33b_price', 'i1' );
		for ( $i = 1; $i <= 7; $i++ ) {
			$id          = self::factory()->post->create( array( 'post_type' => self::TYPE ) );
			$this->ids[] = $id;
			$this->store->set( ObjectType::Post, $id, 'field_t33b_price', $i * 10 );
			$this->pivot->sync( $pid, $id, array( 'status' => 1 ) );
		}
		$this->hook = new PreQuery( new QueryContext( $db, $this->pivot, $this->pivot->dictionary() ) );
		$this->hook->register();
	}

	/**
	 * Remove hooks and data.
	 */
	public function tear_down() {
		remove_filter( 'posts_pre_query', array( $this->hook, 'answer' ), 10 );
		remove_filter( 'found_posts_query', array( $this->hook, 'foundPostsQuery' ), 10 );
		remove_filter( 'found_posts', array( $this->hook, 'foundPosts' ), 1 );
		foreach ( $this->ids as $id ) {
			$this->store->deleteObject( ObjectType::Post, $id );
			wp_delete_post( $id, true );
		}
		parent::tear_down();
	}

	/**
	 * Query args ordered by the field, three per page.
	 *
	 * @param array<string,mixed> $extra Extra arguments.
	 * @return WP_Query
	 */
	private function run( array $extra = array() ): WP_Query {
		return new WP_Query(
			array_merge(
				array(
					'post_type'      => self::TYPE,
					'meta_key'       => 'preprice',
					'orderby'        => 'meta_value_num',
					'order'          => 'ASC',
					'posts_per_page' => 3,
				),
				$extra
			)
		);
	}

	/**
	 * Pages one, two and the last.
	 */
	public function test_paged(): void {
		$q = $this->run();
		$this->assertSame( array_slice( $this->ids, 0, 3 ), wp_list_pluck( $q->posts, 'ID' ) );
		$this->assertSame( 7, $q->found_posts );
		$this->assertSame( 3, $q->max_num_pages );
		$last = $this->run( array( 'paged' => 3 ) );
		$this->assertSame( array( $this->ids[6] ), wp_list_pluck( $last->posts, 'ID' ) );
		$this->assertSame( 7, $last->found_posts );
	}

	/**
	 * Past the last page is empty, as in core.
	 */
	public function test_past_last_page(): void {
		$q = $this->run( array( 'paged' => 4 ) );
		$this->assertSame( array(), $q->posts );
		$this->assertSame( 0, $q->post_count );
		$this->assertSame( 0, $q->found_posts );
		$this->assertSame( 0, $q->max_num_pages );
	}

	/**
	 * Offset overrides paged.
	 */
	public function test_offset_overrides_paged(): void {
		$q = $this->run(
			array(
				'offset' => 2,
				'paged'  => 3,
			)
		);
		$this->assertSame( array_slice( $this->ids, 2, 3 ), wp_list_pluck( $q->posts, 'ID' ) );
	}

	/**
	 * No limit: no count, all rows.
	 */
	public function test_nopaging_and_minus_one(): void {
		$this->assertSame( $this->ids, wp_list_pluck( $this->run( array( 'posts_per_page' => -1 ) )->posts, 'ID' ) );
		$this->assertSame( $this->ids, wp_list_pluck( $this->run( array( 'nopaging' => true ) )->posts, 'ID' ) );
	}

	/**
	 * `fields => ids` returns integers; `no_found_rows` skips the count.
	 */
	public function test_ids_and_no_found_rows(): void {
		$q = $this->run(
			array(
				'fields'        => 'ids',
				'no_found_rows' => true,
			)
		);
		$this->assertSame( array_slice( $this->ids, 0, 3 ), $q->posts );
		$this->assertSame( 0, $q->found_posts );
	}

	/**
	 * Another `found_posts` filter still runs after NF's early one.
	 */
	public function test_other_found_posts_filters_run(): void {
		$sub = static fn( $n ) => $n - 1;
		add_filter( 'found_posts', $sub, 10 );
		$q = $this->run();
		remove_filter( 'found_posts', $sub, 10 );
		$this->assertSame( 6, $q->found_posts );
	}

	/**
	 * Capped count reports the cap plus one and flags the query; paging past the cap counts exactly.
	 */
	public function test_capped_count(): void {
		$cap = static fn() => 5;
		add_filter( 'nf/count/cap', $cap );
		$q = $this->run( array( 'nf_count' => 'capped' ) );
		$this->assertSame( 6, $q->found_posts );
		$this->assertTrue( (bool) $q->get( 'nf_count_capped' ) );
		$deep = $this->run(
			array(
				'nf_count' => 'capped',
				'paged'    => 3,
			)
		);
		remove_filter( 'nf/count/cap', $cap );
		$this->assertSame( 7, $deep->found_posts, 'page beyond the cap gets the exact count' );
	}

	/**
	 * Short-circuit mode is flagged on answered queries.
	 */
	public function test_mode_flag(): void {
		$this->assertSame( 'short-circuit', $this->run()->get( 'nf_mode' ) );
	}

	/**
	 * A clause filter from another plugin sends the query to core; suppress_filters overrides.
	 */
	public function test_clause_filter_passes_through(): void {
		$f = static fn( $w ) => $w;
		add_filter( 'posts_where', $f );
		$passed = $this->run();
		$forced = $this->run( array( 'suppress_filters' => true ) );
		remove_filter( 'posts_where', $f );
		$this->assertNotSame( 'short-circuit', $passed->get( 'nf_mode' ) );
		$this->assertSame( 'short-circuit', $forced->get( 'nf_mode' ) );
	}

	/**
	 * Unmanaged keys, OR meta queries and search stay with core.
	 */
	public function test_untranslatable_passes_through(): void {
		foreach ( array(
			array( 's' => 'zzz' ),
			array( 'meta_key' => 'unmanaged_key' ),
			array(
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'   => 'preprice',
						'value' => 10,
					),
				),
			),
		) as $extra ) {
			$q = new WP_Query( array_merge( array( 'post_type' => self::TYPE ), $extra ) );
			$this->assertNotSame( 'short-circuit', $q->get( 'nf_mode' ) );
		}
	}
}
