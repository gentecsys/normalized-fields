<?php
/**
 * Primer (WP 3.5c).
 *
 * @package NF
 */

use NF\Loading\Primer;

/**
 * Target priming for relations, media, users, terms and thumbnails.
 */
class PrimerTest extends WP_UnitTestCase {

	/**
	 * IDs are flattened, deduplicated and filtered.
	 *
	 * @return void
	 */
	public function test_ids_flatten_and_dedupe(): void {
		$this->assertSame( array( 3, 4, 9 ), Primer::ids( array( 1 => array( 3, '4', 0, 'x', null ), 2 => array( array( 9, 3 ) ) ) ) );
	}

	/**
	 * Post targets cost no queries to read after priming.
	 *
	 * @return void
	 */
	public function test_post_targets_are_primed_in_one_batch(): void {
		$ids = self::factory()->post->create_many( 5 );
		wp_cache_flush();
		( new Primer() )->prime( 'relationship', array( 1 => $ids ) );
		$before = get_num_queries();
		foreach ( $ids as $id ) {
			get_post( $id );
		}
		$this->assertSame( $before, get_num_queries() );
	}

	/**
	 * Users and terms are primed too.
	 *
	 * @return void
	 */
	public function test_user_and_term_targets_are_primed(): void {
		$users = self::factory()->user->create_many( 3 );
		$terms = self::factory()->term->create_many( 3 );
		wp_cache_flush();
		$p = new Primer();
		$p->prime( 'user', array( 1 => $users ) );
		$p->prime( 'taxonomy', array( 1 => $terms ) );
		$before = get_num_queries();
		foreach ( $users as $id ) {
			get_userdata( $id );
		}
		$this->assertSame( $before, get_num_queries() );
	}

	/**
	 * Featured images are primed for a set of posts.
	 *
	 * @return void
	 */
	public function test_thumbnails_are_primed_for_a_set(): void {
		$posts = self::factory()->post->create_many( 4 );
		$atts  = array();
		foreach ( $posts as $post ) {
			$att = self::factory()->attachment->create( array( 'post_parent' => $post ) );
			set_post_thumbnail( $post, $att );
			$atts[] = $att;
		}
		wp_cache_flush();
		( new Primer() )->primeThumbnails( $posts );
		$before = get_num_queries();
		foreach ( $posts as $post ) {
			get_post_thumbnail_id( $post );
		}
		foreach ( $atts as $att ) {
			get_post( $att );
		}
		$this->assertSame( $before, get_num_queries() );
	}

	/**
	 * A target is primed once per request.
	 *
	 * @return void
	 */
	public function test_target_is_primed_once(): void {
		$ids = self::factory()->post->create_many( 2 );
		$p   = new Primer();
		$p->prime( 'post_object', array( 1 => $ids ) );
		wp_cache_flush();
		$before = get_num_queries();
		$p->prime( 'post_object', array( 1 => $ids ) );
		$this->assertSame( $before, get_num_queries() );
	}
}
