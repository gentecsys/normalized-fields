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
	 * Series rows loaded this request, by field key then post ID.
	 *
	 * @var array<string,array<int,array<int,array<string,mixed>>>>
	 */
	private array $rows = array();

	/**
	 * Constructor.
	 *
	 * @param Store             $store    Store.
	 * @param ResultSetRegistry $registry Registry.
	 * @param Primer|null       $primer   Target primer; when null nothing is primed.
	 * @param Detector|null     $detector N+1 detector; when null nothing is counted.
	 */
	public function __construct( private Store $store, private ResultSetRegistry $registry, private ?Primer $primer = null, private ?Detector $detector = null ) {}

	/**
	 * Add hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'nf/loading/flush', array( $this, 'flush' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		add_action( 'nf/store/changed', array( $this, 'onStoreChanged' ), 10, 2 ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		add_action( 'switch_blog', array( $this, 'onSwitchBlog' ) );
	}

	/**
	 * Read a post field, loading it for the post's whole result set on first use.
	 *
	 * @param int         $post_id Post ID.
	 * @param string      $key     Field key.
	 * @param string|null $type    ACF field type; when set, the batch's targets are primed.
	 * @return mixed
	 */
	public function get( int $post_id, string $key, ?string $type = null ): mixed {
		if ( ! array_key_exists( $post_id, $this->values[ $key ] ?? array() ) ) {
			do_action( 'nf/loading/before_read' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			$batch = $this->registry->batchFor( $post_id, $key );
			if ( 1 === count( $batch ) && ! $this->registry->inSet( $post_id ) ) {
				$this->detector?->note( $key, $post_id );
			}
			try {
				$loaded = $this->store->getMany( ObjectType::Post, $batch, $key );
			} catch ( \InvalidArgumentException ) {
				// Unknown or non-value key: no value, as get_field() returns for it.
				return null;
			}
			foreach ( $loaded as $id => $value ) {
				$this->values[ $key ][ $id ] = $value;
			}
			if ( null !== $type && null !== $this->primer ) {
				$this->primer->prime( $type, $loaded );
			}
			$this->registry->markLoaded( $key, $batch );
		}
		return $this->values[ $key ][ $post_id ] ?? null;
	}

	/**
	 * Read a post's Series rows, loading them for the post's whole result set on first use.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Series field key.
	 * @return array<int,array<string,mixed>> Rows as Store::getRows() returns them; empty for an unknown key.
	 */
	public function rows( int $post_id, string $key ): array {
		if ( ! array_key_exists( $post_id, $this->rows[ $key ] ?? array() ) ) {
			do_action( 'nf/loading/before_read' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			$mark  = 'rows:' . $key;
			$batch = $this->registry->batchFor( $post_id, $mark );
			if ( 1 === count( $batch ) && ! $this->registry->inSet( $post_id ) ) {
				$this->detector?->note( $mark, $post_id );
			}
			try {
				$loaded = $this->store->getRowsMany( ObjectType::Post, $batch, $key );
			} catch ( \InvalidArgumentException ) {
				return array();
			}
			foreach ( $loaded as $id => $rows ) {
				$this->rows[ $key ][ $id ] = $rows;
			}
			$this->registry->markLoaded( $mark, $batch );
		}
		return $this->rows[ $key ][ $post_id ] ?? array();
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
		$this->primer?->flush();
		if ( null === $post_id ) {
			$this->detector?->reset();
			$this->values = array();
			$this->rows   = array();
			return;
		}
		foreach ( array_keys( $this->values ) as $key ) {
			unset( $this->values[ $key ][ $post_id ] );
		}
		foreach ( array_keys( $this->rows ) as $key ) {
			unset( $this->rows[ $key ][ $post_id ] );
		}
	}

	/**
	 * A store write happened: drop that post's held values so the next read sees the new ones.
	 *
	 * @param mixed $object_type Object type value.
	 * @param mixed $object_id   Object ID.
	 * @return void
	 */
	public function onStoreChanged( mixed $object_type, mixed $object_id ): void {
		if ( ObjectType::Post->value === $object_type ) {
			$this->forget( (int) $object_id );
		}
	}

	/**
	 * The current site changed: values and sets belong to the previous site, so everything goes.
	 *
	 * @return void
	 */
	public function onSwitchBlog(): void {
		do_action( 'nf/loading/flush' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
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
