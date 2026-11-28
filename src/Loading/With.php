<?php
/**
 * Explicit eager loading: dotted paths over relations and Series sub-fields (WP 3.5d2).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Loading;

defined( 'ABSPATH' ) || exit;

/**
 * `With::run( $ids, array( 'venue', 'artists.photo', 'sessions.speaker', 'thumbnail' ) )` loads every path for
 * the whole ID list: each step is one batch through the loader, and the IDs a relation step returns become the
 * set for the next step. The same list works as the `nf_with` argument of WP_Query and get_posts().
 *
 * Field names are turned into keys and types by the `nf/loading/describe_field` filter, which receives
 * `array( 'name' => ..., 'parent' => <Series key or ''> )` and returns `array( 'key' => ..., 'type' => ... )`
 * (the ACF read path supplies it). A step nothing describes is skipped.
 */
final class With {

	/**
	 * Relation types whose values are post IDs the next path step can load fields for.
	 *
	 * @var string[]
	 */
	private const POST_RELATIONS = array( 'post_object', 'relationship', 'page_link', 'image', 'file', 'gallery' );

	/**
	 * Constructor.
	 *
	 * @param FieldLoader       $loader   Loader.
	 * @param Primer            $primer   Primer.
	 * @param ResultSetRegistry $registry Registry; each step's IDs are registered as a set so the loader batches them.
	 */
	public function __construct( private FieldLoader $loader, private Primer $primer, private ResultSetRegistry $registry ) {}

	/**
	 * Add hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'the_posts', array( $this, 'onPosts' ), 10, 2 );
	}

	/**
	 * `the_posts` callback: runs the `nf_with` query var of a WP_Query.
	 *
	 * @param mixed $posts Posts.
	 * @param mixed $query Query.
	 * @return mixed Unchanged.
	 */
	public function onPosts( $posts, $query ) {
		if ( ! $query instanceof \WP_Query || ! is_array( $posts ) || array() === $posts ) {
			return $posts;
		}
		$paths = $query->get( 'nf_with' );
		if ( is_string( $paths ) ) {
			$paths = array_map( 'trim', explode( ',', $paths ) );
		}
		if ( ! is_array( $paths ) || array() === $paths ) {
			return $posts;
		}
		$ids = array();
		foreach ( $posts as $post ) {
			$ids[] = $post instanceof \WP_Post ? $post->ID : (int) $post;
		}
		$this->run( $ids, array_map( 'strval', $paths ) );
		return $posts;
	}

	/**
	 * Load paths for a list of post IDs.
	 *
	 * @param int[]    $ids   Post IDs.
	 * @param string[] $paths Dotted paths, or `thumbnail`.
	 * @return void
	 */
	public function run( array $ids, array $paths ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( array() === $ids ) {
			return;
		}
		foreach ( $paths as $path ) {
			$path = trim( sanitize_text_field( $path ) );
			if ( 'thumbnail' === $path ) {
				$this->primer->primeThumbnails( $ids );
				continue;
			}
			if ( '' !== $path ) {
				$this->walk( $ids, explode( '.', $path ), '' );
			}
		}
	}

	/**
	 * Load one step for the IDs, then continue with the IDs it points to.
	 *
	 * @param int[]    $ids    Post IDs holding the step's field.
	 * @param string[] $steps  Remaining path steps.
	 * @param string   $parent Series key when the step is a sub-field, '' otherwise.
	 * @return void
	 */
	private function walk( array $ids, array $steps, string $parent ): void {
		$name = (string) array_shift( $steps );
		/**
		 * Describe a field by name for eager loading.
		 *
		 * @param array{key:string,type:string}|null $field Field, or null when unknown.
		 * @param array{name:string,parent:string}   $query Field name and the Series key it sits in.
		 */
		$field = apply_filters( 'nf/loading/describe_field', null, array( 'name' => $name, 'parent' => $parent ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		if ( ! is_array( $field ) || ! isset( $field['key'], $field['type'] ) ) {
			return;
		}
		$key  = (string) $field['key'];
		$type = (string) $field['type'];
		$this->registry->register( $ids );
		if ( 'repeater' === $type ) {
			$next = array();
			foreach ( $ids as $id ) {
				$next[] = $this->loader->rows( $id, $key );
			}
			if ( array() !== $steps ) {
				$this->walkRows( $next, $steps, $key );
			}
			return;
		}
		$values = array();
		foreach ( $ids as $id ) {
			$values[ $id ] = $this->loader->get( $id, $key, $type );
		}
		if ( array() !== $steps && in_array( $type, self::POST_RELATIONS, true ) ) {
			$this->walk( Primer::ids( $values ), $steps, '' );
		}
	}

	/**
	 * Continue a path inside Series rows: the sub-field's values across every row become the set for the next step.
	 *
	 * @param array<int,array<int,array<string,mixed>>> $per_post Rows per post, in the order of the walked IDs.
	 * @param string[]                                  $steps    Remaining path steps; the first names a sub-field.
	 * @param string                                    $parent   Series key.
	 * @return void
	 */
	private function walkRows( array $per_post, array $steps, string $parent ): void {
		$name  = (string) array_shift( $steps );
		$field = apply_filters( 'nf/loading/describe_field', null, array( 'name' => $name, 'parent' => $parent ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		if ( ! is_array( $field ) || ! isset( $field['type'] ) ) {
			return;
		}
		$values = array();
		foreach ( $per_post as $rows ) {
			foreach ( $rows as $row ) {
				if ( isset( $row[ $name ] ) ) {
					$values[] = $row[ $name ];
				}
			}
		}
		$this->primer->prime( (string) $field['type'], $values );
		if ( array() !== $steps && in_array( (string) $field['type'], self::POST_RELATIONS, true ) ) {
			$this->walk( Primer::ids( $values ), $steps, '' );
		}
	}
}
