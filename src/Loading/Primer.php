<?php
/**
 * Target primers for relation, media and thumbnail fields (WP 3.5c).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Loading;

defined( 'ABSPATH' ) || exit;

/**
 * Warms WordPress caches for the objects a batch of field values points at, so reading them later costs no
 * extra queries: one batched call per target kind. Each target ID is primed once per request.
 */
final class Primer {

	/**
	 * Field types whose values are post IDs.
	 */
	private const POST_TYPES = array( 'post_object', 'relationship', 'page_link' );

	/**
	 * Field types whose values are attachment IDs.
	 */
	private const MEDIA_TYPES = array( 'image', 'file', 'gallery' );

	/**
	 * IDs already primed this request, by kind.
	 *
	 * @var array<string,array<int,true>>
	 */
	private array $done = array();

	/**
	 * Prime the targets of a batch of values of one field type.
	 *
	 * @param string            $type   ACF field type.
	 * @param array<int,mixed>  $values Values by post ID, as the store returns them.
	 * @return void
	 */
	public function prime( string $type, array $values ): void {
		$ids = self::ids( $values );
		if ( array() === $ids ) {
			return;
		}
		if ( in_array( $type, self::POST_TYPES, true ) ) {
			$this->primePosts( $ids );
		} elseif ( in_array( $type, self::MEDIA_TYPES, true ) ) {
			$this->primeMedia( $ids );
		} elseif ( 'user' === $type ) {
			$this->primeUsers( $ids );
		} elseif ( 'taxonomy' === $type ) {
			$this->primeTerms( $ids );
		}
	}

	/**
	 * Prime featured images for a set of posts.
	 *
	 * @param int[] $post_ids Post IDs.
	 * @return void
	 */
	public function primeThumbnails( array $post_ids ): void {
		$ids = $this->fresh( 'thumb', array_map( 'intval', $post_ids ) );
		if ( array() === $ids ) {
			return;
		}
		// Loads _thumbnail_id meta for the set and primes the attachment posts and their meta.
		update_meta_cache( 'post', $ids );
		$thumbs = array();
		foreach ( $ids as $id ) {
			$thumb = (int) get_post_meta( $id, '_thumbnail_id', true );
			if ( $thumb > 0 ) {
				$thumbs[] = $thumb;
			}
		}
		$this->primeMedia( $thumbs );
	}

	/**
	 * Forget what was primed (new request scope, or after a flush).
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->done = array();
	}

	/**
	 * Flatten values into unique positive integer IDs.
	 *
	 * @param mixed $values Scalar, ID list or nested arrays.
	 * @return int[]
	 */
	public static function ids( mixed $values ): array {
		$out = array();
		$walk = static function ( mixed $v ) use ( &$walk, &$out ): void {
			if ( is_array( $v ) ) {
				foreach ( $v as $item ) {
					$walk( $item );
				}
			} elseif ( ( is_int( $v ) || ( is_string( $v ) && ctype_digit( $v ) ) ) && (int) $v > 0 ) {
				$out[ (int) $v ] = (int) $v;
			}
		};
		$walk( $values );
		return array_values( $out );
	}

	/**
	 * Post targets: post cache, then their meta and term caches.
	 *
	 * @param int[] $ids Post IDs.
	 * @return void
	 */
	private function primePosts( array $ids ): void {
		$ids = $this->fresh( 'post', $ids );
		if ( array() !== $ids ) {
			_prime_post_caches( $ids, true, true );
		}
	}

	/**
	 * Attachments: posts plus their meta (file, sizes).
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return void
	 */
	private function primeMedia( array $ids ): void {
		$ids = $this->fresh( 'media', $ids );
		if ( array() !== $ids ) {
			_prime_post_caches( $ids, false, true );
		}
	}

	/**
	 * Users in one query.
	 *
	 * @param int[] $ids User IDs.
	 * @return void
	 */
	private function primeUsers( array $ids ): void {
		$ids = $this->fresh( 'user', $ids );
		if ( array() !== $ids ) {
			cache_users( $ids );
		}
	}

	/**
	 * Terms in one query.
	 *
	 * @param int[] $ids Term IDs.
	 * @return void
	 */
	private function primeTerms( array $ids ): void {
		$ids = $this->fresh( 'term', $ids );
		if ( array() !== $ids ) {
			_prime_term_caches( $ids );
		}
	}

	/**
	 * Keep only IDs not primed yet, and mark them.
	 *
	 * @param string $kind Target kind.
	 * @param int[]  $ids  IDs.
	 * @return int[]
	 */
	private function fresh( string $kind, array $ids ): array {
		$new = array();
		foreach ( $ids as $id ) {
			if ( ! isset( $this->done[ $kind ][ $id ] ) ) {
				$this->done[ $kind ][ $id ] = true;
				$new[]                      = $id;
			}
		}
		return $new;
	}
}
