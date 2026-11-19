<?php
/**
 * Query context and nf_query() against real NF tables (WP 3.2b).
 *
 * @package NF
 */

use NF\Pivot\Pivot;
use NF\Query\QueryContext;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * Query ids, counts, SQL text and explain output on a slotted field and a probed field.
 */
class QueryContextTest extends WP_UnitTestCase {

	private const TYPE = 'nf_ctx_event';

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
	 * Context under test.
	 *
	 * @var QueryContext
	 */
	private QueryContext $context;

	/**
	 * Post IDs created.
	 *
	 * @var int[]
	 */
	private array $ids = array();

	/**
	 * Fields `price` (slotted int) and `seats` (int without a slot).
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		register_post_type( self::TYPE );
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t32b%' ) );
		$fields = new FieldMap( $db );
		$fields->flush();
		$fields->register( 'field_t32b_price', 'ctxprice', DataTable::Int );
		$fields->register( 'field_t32b_seats', 'ctxseats', DataTable::Int );
		$this->store    = new Store( $db, $fields );
		$this->pivot    = new Pivot( $db, $fields, $this->store );
		$this->pivot_id = $this->pivot->setId( ObjectType::Post, self::TYPE );
		$this->clean();
		$this->pivot->assignSlot( $this->pivot_id, 'field_t32b_price', 'i1' );
		$this->context = new QueryContext( $db, $this->pivot, $this->pivot->dictionary() );
	}

	/**
	 * Remove test data.
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
	 * Delete pivot data of the test set.
	 */
	private function clean(): void {
		$db = nf_db();
		foreach ( array( 'nf_pivot', 'nf_row_pivot', 'nf_pivot_slots' ) as $t ) {
			$db->execute( 'DELETE FROM ' . $db->table( $t ) . ' WHERE pivot_id = ?', array( $this->pivot_id ) );
		}
	}

	/**
	 * Create an object with values and a pivot row.
	 *
	 * @param int $price Price.
	 * @param int $seats Seats.
	 * @return int
	 */
	private function make( int $price, int $seats ): int {
		$id          = self::factory()->post->create( array( 'post_type' => self::TYPE ) );
		$this->ids[] = $id;
		$this->store->set( ObjectType::Post, $id, 'field_t32b_price', $price );
		$this->store->set( ObjectType::Post, $id, 'field_t32b_seats', $seats );
		$this->pivot->sync( $this->pivot_id, $id, array( 'status' => 1 ) );
		return $id;
	}

	/**
	 * A builder bound to the test context.
	 */
	private function q(): NF\Query\Builder {
		return new NF\Query\Builder( self::TYPE, $this->context );
	}

	/**
	 * Slotted condition, order, limit and count.
	 */
	public function test_ids_and_count_on_slot(): void {
		$a = $this->make( 30, 1 );
		$b = $this->make( 10, 2 );
		$c = $this->make( 20, 3 );
		$this->assertSame( array( $b, $c, $a ), $this->q()->orderBy( 'ctxprice' )->ids() );
		$this->assertSame( array( $c, $a ), $this->q()->where( 'ctxprice', '>=', 20 )->orderBy( 'ctxprice' )->ids() );
		$this->assertSame( array( $b ), $this->q()->orderBy( 'ctxprice' )->limit( 1 )->ids() );
		$this->assertSame( 3, $this->q()->limit( 1 )->count(), 'count ignores limit' );
		$this->assertSame( 2, $this->q()->whereBetween( 'ctxprice', array( 10, 20 ) )->count() );
	}

	/**
	 * A field without a slot is answered by a typed-table probe.
	 */
	public function test_ids_on_probed_field(): void {
		$this->make( 1, 5 );
		$b = $this->make( 2, 7 );
		$this->assertSame( array( $b ), $this->q()->where( 'ctxseats', 7 )->ids() );
	}

	/**
	 * Publish only by default; other statuses on request.
	 */
	public function test_status_filter(): void {
		$a = $this->make( 1, 1 );
		$this->pivot->sync( $this->pivot_id, $a, array( 'status' => 2 ) );
		$this->assertSame( array(), $this->q()->ids() );
		$this->assertCount( 1, $this->q()->status( array( 'publish', 'draft', 'pending', 'private' ) )->ids() );
	}

	/**
	 * Unknown type: no pivot set, no results, no error.
	 */
	public function test_type_without_set_is_empty(): void {
		$q = new NF\Query\Builder( 'nf_ctx_never_stored', $this->context );
		$this->assertSame( array(), $q->ids() );
		$this->assertSame( 0, $q->count() );
		$this->assertSame( '', $q->toSql() );
	}

	/**
	 * The SQL text inlines values; explain output carries the plan and the database's rows.
	 */
	public function test_to_sql_and_explain(): void {
		$this->make( 5, 1 );
		$q = $this->q()->where( 'ctxprice', '>', 3 );
		$this->assertStringContainsString( '> 3', $q->toSql() );
		$e = $q->explain();
		$this->assertArrayHasKey( 'plan', $e );
		$this->assertNotEmpty( $e['db'] );
	}

	/**
	 * The nf_query function is bound to the plugin's context.
	 */
	public function test_nf_query_function(): void {
		$this->assertInstanceOf( NF\Query\Builder::class, nf_query( self::TYPE ) );
	}

	/**
	 * A spec-only builder refuses to run.
	 */
	public function test_builder_without_context_throws(): void {
		$this->expectException( LogicException::class );
		( new NF\Query\Builder( 'event' ) )->ids();
	}

	/**
	 * Posts hydrate to native WP_Post objects in query order.
	 */
	public function test_posts_in_query_order(): void {
		$a = $this->make( 30, 1 );
		$b = $this->make( 10, 2 );

		$posts = $this->q()->orderBy( 'ctxprice' )->posts();
		$this->assertSame( array( $b, $a ), wp_list_pluck( $posts, 'ID' ) );
		$this->assertInstanceOf( WP_Post::class, $posts[0] );
		$this->assertSame( array(), ( new NF\Query\Builder( 'nf_ctx_never_stored', $this->context ) )->posts() );
	}

	/**
	 * Terms hydration refuses a post query.
	 */
	public function test_hydrators_check_target_type(): void {
		$this->expectException( LogicException::class );
		$this->q()->terms();
	}

	/**
	 * Users hydration on a post query throws.
	 */
	public function test_users_on_post_query_throws(): void {
		$this->expectException( LogicException::class );
		$this->q()->users();
	}

	/**
	 * Posts hydration on a user query throws.
	 */
	public function test_posts_on_user_query_throws(): void {
		$this->expectException( LogicException::class );
		( new NF\Query\Builder( 'user', $this->context ) )->posts();
	}

	/**
	 * Related-object condition is an equality on the relationship field and needs a positive id.
	 */
	public function test_where_related(): void {
		$this->make( 1, 5 );
		$b = $this->make( 2, 7 );
		$this->assertSame( array( $b ), $this->q()->whereRelated( 'ctxseats', 7 )->ids() );
		$this->expectException( InvalidArgumentException::class );
		$this->q()->whereRelated( 'ctxseats', 0 );
	}

	/**
	 * Projection returns typed values per matching object in query order, and nulls for missing ones.
	 */
	public function test_values_projection(): void {
		$a = $this->make( 30, 1 );
		$b = $this->make( 10, 2 );
		$this->assertSame(
			array(
				array(
					'id'       => $b,
					'ctxprice' => 10,
					'ctxseats' => 2,
				),
				array(
					'id'       => $a,
					'ctxprice' => 30,
					'ctxseats' => 1,
				),
			),
			$this->q()->orderBy( 'ctxprice' )->values( array( 'ctxprice', 'ctxseats' ) )
		);
		$this->assertSame( array(), ( new NF\Query\Builder( 'nf_ctx_never_stored', $this->context ) )->values( array( 'ctxprice' ) ) );
	}

	/**
	 * Unknown fields are refused.
	 */
	public function test_values_unknown_field_throws(): void {
		$this->make( 1, 1 );
		$this->expectException( InvalidArgumentException::class );
		$this->q()->values( array( 'ctx_missing' ) );
	}

	/**
	 * The wp_query() builder test returns a WP_Query with the page, the live total and page count, without core SQL for the posts.
	 */
	public function test_wp_query_found_posts_and_pages(): void {
		$this->make( 30, 1 );
		$b = $this->make( 10, 2 );
		$c = $this->make( 20, 3 );

		$query = $this->q()->orderBy( 'ctxprice' )->limit( 2 )->wp_query();
		$this->assertInstanceOf( WP_Query::class, $query );
		$this->assertSame( array( $b, $c ), wp_list_pluck( $query->posts, 'ID' ) );
		$this->assertSame( 3, $query->found_posts );
		$this->assertSame( 2, $query->max_num_pages );
		$this->assertTrue( $query->have_posts() );
		$this->assertStringNotContainsString( 'FOUND_ROWS', $GLOBALS['wpdb']->last_query );

		$deep = $this->q()->orderBy( 'ctxprice' )->limit( 2 )->offset( 10 )->wp_query();
		$this->assertSame( array(), $deep->posts );
		$this->assertSame( 3, $deep->found_posts, 'past the end still reports the total' );
		$this->assertSame( 2, $deep->max_num_pages );
	}

	/**
	 * The wp_query() call on a user query throws.
	 */
	public function test_wp_query_on_user_query_throws(): void {
		$this->expectException( LogicException::class );
		( new NF\Query\Builder( 'user', $this->context ) )->wp_query();
	}

	/**
	 * Seek pagination: cursor pages cover the set once, in order, with ties on the sort value.
	 */
	public function test_after_cursor_pages(): void {
		$a = $this->make( 10, 1 );
		$b = $this->make( 20, 1 );
		$c = $this->make( 20, 1 );
		$d = $this->make( 30, 1 );
		$e = $this->make( 40, 1 );
		$q = $this->q()->orderBy( 'ctxprice' )->limit( 2 );

		$page1 = $q->ids();
		$this->assertSame( array( $a, $b ), $page1 );
		$cursor = $q->cursor( $page1[1] );
		$this->assertIsString( $cursor );
		$page2 = $this->q()->orderBy( 'ctxprice' )->limit( 2 )->after( $cursor )->ids();
		$this->assertSame( array( $c, $d ), $page2, 'a tie on price continues with the next id' );
		$page3 = $this->q()->orderBy( 'ctxprice' )->limit( 2 )->after( (string) $q->cursor( $page2[1] ) )->ids();
		$this->assertSame( array( $e ), $page3 );

		$desc = $this->q()->orderBy( 'ctxprice', 'DESC' )->limit( 2 );
		$this->assertSame( array( $e, $d ), $desc->ids() );
		$this->assertSame( array( $c, $b ), $this->q()->orderBy( 'ctxprice', 'DESC' )->limit( 2 )->after( (string) $desc->cursor( $d ) )->ids() );
	}

	/**
	 * A malformed cursor throws.
	 */
	public function test_after_rejects_bad_cursor(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->q()->after( '!!!' );
	}

	/**
	 * Chunking visits every match once and can stop early.
	 */
	public function test_chunk_visits_all(): void {
		$ids = array();
		foreach ( array( 5, 15, 25, 35, 45 ) as $p ) {
			$ids[] = $this->make( $p, 1 );
		}
		$sizes = array();
		$seen  = array();
		$n     = $this->q()->orderBy( 'ctxprice' )->chunk(
			2,
			function ( array $posts ) use ( &$sizes, &$seen ) {
				$sizes[] = count( $posts );
				foreach ( $posts as $post ) {
					$seen[] = $post->ID;
				}
			}
		);
		$this->assertSame( 5, $n );
		$this->assertSame( array( 2, 2, 1 ), $sizes );
		$this->assertSame( $ids, $seen );

		$calls = 0;
		$this->q()->orderBy( 'ctxprice' )->chunk(
			2,
			function () use ( &$calls ) {
				++$calls;
				return false;
			}
		);
		$this->assertSame( 1, $calls );
	}

	/**
	 * The search and tax filters narrow through core ids combined with a field filter.
	 */
	public function test_search_and_tax(): void {
		register_taxonomy( 'nf_ctx_region', self::TYPE );
		$a = $this->make( 10, 1 );
		$b = $this->make( 20, 1 );
		$c = $this->make( 30, 1 );
		wp_update_post(
			array(
				'ID'         => $b,
				'post_title' => 'Jazz night',
			)
		);
		wp_update_post(
			array(
				'ID'         => $c,
				'post_title' => 'Jazz brunch',
			)
		);
		wp_set_object_terms( $a, array( 'varna' ), 'nf_ctx_region' );
		wp_set_object_terms( $c, array( 'varna' ), 'nf_ctx_region' );

		$this->assertSame( array( $b, $c ), $this->q()->search( 'jazz' )->orderBy( 'ctxprice' )->ids() );
		$this->assertSame( array( $c ), $this->q()->search( 'jazz' )->where( 'ctxprice', '>', 20 )->ids() );
		$this->assertSame( array( $a, $c ), $this->q()->tax( 'nf_ctx_region', 'varna' )->orderBy( 'ctxprice' )->ids() );
		$this->assertSame( array( $c ), $this->q()->tax( 'nf_ctx_region', 'varna' )->search( 'jazz' )->ids() );
		$this->assertSame( array(), $this->q()->tax( 'nf_ctx_region', 'missing' )->ids() );
		$this->assertSame( 2, $this->q()->tax( 'nf_ctx_region', 'varna' )->count() );
	}

	/**
	 * Cursors cross NULL sort values in both directions without dropping rows.
	 */
	public function test_after_with_null_sort_values(): void {
		$a = $this->make( 10, 1 );
		$b = $this->make( 20, 1 );
		$n = $this->make( 30, 1 );
		$m = $this->make( 40, 1 );
		nf_db()->execute( 'UPDATE ' . nf_db()->table( 'nf_pivot' ) . ' SET i1 = NULL WHERE pivot_id = ? AND object_id IN (?, ?)', array( $this->pivot_id, $n, $m ) );
		foreach ( array( 'ASC', 'DESC' ) as $dir ) {
			$full = $this->q()->orderBy( 'ctxprice', $dir )->ids();
			$this->assertCount( 4, $full );
			$seen = array();
			$this->q()->orderBy( 'ctxprice', $dir )->chunk(
				1,
				function ( array $posts ) use ( &$seen ) {
					$seen[] = $posts[0]->ID;
				}
			);
			$this->assertSame( $full, $seen, "chunk visits NULL rows ($dir)" );
		}
		$this->assertSame( array( $a, $b ), array_values( array_intersect( $this->q()->orderBy( 'ctxprice' )->ids(), array( $a, $b ) ) ) );
	}
}
