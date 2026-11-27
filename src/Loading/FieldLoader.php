<?php
/**
 * Lazy per-field loader for result sets (WP 3.5b).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Loading;

use NF\Storage\ObjectType;
use NF\Storage\Store;

defined( 'ABSPATH' ) || exit;

/**
 * The first read of a field on any post of a result set loads it for every post of that set (one query per
 * typed table touched, 500 posts per batch) and keeps the values for the request. Values keep the shape
 * Store::get() returns; nothing is wrapped.
 */
final class FieldLoader {

	/**
	 * Values loaded this request, by field key then post ID.
	 *
	 * @var array<string,array<int,mixed>>
	 */
	private array $values = array();

	/**
	 * Constructor.
	 *
	 * @param Store             $store    Store.
	 * @param ResultSetRegistry $registry Registry.
	 */
	public function __construct( private Store $store, private ResultSetRegistry $registry ) {}

	/**
	 * Add hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'nf/loading/flush', array( $this, 'flush' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	/**
	 * Read a post field, loading it for the post's whole result set on first use.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Field key.
	 * @return mixed
	 */
	public function get( int $post_id, string $key ): mixed {
		if ( ! array_key_exists( $post_id, $this->values[ $key ] ?? array() ) ) {
			$batch = $this->registry->batchFor( $post_id, $key );
			foreach ( $this->store->getMany( ObjectType::Post, $batch, $key ) as $id => $value ) {
				$this->values[ $key ][ $id ] = $value;
			}
			$this->registry->markLoaded( $key, $batch );
		}
		return $this->values[ $key ][ $post_id ] ?? null;
	}

	/**
	 * Whether a field's value is already held for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Field key.
	 * @return bool
	 */
	public function has( int $post_id, string $key ): bool {
		return array_key_exists( $post_id, $this->values[ $key ] ?? array() );
	}

	/**
	 * Drop one post's cached values (after a write), or all of them.
	 *
	 * @param int|null $post_id Post ID, or null for everything.
	 * @return void
	 */
	public function forget( ?int $post_id = null ): void {
		if ( null === $post_id ) {
			$this->values = array();
			return;
		}
		foreach ( array_keys( $this->values ) as $key ) {
			unset( $this->values[ $key ][ $post_id ] );
		}
	}

	/**
	 * Flush callback: forget everything.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->forget();
	}
}
