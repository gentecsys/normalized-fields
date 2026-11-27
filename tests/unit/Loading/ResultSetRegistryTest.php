<?php
/**
 * Result-set registry (WP 3.5a).
 *
 * @package NF
 */

use NF\Loading\QueryWatcher;
use NF\Loading\ResultSetRegistry;

/**
 * Sets, batches, loaded marks and the query watcher.
 */
class ResultSetRegistryTest extends WP_UnitTestCase {

	public function test_post_outside_any_set_loads_alone(): void {
		$r = new ResultSetRegistry();
		$this->assertSame( array( 7 ), $r->batchFor( 7, 'f' ) );
		$r->register( array( 7 ) );
		$this->assertFalse( $r->inSet( 7 ), 'A one-post set is not kept.' );
	}

	public function test_batch_is_the_whole_set_in_order(): void {
		$r = new ResultSetRegistry();
		$r->register( array( 3, 1, 2, 3 ) );
		$this->assertSame( array( 3, 1, 2 ), $r->batchFor( 1, 'f' ) );
	}

	public function test_loaded_posts_are_left_out_per_field(): void {
		$r = new ResultSetRegistry();
		$r->register( array( 1, 2, 3 ) );
		$r->markLoaded( 'a', array( 1, 2 ) );
		$this->assertSame( array( 3 ), $r->batchFor( 3, 'a' ) );
		$this->assertSame( array( 1, 2, 3 ), $r->batchFor( 3, 'b' ) );
		$this->assertTrue( $r->isLoaded( 'a', 1 ) );
		$this->assertFalse( $r->isLoaded( 'b', 1 ) );
	}

	public function test_most_recent_set_wins(): void {
		$r = new ResultSetRegistry();
		$r->register( array( 1, 2, 3 ) );
		$r->register( array( 3, 4 ) );
		$this->assertSame( array( 3, 4 ), $r->batchFor( 3, 'f' ) );
		$this->assertSame( array( 1, 2, 3 ), $r->batchFor( 1, 'f' ) );
	}

	public function test_large_sets_load_in_chunks(): void {
		$r   = new ResultSetRegistry();
		$ids = range( 1, 1200 );
		$r->register( $ids );
		$this->assertCount( 500, $r->batchFor( 1, 'f' ) );
		$this->assertSame( 501, $r->batchFor( 700, 'f' )[0] );
		$this->assertCount( 200, $r->batchFor( 1100, 'f' ) );
	}

	public function test_oldest_sets_are_dropped(): void {
		$r = new ResultSetRegistry();
		for ( $i = 0; $i < ResultSetRegistry::MAX_SETS + 1; $i++ ) {
			$r->register( array( $i * 10 + 1, $i * 10 + 2 ) );
		}
		$this->assertFalse( $r->inSet( 1 ) );
		$this->assertTrue( $r->inSet( ResultSetRegistry::MAX_SETS * 10 + 1 ) );
	}

	public function test_flush_forgets_everything(): void {
		$r = new ResultSetRegistry();
		$r->register( array( 1, 2 ) );
		$r->markLoaded( 'f', array( 1 ) );
		$r->flush();
		$this->assertFalse( $r->inSet( 1 ) );
		$this->assertFalse( $r->isLoaded( 'f', 1 ) );
	}

	public function test_watcher_registers_wp_query_posts_even_with_suppress_filters(): void {
		$ids = self::factory()->post->create_many( 3 );
		$reg = new ResultSetRegistry();
		( new QueryWatcher( $reg ) )->register();
		$posts = get_posts( array( 'include' => $ids, 'orderby' => 'post__in', 'suppress_filters' => false ) );
		$q     = new WP_Query( array( 'post__in' => $ids, 'orderby' => 'post__in', 'posts_per_page' => 10 ) );
		$this->assertCount( 3, $posts );
		$this->assertSame( $ids, $reg->batchFor( $ids[1], 'f' ) );
		unset( $q );
	}

	public function test_watcher_ignores_singular_queries_and_keeps_posts_unchanged(): void {
		$id  = self::factory()->post->create();
		$reg = new ResultSetRegistry();
		$w   = new QueryWatcher( $reg );
		$q   = new WP_Query( array( 'p' => $id ) );
		$this->assertSame( array( 'x' ), $w->watch( array( 'x' ), $q ) );
		$this->assertFalse( $reg->inSet( $id ) );
	}
}
