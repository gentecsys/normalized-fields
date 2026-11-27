<?php
/**
 * Request-level registry of result sets, keyed by post ID (WP 3.5a).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Loading;

defined( 'ABSPATH' ) || exit;

/**
 * Remembers which post IDs were returned together, so a field first read on one post can load for the whole set.
 * Nothing is attached to WP_Post objects. The registry only holds IDs and per-field "loaded" marks.
 */
final class ResultSetRegistry {

	/**
	 * Most IDs handed out for one batch; larger sets load in chunks.
	 */
	public const BATCH_CAP = 500;

	/**
	 * Most sets kept; the oldest are dropped first.
	 */
	public const MAX_SETS = 64;

	/**
	 * Most watched queries kept before they are read; the oldest are dropped first.
	 */
	public const MAX_PENDING = 32;

	/**
	 * Registered sets, oldest first.
	 *
	 * @var array<int,int[]>
	 */
	private array $sets = array();

	/**
	 * Post ID to the keys of the sets holding it.
	 *
	 * @var array<int,int[]>
	 */
	private array $index = array();

	/**
	 * Next set key.
	 *
	 * @var int
	 */
	private int $next = 0;

	/**
	 * Loaded marks: field => post ID => true.
	 *
	 * @var array<string,array<int,bool>>
	 */
	private array $loaded = array();

	/**
	 * Queries whose posts are read when first needed (they may not have run yet when registered). Held strongly, so a
	 * query built and dropped inside a helper such as get_posts() is still read; capped at MAX_PENDING.
	 *
	 * @var \WP_Query[]
	 */
	private array $pending = array();

	/**
	 * Register a set of post IDs. Empty and single-ID sets are ignored (nothing to batch).
	 *
	 * @param int[] $ids Post IDs in result order.
	 * @return void
	 */
	public function register( array $ids ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );
		if ( count( $ids ) < 2 ) {
			return;
		}
		$key                = $this->next++;
		$this->sets[ $key ] = $ids;
		foreach ( $ids as $id ) {
			$this->index[ $id ][] = $key;
		}
		$kept = count( $this->sets );
		while ( $kept-- > self::MAX_SETS ) {
			$old = (int) array_key_first( $this->sets );
			foreach ( $this->sets[ $old ] as $id ) {
				$this->index[ $id ] = array_values( array_diff( $this->index[ $id ] ?? array(), array( $old ) ) );
				if ( array() === $this->index[ $id ] ) {
					unset( $this->index[ $id ] );
				}
			}
			unset( $this->sets[ $old ] );
		}
	}

	/**
	 * Remember a WP_Query; its posts are read when a field is first accessed.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public function watch( \WP_Query $query ): void {
		$this->pending[] = $query;
		if ( count( $this->pending ) > self::MAX_PENDING ) {
			array_shift( $this->pending );
		}
	}

	/**
	 * Register the posts of every watched query that has run.
	 *
	 * @return void
	 */
	public function collect(): void {
		$still = array();
		foreach ( $this->pending as $query ) {
			if ( ! is_array( $query->posts ) || array() === $query->posts ) {
				if ( null === $query->posts || $query->found_posts > 0 ) {
					$still[] = $query; // Has not run yet.
				}
				continue; // Ran and found nothing: drop.
			}
			$ids = array();
			foreach ( $query->posts as $post ) {
				$ids[] = $post instanceof \WP_Post ? $post->ID : ( is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post );
			}
			$this->register( $ids );
		}
		$this->pending = $still;
	}

	/**
	 * The IDs to load a field for, when it is first read on one post.
	 *
	 * Uses the most recent set holding the post and leaves out posts that already have the field, then returns the
	 * chunk of at most BATCH_CAP IDs that holds the post. A post in no set loads alone.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field name.
	 * @return int[]
	 */
	public function batchFor( int $post_id, string $field ): array {
		$this->collect();
		$keys = $this->index[ $post_id ] ?? array();
		if ( array() === $keys ) {
			return array( $post_id );
		}
		rsort( $keys );
		$todo = array( $post_id );
		foreach ( $keys as $key ) {
			$todo = array_values( array_filter( $this->sets[ $key ], fn( int $id ): bool => $id === $post_id || empty( $this->loaded[ $field ][ $id ] ) ) );
			if ( count( $todo ) > 1 ) {
				break; // The newest set that still has posts to load.
			}
		}
		$pos = (int) array_search( $post_id, $todo, true );
		return array_slice( $todo, intdiv( $pos, self::BATCH_CAP ) * self::BATCH_CAP, self::BATCH_CAP );
	}

	/**
	 * Whether a post sits in a set of more than one post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function inSet( int $post_id ): bool {
		$this->collect();
		return isset( $this->index[ $post_id ] );
	}

	/**
	 * Mark a field as loaded for posts.
	 *
	 * @param string $field Field name.
	 * @param int[]  $ids   Post IDs.
	 * @return void
	 */
	public function markLoaded( string $field, array $ids ): void {
		foreach ( $ids as $id ) {
			$this->loaded[ $field ][ (int) $id ] = true;
		}
	}

	/**
	 * Whether a field is marked loaded for a post.
	 *
	 * @param string $field   Field name.
	 * @param int    $post_id Post ID.
	 * @return bool
	 */
	public function isLoaded( string $field, int $post_id ): bool {
		return ! empty( $this->loaded[ $field ][ $post_id ] );
	}

	/**
	 * Forget everything (between chunks, or when the object cache is flushed).
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->sets    = array();
		$this->index   = array();
		$this->loaded  = array();
		$this->pending = array();
	}
}
