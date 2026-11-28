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
	 * Queries waiting for their first read.
	 *
	 * @var \WP_Query[]
	 */
	private array $pending = array();

	/**
	 * Signatures of the ID lists already registered as sets.
	 *
	 * @var array<string,true>
	 */
	private array $registered = array();

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
		add_filter( 'posts_pre_query', array( $this, 'onPreQuery' ), PHP_INT_MAX, 2 );
		add_action( 'nf/loading/flush', array( $this, 'forgetSets' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		add_action( 'nf/loading/before_read', array( $this, 'runPending' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	/**
	 * Queries run with `suppress_filters` (get_posts()) never reach `the_posts`; their paths run on the first read.
	 *
	 * @param mixed $posts Posts so far.
	 * @param mixed $query Query.
	 * @return mixed Unchanged.
	 */
	public function onPreQuery( $posts, $query ) {
		if ( $query instanceof \WP_Query && $query->get( 'suppress_filters' ) && ! empty( $query->get( 'nf_with' ) ) && count( $this->pending ) < 16 ) {
			$this->pending[] = $query;
		}
		return $posts;
	}

	/**
	 * Flush callback: the registry forgot its sets, so they may be registered again.
	 *
	 * @return void
	 */
	public function forgetSets(): void {
		$this->registered = array();
	}

	/**
	 * Run the paths of the recorded queries whose posts are now loaded.
	 *
	 * @return void
	 */
	public function runPending(): void {
		$queries       = $this->pending;
		$this->pending = array();
		foreach ( $queries as $query ) {
			if ( is_array( $query->posts ) && array() !== $query->posts ) {
				$this->onPosts( $query->posts, $query );
			}
		}
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
		$this->pending = array_values( array_filter( $this->pending, static fn( $q ): bool => $q !== $query ) );
		$paths         = $query->get( 'nf_with' );
		if ( is_string( $paths ) ) {
			$paths = array_map( 'trim', explode( ',', $paths ) );
		}
		if ( ! is_array( $paths ) || array() === $paths ) {
			return $posts;
		}
		$ids = array();
		foreach ( $posts as $post ) {
			$ids[] = $post instanceof \WP_Post ? $post->ID : ( is_object( $post ) ? (int) ( $post->ID ?? 0 ) : (int) $post );
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
	 * @param string   $series Series key when the step is a sub-field, '' otherwise.
	 * @return void
	 */
	private function walk( array $ids, array $steps, string $series ): void {
		$name = (string) array_shift( $steps );
		/**
		 * Describe a field by name for eager loading.
		 *
		 * @param array{key:string,type:string}|null $field Field, or null when unknown.
		 * @param array{name:string,parent:string}   $query Field name and the Series key it sits in.
		 */
		$field = apply_filters(
			'nf/loading/describe_field', // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			null,
			array(
				'name'   => $name,
				'parent' => $series,
			)
		);
		if ( ! is_array( $field ) || ! isset( $field['key'], $field['type'] ) ) {
			return;
		}
		$key  = (string) $field['key'];
		$type = (string) $field['type'];
		$sig  = md5( implode( ',', $ids ) );
		if ( ! isset( $this->registered[ $sig ] ) ) {
			$this->registered[ $sig ] = true;
			$this->registry->register( $ids );
		}
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
	 * @param string                                    $series   Series key.
	 * @return void
	 */
	private function walkRows( array $per_post, array $steps, string $series ): void {
		$name  = (string) array_shift( $steps );
		$field = apply_filters(
			'nf/loading/describe_field', // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			null,
			array(
				'name'   => $name,
				'parent' => $series,
			)
		);
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
