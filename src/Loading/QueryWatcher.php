<?php
/**
 * Registers every WP_Query result with the result-set registry (WP 3.5a).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Loading;

defined( 'ABSPATH' ) || exit;

/**
 * `posts_pre_query` fires even with `suppress_filters` (as in `get_posts()`), at the last priority so queries NF
 * answers itself are seen too. It only keeps a capped reference to the query and returns the posts unchanged: no query, no write.
 */
final class QueryWatcher {

	/**
	 * Constructor.
	 *
	 * @param ResultSetRegistry $registry Registry.
	 */
	public function __construct( private ResultSetRegistry $registry ) {}

	/**
	 * Add hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'posts_pre_query', array( $this, 'watch' ), PHP_INT_MAX, 2 );
		add_action( 'nf/loading/flush', array( $this, 'flush' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	/**
	 * `posts_pre_query` callback.
	 *
	 * @param mixed $posts Posts so far.
	 * @param mixed $query Query.
	 * @return mixed Unchanged.
	 */
	public function watch( $posts, $query ) {
		if ( $query instanceof \WP_Query && ! $query->is_singular() ) {
			$this->registry->watch( $query );
		}
		return $posts;
	}

	/**
	 * `nf/loading/flush` action callback.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->registry->flush();
	}
}
