<?php
/**
 * Hybrid mode, small path: NF resolves its own field conditions to IDs and core gets `post__in` (WP 3.3c2a).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Query;

use NF\Install\Upgrader;
use NF\Storage\Status;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.SlowDBQuery -- this class removes meta_key / meta_query from queries; it adds none.

/**
 * Runs on `pre_get_posts` for queries the full short-circuit does not answer. NF conditions (flat `meta_query`
 * clauses, `meta_key`, the `nf` argument and NF-field ordering) are removed from the query and replaced by the IDs the
 * compiler found. Core never reads `wp_postmeta` for an NF field. Anything NF cannot answer exactly returns no rows.
 */
final class Hybrid {

	/**
	 * Default for the most IDs one `post__in` list may hold.
	 */
	public const ID_CAP = 5000;

	/**
	 * IDs per batch when walking NF's sorted list.
	 */
	public const BATCH = 1000;

	/**
	 * Most batches one query may walk.
	 */
	public const WALK_BATCHES = 50;

	/**
	 * Ownership lookups of this request, by post type and key.
	 *
	 * @var array<string,bool>
	 */
	private array $owned = array();

	/**
	 * Totals NF supplied for queries it paged itself.
	 *
	 * @var \WeakMap<\WP_Query,int>|null
	 */
	private ?\WeakMap $totals = null;

	/**
	 * Original `paged` and `offset` of queries NF paged itself, put back once the posts are fetched.
	 *
	 * @var \WeakMap<\WP_Query,array{0:mixed,1:mixed}>|null
	 */
	private ?\WeakMap $restore = null;

	/**
	 * Constructor.
	 *
	 * @param QueryContext $context Query context.
	 * @param PreQuery     $pre     Full short-circuit, asked first.
	 */
	public function __construct( private QueryContext $context, private PreQuery $pre ) {}

	/**
	 * Add hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'pre_get_posts', array( $this, 'apply' ), 9999 );
		add_filter( 'found_posts', array( $this, 'foundPosts' ), 1, 2 );
		add_filter( 'the_posts', array( $this, 'restorePaging' ), 1, 2 );
	}

	/**
	 * Put back the page a query asked for, so `get_query_var( 'paged' )` and pagination links stay right.
	 *
	 * @param mixed     $posts Posts.
	 * @param \WP_Query $query Query.
	 * @return mixed
	 */
	public function restorePaging( $posts, $query ) {
		if ( $query instanceof \WP_Query && null !== $this->restore && isset( $this->restore[ $query ] ) ) {
			list( $paged, $offset ) = $this->restore[ $query ];
			$query->set( 'paged', $paged );
			$query->set( 'offset', $offset );
			unset( $this->restore[ $query ] );
		}
		return $posts;
	}

	/**
	 * Early `found_posts`: the total of a query NF paged itself.
	 *
	 * @param int       $found Count so far.
	 * @param \WP_Query $query Query.
	 * @return int
	 */
	public function foundPosts( $found, $query ) {
		if ( $query instanceof \WP_Query && null !== $this->totals && isset( $this->totals[ $query ] ) ) {
			return $this->totals[ $query ];
		}
		return $found;
	}

	/**
	 * `pre_get_posts` callback.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public function apply( $query ): void {
		if ( ! $query instanceof \WP_Query || ( null !== $query->get( 'nf_mode' ) && '' !== $query->get( 'nf_mode' ) ) ) {
			return;
		}
		$vars = $query->query_vars;
		$type = $this->postType( $vars );
		try {
			$split = $this->split( $vars, $type );
		} catch ( \Throwable $e ) {
			$this->failClosed( $query, 'backend: ' . $e->getMessage() );
			return;
		}
		if ( null === $split ) {
			return; // No NF field in the query: core as usual.
		}
		if ( $this->pre->translatable( $query ) ) {
			return; // The full short-circuit answers it in one statement.
		}
		$this->strip( $query, $split );
		if ( null === $split['args'] || null === $type ) {
			$this->failClosed( $query, 'unsupported NF condition' );
			return;
		}
		if ( ! Upgrader::isCurrent() ) {
			$this->failClosed( $query, 'schema not current' );
			return;
		}
		$cap = max( 1, (int) apply_filters( 'nf/hybrid/id_cap', self::ID_CAP ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$full = null;
		try {
			$spec = ( new ArgsTranslator( array( $this->context, 'fieldKind' ), false ) )->translate( $split['args'] );
			if ( null === $spec ) {
				$this->failClosed( $query, 'unsupported NF condition' );
				return;
			}
			$full = new QuerySpec( $spec->type, $spec->subtype, Status::cases(), $spec->conditions, $spec->orders, 0, 0, $spec->masks, $spec->rows );
			$spec = $this->limited( $full, array(), $cap + 1, 0 );
			if ( null === $this->context->compile( $spec ) ) {
				$this->failClosed( $query, 'no pivot set for the post type' );
				return;
			}
			$ids = $this->context->ids( $spec );
		} catch ( \Throwable $e ) {
			$this->failClosed( $query, 'backend: ' . $e->getMessage() );
			return;
		}
		if ( count( $ids ) > $cap && $full instanceof QuerySpec ) {
			$this->large( $query, $split, $full, $cap );
			return;
		}
		$existing = array_map( 'intval', (array) $query->get( 'post__in' ) );
		if ( array() !== $existing ) {
			$ids = array_values( array_intersect( $ids, $existing ) );
		}
		$query->set( 'post__in', array() === $ids ? array( 0 ) : $ids );
		if ( $split['ordered'] ) {
			$query->set( 'orderby', 'post__in' );
		}
		$query->set( 'nf_mode', 'hybrid' );
	}

	/**
	 * Copy of a spec with extra conditions, a limit and an offset.
	 *
	 * @param QuerySpec   $s      Spec.
	 * @param Condition[] $extra  Conditions to add.
	 * @param int         $limit  Limit.
	 * @param int         $offset Offset.
	 * @return QuerySpec
	 */
	private function limited( QuerySpec $s, array $extra, int $limit, int $offset ): QuerySpec {
		return new QuerySpec( $s->type, $s->subtype, $s->statuses, array_merge( $s->conditions, $extra ), $s->orders, $limit, $offset, $s->masks, $s->rows );
	}

	/**
	 * Large path: the NF condition set is over the ID cap. Start from the smaller side, or page in NF's own order.
	 *
	 * @param \WP_Query           $query Query (NF clauses already stripped).
	 * @param array<string,mixed> $split Split.
	 * @param QuerySpec           $full  NF conditions over every status, no limit.
	 * @param int                 $cap   ID cap.
	 * @return void
	 */
	private function large( \WP_Query $query, array $split, QuerySpec $full, int $cap ): void {
		$vars = $query->query_vars;
		$per  = (int) ( $vars['posts_per_page'] ?? get_option( 'posts_per_page', 10 ) );
		if ( ! empty( $vars['nopaging'] ) || $per < 1 ) {
			$this->failClosed( $query, 'more than ' . $cap . ' ids and no page size' );
			return;
		}
		$offset = isset( $vars['offset'] ) && is_numeric( $vars['offset'] ) && (int) $vars['offset'] > 0 ? (int) $vars['offset'] : ( max( 1, (int) ( $vars['paged'] ?? 1 ) ) - 1 ) * $per;
		$pure   = $this->isPure( $vars );
		try {
			if ( ! $pure ) {
				$core = $this->coreIds( $query, array(), $cap + 1 );
				if ( count( $core ) <= $cap ) {
					// Core side is the smaller one: filter it on the NF side.
					$ids = array() === $core ? array() : $this->context->ids( $this->limited( $full, array( new Condition( 'ID', 'in', $core ) ), $cap + 1, 0 ) );
					$this->finish( $query, $ids, (bool) $split['ordered'] );
					return;
				}
				if ( ! $split['ordered'] || empty( $vars['no_found_rows'] ) || ! is_user_logged_in() ) {
					$this->failClosed( $query, 'both sides over ' . $cap . ' ids' . ( $split['ordered'] ? ' and no totals' : '' ) );
					return;
				}
				$this->walk( $query, $full, $offset, $per );
				return;
			}
			$args = $split['args'];
			if ( ! is_array( $args ) ) {
				$this->failClosed( $query, 'unsupported NF condition' );
				return;
			}
			unset( $args['nopaging'] );
			$args['post_status']    = ! empty( $vars['post_status'] ) ? $vars['post_status'] : 'publish';
			$args['posts_per_page'] = $per;
			$args['offset']         = $offset;
			if ( ! isset( $args['orderby'] ) && ! empty( $vars['orderby'] ) ) {
				$args['orderby'] = $vars['orderby'];
				$args['order']   = $vars['order'] ?? 'DESC';
			}
			$spec = ( new ArgsTranslator( array( $this->context, 'fieldKind' ), is_user_logged_in() ) )->translate( $args );
			if ( null === $spec ) {
				$this->failClosed( $query, 'unsupported NF condition' );
				return;
			}
			$ids = $this->context->ids( $spec );
			if ( empty( $vars['no_found_rows'] ) ) {
				$this->totals         ??= new \WeakMap();
				$this->totals[ $query ] = $this->context->count( $spec );
			}
			$this->finish( $query, $ids, true, true );
		} catch ( \Throwable $e ) {
			$this->failClosed( $query, 'backend: ' . $e->getMessage() );
		}
	}

	/**
	 * Set the final ID list on the query.
	 *
	 * @param \WP_Query $query  Query.
	 * @param int[]     $ids    IDs in the order core must return them.
	 * @param bool      $ordered Whether the IDs are in the wanted order (core must keep it).
	 * @param bool      $paged  Whether the list is already the requested page.
	 * @return void
	 */
	private function finish( \WP_Query $query, array $ids, bool $ordered, bool $paged = false ): void {
		$existing = array_map( 'intval', (array) $query->get( 'post__in' ) );
		if ( array() !== $existing && ! $paged ) {
			$ids = array_values( array_intersect( $ids, $existing ) );
		}
		$query->set( 'post__in', array() === $ids ? array( 0 ) : $ids );
		if ( $ordered ) {
			$query->set( 'orderby', 'post__in' );
		}
		if ( $paged ) {
			$this->restore         ??= new \WeakMap();
			$this->restore[ $query ] = array( $query->get( 'paged' ), $query->get( 'offset' ) );
			$query->set( 'paged', 1 );
			$query->set( 'offset', 0 );
		}
		$query->set( 'nf_mode', 'hybrid' );
	}

	/**
	 * IDs of the core side of the query (its non-NF conditions), unordered, at most `$limit` of them.
	 *
	 * @param \WP_Query $query Query.
	 * @param int[]     $in    Restrict to these IDs (empty = no restriction beyond the query's own).
	 * @param int       $limit Limit.
	 * @return int[]
	 */
	private function coreIds( \WP_Query $query, array $in, int $limit ): array {
		$vars                        = $query->query_vars;
		$vars['fields']              = 'ids';
		$vars['no_found_rows']       = true;
		$vars['ignore_sticky_posts'] = true;
		$vars['nf_mode']             = 'core-side';
		$vars['paged']               = 1;
		$vars['offset']              = 0;
		$vars['nopaging']            = false;
		$vars['posts_per_page']      = $limit;
		$vars['orderby']             = array() === $in ? 'none' : 'post__in';
		if ( array() !== $in ) {
			$own              = array_map( 'intval', (array) ( $vars['post__in'] ?? array() ) );
			$vars['post__in'] = array() === $own ? $in : array_values( array_intersect( $in, $own ) );
			if ( array() === $vars['post__in'] ) {
				return array();
			}
		}
		$sub = new \WP_Query();
		return array_map( 'intval', (array) $sub->query( $vars ) );
	}

	/**
	 * Page through NF's sorted IDs in batches until the page fills, asking core which of each batch qualify.
	 *
	 * @param \WP_Query $query  Query.
	 * @param QuerySpec $full   NF spec, no limit.
	 * @param int       $offset Rows to skip.
	 * @param int       $per    Page size.
	 * @return void
	 */
	private function walk( \WP_Query $query, QuerySpec $full, int $offset, int $per ): void {
		$need = $offset + $per;
		$kept = array();
		$have = 0;
		for ( $pos = 0, $n = 0; $n < self::WALK_BATCHES && $have < $need; $n++, $pos += self::BATCH ) {
			$batch = $this->context->ids( $this->limited( $full, array(), self::BATCH, $pos ) );
			if ( array() === $batch ) {
				break;
			}
			$kept = array_merge( $kept, $this->coreIds( $query, $batch, self::BATCH ) );
			$have = count( $kept );
			if ( count( $batch ) < self::BATCH ) {
				break;
			}
		}
		if ( $have < $need && self::WALK_BATCHES === $n ) {
			$this->failClosed( $query, 'page not filled within ' . ( self::WALK_BATCHES * self::BATCH ) . ' ids' );
			return;
		}
		$this->finish( $query, array_slice( $kept, $offset, $per ), true, true );
	}

	/**
	 * Whether the query has no core conditions besides type, status, order and paging.
	 *
	 * @param array<string,mixed> $vars Query vars (NF clauses stripped).
	 * @return bool
	 */
	private function isPure( array $vars ): bool {
		$ok = array( 'post_type', 'post_status', 'posts_per_page', 'paged', 'offset', 'nopaging', 'order', 'orderby', 'nf_mode', 'nf_count', 'no_found_rows', 'cache_results', 'update_post_term_cache', 'update_post_meta_cache', 'lazy_load_term_meta', 'update_menu_item_cache', 'ignore_sticky_posts', 'suppress_filters', 'fields', 'perm', 'comments_per_page' );
		$zero = array( 'p', 'page', 'page_id', 'attachment_id', 'm', 'year', 'monthnum', 'day', 'w', 'hour', 'minute', 'second', 'tag_id', 'posts_per_archive_page' );
		foreach ( $vars as $name => $value ) {
			if ( in_array( $name, $ok, true ) || in_array( $name, $zero, true ) && ( 0 === $value || '0' === $value ) ) {
				continue;
			}
			if ( null !== $value && false !== $value && '' !== $value && array() !== $value ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Post type to resolve against, or null when the query names none or several.
	 *
	 * @param array<string,mixed> $vars Query vars.
	 * @return string|null
	 */
	private function postType( array $vars ): ?string {
		$t = $vars['post_type'] ?? '';
		if ( is_array( $t ) && 1 === count( $t ) ) {
			$t = reset( $t );
		}
		if ( '' === $t || null === $t ) {
			return 'post';
		}
		return is_string( $t ) && 'any' !== $t ? $t : null;
	}

	/**
	 * Separate the NF part of the query from core's.
	 *
	 * @param array<string,mixed> $vars Query vars.
	 * @param string|null         $type Post type.
	 * @return array{args:array<string,mixed>|null,meta_query:array<mixed>,strip_key:bool,ordered:bool}|null Null when no NF field is involved.
	 */
	private function split( array $vars, ?string $type ): ?array {
		$owns = function ( $key ) use ( $type ): bool {
			if ( null === $type || ! is_string( $key ) || '' === $key ) {
				return false;
			}
			$id = $type . "\0" . $key;
			if ( ! isset( $this->owned[ $id ] ) ) {
				try {
					$this->owned[ $id ] = $this->context->ownsField( $key, $type );
				} catch ( \Throwable $e ) {
					return false; // A failed lookup must not blank unrelated queries.
				}
			}
			return $this->owned[ $id ];
		};
		$meta = is_array( $vars['meta_query'] ?? null ) ? $vars['meta_query'] : array();
		$nf   = array();
		$core = array();
		$bad  = false;
		foreach ( $meta as $k => $clause ) {
			if ( 'relation' === $k ) {
				continue;
			}
			if ( is_array( $clause ) && ! isset( $clause[0] ) && array_key_exists( 'key', $clause ) && is_int( $k ) ) {
				if ( $owns( $clause['key'] ) ) {
					$nf[] = $clause;
				} else {
					$core[ $k ] = $clause;
				}
				continue;
			}
			// Nested group or named clause: fine for core unless an NF key hides inside it.
			if ( $this->mentionsNf( $clause, $owns ) ) {
				$bad = true;
			} else {
				$core[ $k ] = $clause;
			}
		}
		$relation = strtoupper( (string) ( $meta['relation'] ?? 'AND' ) );
		if ( 'AND' !== $relation && ( count( $nf ) > 1 || ( array() !== $nf && array() !== $core ) ) ) {
			$bad = true; // OR across NF clauses, or between an NF clause and a core clause, cannot be split.
		}
		$key     = $vars['meta_key'] ?? '';
		$nf_key  = $owns( $key );
		$nf_arg  = isset( $vars['nf'] ) && is_array( $vars['nf'] ) && array() !== $vars['nf'];
		$orderby = $vars['orderby'] ?? '';
		$ordered = ( $nf_arg && isset( $vars['nf']['orderby'] ) ) || ( $nf_key && is_string( $orderby ) && 1 === preg_match( '/\bmeta_value(_num)?\b/', $orderby ) );
		if ( array() === $nf && ! $nf_key && ! $nf_arg && ! $bad ) {
			return null;
		}
		$args       = null;
		$value_used = false;
		foreach ( array( 'meta_value', 'meta_value_num', 'meta_compare', 'meta_type' ) as $name ) {
			if ( isset( $vars[ $name ] ) && '' !== $vars[ $name ] ) {
				$value_used = true;
			}
		}
		if ( ! $bad && ! ( $nf_key && ( $value_used || is_array( $orderby ) ) ) ) {
			$args = array(
				'post_type'   => $type,
				'post_status' => 'publish',
				'nopaging'    => true,
				'meta_query'  => $nf,
			);
			if ( $nf_key ) {
				$args['meta_key'] = $key;
			}
			if ( $nf_arg ) {
				$args['nf'] = $vars['nf'];
			}
			if ( $ordered && ! ( $nf_arg && isset( $vars['nf']['orderby'] ) ) ) {
				$args['orderby'] = $orderby;
				$args['order']   = $vars['order'] ?? 'DESC';
			}
		}
		$rest = $core;
		if ( count( $core ) > 1 && isset( $meta['relation'] ) ) {
			$rest['relation'] = $meta['relation'];
		}
		return array(
			'args'       => $args,
			'meta_query' => $rest,
			'strip_key'  => $nf_key,
			'ordered'    => $ordered,
		);
	}

	/**
	 * Whether a nested meta_query part names an NF field.
	 *
	 * @param mixed    $part Part.
	 * @param callable $owns Key test.
	 * @return bool
	 */
	private function mentionsNf( mixed $part, callable $owns ): bool {
		if ( ! is_array( $part ) ) {
			return false;
		}
		if ( isset( $part['key'] ) && $owns( $part['key'] ) ) {
			return true;
		}
		foreach ( $part as $child ) {
			if ( is_array( $child ) && $this->mentionsNf( $child, $owns ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remove the NF part from the query so core never sees it.
	 *
	 * @param \WP_Query                                                                                $query Query.
	 * @param array{args:array<string,mixed>|null,meta_query:array<mixed>,strip_key:bool,ordered:bool} $split Split.
	 * @return void
	 */
	private function strip( \WP_Query $query, array $split ): void {
		$query->set( 'meta_query', $split['meta_query'] );
		if ( $split['strip_key'] ) {
			$query->set( 'meta_key', '' );
			$query->set( 'meta_value', '' );
			$query->set( 'meta_value_num', '' );
		}
		$query->set( 'nf', null );
	}

	/**
	 * No rows, logged. Core must not fall back to `wp_postmeta` for an NF field.
	 *
	 * @param \WP_Query $query  Query.
	 * @param string    $reason Reason.
	 * @return void
	 */
	private function failClosed( \WP_Query $query, string $reason ): void {
		$query->set( 'post__in', array( 0 ) );
		$query->set( 'nf_mode', 'hybrid-failed' );
		/**
		 * Fires when a hybrid query returned no rows because NF could not answer it.
		 *
		 * @param string    $reason Reason.
		 * @param \WP_Query $query  Query.
		 */
		do_action( 'nf/hybrid/failed', $reason, $query ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			_doing_it_wrong( __CLASS__, esc_html( 'NF could not answer a query on its fields (' . $reason . '); it returned no rows.' ), '0.1.0' );
		}
	}
}
