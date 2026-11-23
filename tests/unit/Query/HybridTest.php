<?php
/**
 * Hybrid mode, small path: NF conditions become post__in, core never reads wp_postmeta for them (WP 3.3c2a).
 *
 * @package NF
 */

// phpcs:disable WordPress.DB.SlowDBQuery -- the test runs WP_Query with meta_key / meta_query against NF.

use NF\Pivot\Pivot;
use NF\Query\Hybrid;
use NF\Query\PreQuery;
use NF\Query\QueryContext;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * Posts with an NF-only field (nothing in wp_postmeta) are found through post__in.
 */
class HybridTest extends WP_UnitTestCase {

	private const TYPE = 'nf_hy_event';

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Hook object.
	 *
	 * @var Hybrid
	 */
	private Hybrid $hook;

	/**
	 * Post IDs in price order 10..70; titles are in reverse alphabetical order of price.
	 *
	 * @var int[]
	 */
	private array $ids = array();

	/**
	 * Pivot.
	 *
	 * @var Pivot
	 */
	private Pivot $pivot;

	/**
	 * Pivot set of the test type.
	 *
	 * @var int
	 */
	private int $pid = 0;

	/**
	 * Seven posts with a slotted integer field `hyprice`, stored in NF only.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		update_option( NF\Install\Upgrader::OPTION, NF\Install\Upgrader::SCHEMA_VERSION );
		register_post_type( self::TYPE );
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t33c2a%' ) );
		$fields = new FieldMap( $db );
		$fields->flush();
		$fields->register( 'field_t33c2a_price', 'hyprice', DataTable::Int );
		$this->store = new Store( $db, $fields );
		$pivot       = new Pivot( $db, $fields, $this->store );
		$pid         = $pivot->setId( ObjectType::Post, self::TYPE );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot' ) . ' WHERE pivot_id = ?', array( $pid ) );
		$pivot->assignSlot( $pid, 'field_t33c2a_price', 'i1' );
		$this->pivot = $pivot;
		$this->pid   = $pid;
		for ( $i = 1; $i <= 7; $i++ ) {
			$id          = self::factory()->post->create(
				array(
					'post_type'  => self::TYPE,
					'post_title' => 'Title ' . ( 8 - $i ),
				)
			);
			$this->ids[] = $id;
			$this->store->set( ObjectType::Post, $id, 'field_t33c2a_price', $i * 10 );
			$pivot->sync( $pid, $id, array( 'status' => 1 ) );
		}
		$context    = new QueryContext( $db, $pivot, $pivot->dictionary() );
		$this->hook = new Hybrid( $context, new PreQuery( $context ) );
		$this->hook->register();
	}

	/**
	 * Remove hooks and data.
	 */
	public function tear_down() {
		remove_all_filters( 'nf/hybrid/id_cap' );
		remove_all_filters( 'nf/hybrid/set_max' );
		remove_action( 'pre_get_posts', array( $this->hook, 'apply' ), 9999 );
		remove_filter( 'found_posts', array( $this->hook, 'foundPosts' ), 1 );
		remove_filter( 'the_posts', array( $this->hook, 'restorePaging' ), 1 );
		delete_option( 'sticky_posts' );
		foreach ( $this->ids as $id ) {
			$this->store->deleteObject( ObjectType::Post, $id );
			wp_delete_post( $id, true );
		}
		parent::tear_down();
	}

	/**
	 * Run a query the full short-circuit would not answer (a post__not_in list), so the hybrid path takes it.
	 *
	 * @param array<string,mixed> $extra Extra arguments.
	 * @return WP_Query
	 */
	private function hy_run( array $extra = array() ): WP_Query {
		return new WP_Query(
			array_merge(
				array(
					'post_type'    => self::TYPE,
					'post__not_in' => array( 999999 ),
					'orderby'      => 'title',
					'order'        => 'ASC',
				),
				$extra
			)
		);
	}

	/**
	 * An NF clause filters through IDs; wp_postmeta holds nothing for the key.
	 */
	public function test_meta_query_clause_resolves_to_ids(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- test checks core's table is empty for the key.
		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'hyprice'" ) );
		$q = $this->hy_run(
			array(
				'meta_query' => array(
					array(
						'key'     => 'hyprice',
						'value'   => 50,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		$this->assertSame( 'hybrid', $q->get( 'nf_mode' ) );
		$this->assertEqualsCanonicalizing( array_slice( $this->ids, 4 ), wp_list_pluck( $q->posts, 'ID' ) );
		$this->assertSame( 3, $q->found_posts );
		// Core's own order (title ASC) is kept: price 70 has the smallest title.
		$this->assertSame( array_reverse( array_slice( $this->ids, 4 ) ), wp_list_pluck( $q->posts, 'ID' ) );
	}

	/**
	 * Ordering by the NF field follows NF's order.
	 */
	public function test_orders_by_nf_field(): void {
		$q = $this->hy_run(
			array(
				'meta_key' => 'hyprice',
				'orderby'  => 'meta_value_num',
				'order'    => 'DESC',
			)
		);
		$this->assertSame( array_reverse( $this->ids ), wp_list_pluck( $q->posts, 'ID' ) );
		$this->assertSame( '', (string) $q->get( 'meta_key' ) );
	}

	/**
	 * A core meta clause stays with core and is combined with the NF clause.
	 */
	public function test_core_meta_clause_is_kept(): void {
		update_post_meta( $this->ids[5], 'color', 'red' );
		update_post_meta( $this->ids[1], 'color', 'red' );
		$q = $this->hy_run(
			array(
				'meta_query' => array(
					array(
						'key'     => 'hyprice',
						'value'   => 40,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
					array(
						'key'   => 'color',
						'value' => 'red',
					),
				),
			)
		);
		$this->assertSame( array( $this->ids[5] ), wp_list_pluck( $q->posts, 'ID' ) );
	}

	/**
	 * An existing post__in is intersected.
	 */
	public function test_intersects_existing_post_in(): void {
		$q = $this->hy_run(
			array(
				'post__in'   => array( $this->ids[0], $this->ids[5], $this->ids[6] ),
				'meta_query' => array(
					array(
						'key'     => 'hyprice',
						'value'   => 50,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		$this->assertEqualsCanonicalizing( array( $this->ids[5], $this->ids[6] ), wp_list_pluck( $q->posts, 'ID' ) );
	}

	/**
	 * No match gives no rows, not every post.
	 */
	public function test_no_match_returns_nothing(): void {
		$q = $this->hy_run(
			array(
				'meta_query' => array(
					array(
						'key'     => 'hyprice',
						'value'   => 999,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		$this->assertSame( array(), $q->posts );
	}

	/**
	 * A stale schema fails closed instead of reading wp_postmeta.
	 */
	public function test_stale_schema_fails_closed(): void {
		$this->setExpectedIncorrectUsage( Hybrid::class );
		update_option( NF\Install\Upgrader::OPTION, 0 );
		$fired = 0;
		$count = static function () use ( &$fired ): void {
			++$fired;
		};
		add_action( 'nf/hybrid/failed', $count );
		$q = $this->hy_run( array( 'meta_key' => 'hyprice' ) );
		remove_action( 'nf/hybrid/failed', $count );
		$this->assertSame( array(), $q->posts );
		$this->assertSame( 'hybrid-failed', $q->get( 'nf_mode' ) );
		$this->assertSame( 1, $fired );
	}

	/**
	 * Lists above the cap fail closed until the large-list path exists.
	 */
	public function test_over_cap_fails_closed(): void {
		$this->setExpectedIncorrectUsage( Hybrid::class );
		$cap = static fn() => 2;
		add_filter( 'nf/hybrid/id_cap', $cap );
		$q   = $this->hy_run( array( 'meta_key' => 'hyprice' ) );
		remove_filter( 'nf/hybrid/id_cap', $cap );
		$this->assertSame( array(), $q->posts );
		$this->assertSame( 'hybrid-failed', $q->get( 'nf_mode' ) );
	}

	/**
	 * OR between an NF clause and a core clause cannot be split: fail closed.
	 */
	public function test_or_with_core_clause_fails_closed(): void {
		$this->setExpectedIncorrectUsage( Hybrid::class );
		$q = $this->hy_run(
			array(
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'     => 'hyprice',
						'value'   => 10,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
					array(
						'key'   => 'color',
						'value' => 'red',
					),
				),
			)
		);
		$this->assertSame( array(), $q->posts );
		$this->assertSame( 'hybrid-failed', $q->get( 'nf_mode' ) );
	}

	/**
	 * OR across NF clauses only is not read as AND.
	 */
	public function test_or_across_nf_clauses_fails_closed(): void {
		$this->setExpectedIncorrectUsage( Hybrid::class );
		$q = $this->hy_run(
			array(
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'     => 'hyprice',
						'value'   => 10,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => 'hyprice',
						'value'   => 20,
						'compare' => '=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		$this->assertSame( array(), $q->posts );
		$this->assertSame( 'hybrid-failed', $q->get( 'nf_mode' ) );
	}

	/**
	 * The nf argument's orderby works without a core orderby.
	 */
	public function test_nf_orderby(): void {
		$q = new WP_Query(
			array(
				'post_type'    => self::TYPE,
				'post__not_in' => array( 999999 ),
				'nf'           => array( 'orderby' => array( 'hyprice' => 'DESC' ) ),
			)
		);
		$this->assertSame( array_reverse( $this->ids ), wp_list_pluck( $q->posts, 'ID' ) );
	}

	/**
	 * A meta_value_num filter on an NF key is not silently dropped.
	 */
	public function test_meta_value_num_fails_closed(): void {
		$this->setExpectedIncorrectUsage( Hybrid::class );
		$q = $this->hy_run(
			array(
				'meta_key'       => 'hyprice',
				'meta_value_num' => 30,
			)
		);
		$this->assertSame( array(), $q->posts );
		$this->assertSame( 'hybrid-failed', $q->get( 'nf_mode' ) );
	}

	/**
	 * Queries without an NF field are untouched.
	 */
	public function test_without_nf_field_is_untouched(): void {
		$q = $this->hy_run( array( 'meta_key' => 'color' ) );
		$this->assertSame( '', (string) $q->get( 'nf_mode' ) );
		$this->assertSame( 'color', $q->get( 'meta_key' ) );
	}

	/**
	 * Lower the ID cap so seven posts are a large list.
	 *
	 * @param int $cap Cap.
	 */
	private function hy_cap( int $cap ): void {
		add_filter(
			'nf/hybrid/id_cap',
			static function () use ( $cap ) {
				return $cap;
			}
		);
	}

	/**
	 * Over the cap with a small core side: the core IDs are filtered on the NF side.
	 */
	public function test_large_list_starts_from_the_smaller_core_side(): void {
		$this->hy_cap( 3 );
		$others = get_posts(
			array(
				'post_type'   => self::TYPE,
				'post_status' => 'any',
				'fields'      => 'ids',
				'nopaging'    => true,
			)
		);
		$why    = '';
		add_action(
			'nf/hybrid/failed',
			static function ( $reason ) use ( &$why ) {
				$why = $reason;
			}
		);
		$q = $this->hy_run(
			array(
				'post__not_in' => array_values( array_diff( $others, array_slice( $this->ids, 4 ) ) ),
				'meta_query'   => array(
					array(
						'key'     => 'hyprice',
						'value'   => 20,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		$this->assertSame( 'hybrid', $q->get( 'nf_mode' ), $why );
		$this->assertEqualsCanonicalizing( array_slice( $this->ids, 4 ), wp_list_pluck( $q->posts, 'ID' ) );
	}

	/**
	 * Over the cap, nothing else in the query: NF pages its own sorted list and supplies the total.
	 */
	public function test_large_list_pages_in_nf_order_with_total(): void {
		$this->hy_cap( 3 );
		$where = static fn( $w ) => $w;
		add_filter( 'posts_where', $where ); // Keeps the full short-circuit out; the hybrid path takes the query.
		$q     = new WP_Query(
			array(
				'post_type'      => self::TYPE,
				'meta_key'       => 'hyprice',
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
				'posts_per_page' => 2,
				'paged'          => 2,
				'meta_query'     => array(
					array(
						'key'     => 'hyprice',
						'value'   => 20,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		remove_filter( 'posts_where', $where );
		$this->assertSame( 'hybrid', $q->get( 'nf_mode' ) );
		$this->assertSame( array( $this->ids[4], $this->ids[3] ), wp_list_pluck( $q->posts, 'ID' ) );
		$this->assertSame( 2, (int) $q->get( 'paged' ) );
		$this->assertSame( 6, $q->found_posts );
		$this->assertSame( 3, $q->max_num_pages );
	}

	/**
	 * Both sides over the cap with NF ordering: the intersection keeps NF's order, with an exact total for a visitor.
	 */
	public function test_both_sides_large_intersect_in_nf_order_with_total(): void {
		$this->hy_cap( 3 );
		wp_set_current_user( 0 );
		$q = $this->hy_run(
			array(
				'meta_key'       => 'hyprice',
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
				'posts_per_page' => 2,
				'paged'          => 2,
			)
		);
		$this->assertSame( 'hybrid', $q->get( 'nf_mode' ) );
		$this->assertSame( array( $this->ids[4], $this->ids[3] ), wp_list_pluck( $q->posts, 'ID' ) );
		$this->assertSame( 7, $q->found_posts );
		$this->assertSame( 4, $q->max_num_pages );
		$this->assertSame( 2, (int) $q->get( 'paged' ) );
	}

	/**
	 * Both sides over the cap without NF ordering: core's order, exact total, and a second run answers from the cache.
	 */
	public function test_both_sides_large_unordered_keeps_core_order(): void {
		$this->hy_cap( 3 );
		$args = array(
			'meta_query'     => array(
				array(
					'key'     => 'hyprice',
					'value'   => 10,
					'compare' => '>=',
					'type'    => 'NUMERIC',
				),
			),
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'posts_per_page' => 3,
		);
		$q    = $this->hy_run( $args );
		$this->assertSame( 'hybrid', $q->get( 'nf_mode' ) );
		$this->assertSame( array_slice( $this->ids, 0, 3 ), wp_list_pluck( $q->posts, 'ID' ) );
		$this->assertSame( 7, $q->found_posts );
		$again = $this->hy_run( $args );
		$this->assertSame( wp_list_pluck( $q->posts, 'ID' ), wp_list_pluck( $again->posts, 'ID' ) );
	}

	/**
	 * A side over the per-side ceiling fails closed.
	 */
	public function test_both_sides_over_set_max_fails_closed(): void {
		$this->hy_cap( 3 );
		$this->setExpectedIncorrectUsage( Hybrid::class );
		add_filter( 'nf/hybrid/set_max', static fn () => 4 );
		$q = $this->hy_run(
			array(
				'meta_key'       => 'hyprice',
				'orderby'        => 'meta_value_num',
				'posts_per_page' => 2,
			)
		);
		$this->assertSame( 'hybrid-failed', $q->get( 'nf_mode' ) );
		$this->assertSame( array(), $q->posts );
	}

	/**
	 * The NF-paged large query of test_large_list_pages_in_nf_order_with_total, as the given user.
	 *
	 * @param int $paged Page.
	 * @return WP_Query
	 */
	private function hy_large_page( int $paged = 1 ): WP_Query {
		$this->hy_cap( 3 );
		$where = static fn( $w ) => $w;
		add_filter( 'posts_where', $where );
		$q     = new WP_Query(
			array(
				'post_type'      => self::TYPE,
				'meta_key'       => 'hyprice',
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
				'posts_per_page' => 2,
				'paged'          => $paged,
				'meta_query'     => array(
					array(
						'key'     => 'hyprice',
						'value'   => 20,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		remove_filter( 'posts_where', $where );
		return $q;
	}

	/**
	 * Make a post private in WordPress and in the pivot.
	 *
	 * @param int $id     Post ID.
	 * @param int $author Author.
	 */
	private function hy_private( int $id, int $author ): void {
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'private',
				'post_author' => $author,
			)
		);
		$this->pivot->sync( $this->pid, $id, array( 'status' => NF\Storage\Status::Private->value ) );
	}

	/**
	 * Core adds sticky posts on page 1 only; NF paging the list itself must not add them to later pages.
	 */
	public function test_large_list_page_two_has_no_sticky_posts(): void {
		stick_post( $this->ids[0] );
		$q = $this->hy_large_page( 2 );
		$this->assertSame( 'hybrid', $q->get( 'nf_mode' ) );
		$this->assertSame( array( $this->ids[4], $this->ids[3] ), wp_list_pluck( $q->posts, 'ID' ) );
		$this->assertFalse( (bool) $q->get( 'ignore_sticky_posts' ) );
	}

	/**
	 * A user who can read private posts sees them in an NF-paged list, as core shows them; totals count them.
	 */
	public function test_large_list_includes_private_posts_the_user_can_read(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->hy_private( $this->ids[6], $admin );
		wp_set_current_user( $admin );
		$q = $this->hy_large_page();
		$this->assertSame( 'hybrid', $q->get( 'nf_mode' ) );
		$this->assertSame( array( $this->ids[6], $this->ids[5] ), wp_list_pluck( $q->posts, 'ID' ) );
		$this->assertSame( 6, $q->found_posts );
	}

	/**
	 * Logged out, private posts stay out of an NF-paged list.
	 */
	public function test_large_list_hides_private_posts_from_visitors(): void {
		$this->hy_private( $this->ids[6], 1 );
		wp_set_current_user( 0 );
		$q = $this->hy_large_page();
		$this->assertSame( array( $this->ids[5], $this->ids[4] ), wp_list_pluck( $q->posts, 'ID' ) );
		$this->assertSame( 5, $q->found_posts );
	}

	/**
	 * A user without read_private_posts who owns a private post: core shows it to them, a status list can't, so NF
	 * leaves the page to the core side (here both sides are over the cap with totals wanted: no rows, never wrong rows).
	 */
	public function test_large_list_with_own_private_post_is_not_paged_by_nf(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->hy_private( $this->ids[6], $author );
		wp_set_current_user( $author );
		$this->setExpectedIncorrectUsage( Hybrid::class );
		$q = $this->hy_large_page();
		$this->assertSame( 'hybrid-failed', $q->get( 'nf_mode' ) );
		$this->assertSame( array(), $q->posts );
	}

	/**
	 * The fail-closed reason never carries the ID list it refused.
	 */
	public function test_fail_closed_reason_lists_no_ids(): void {
		$this->hy_cap( 3 );
		$this->setExpectedIncorrectUsage( Hybrid::class );
		$why = '';
		add_action(
			'nf/hybrid/failed',
			static function ( $reason ) use ( &$why ) {
				$why = $reason;
			}
		);
		$this->hy_run(
			array(
				'meta_query' => array(
					array(
						'key'     => 'hyprice',
						'value'   => 10,
						'compare' => '>=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		remove_all_actions( 'nf/hybrid/failed' );
		$this->assertSame( 'both sides over 3 ids', $why );
	}
}
