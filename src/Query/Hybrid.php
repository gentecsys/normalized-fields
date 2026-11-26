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
	 * Default for the most IDs either side (NF or core) may contribute to an intersection.
	 */
	public const SET_MAX = 500000;

	/**
	 * Rows per read when fetching NF's ID list (filter `nf/hybrid/chunk`, clamped to 50,000).
	 */
	public const CHUNK = 20000;

	/**
	 * Number of cold-intersection slots shared by anonymous web requests (filter `nf/hybrid/cold_slots`).
	 */
	public const COLD_SLOTS = 2;

	/**
	 * Object cache group of intersected ID lists.
	 */
	public const CACHE_GROUP = 'nf_hybrid';

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
	 * Original `paged`, `offset` and `ignore_sticky_posts` of queries NF paged itself, put back once the posts are fetched.
	 *
	 * @var \WeakMap<\WP_Query,array{0:mixed,1:mixed,2:mixed}>|null
	 */
	private ?\WeakMap $restore = null;

	/**
	 * Queries that name NF fields and that the full short-circuit was expected to answer.
	 *
	 * @var \WeakMap<\WP_Query,bool>|null
	 */
	private ?\WeakMap $pending = null;

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
		add_filter( 'posts_pre_query', array( $this, 'guardHandBack' ), 20, 2 );
		add_filter( 'found_posts', array( $this, 'foundPosts' ), 1, 2 );
		add_filter( 'the_posts', array( $this, 'restorePaging' ), 1, 2 );
	}

	/**
	 * Fail closed when the full short-circuit handed back a query that names NF fields (no pivot set, backend error).
	 *
	 * @param mixed     $posts Posts from earlier filters.
	 * @param \WP_Query $query Query.
	 * @return mixed
	 */
	public function guardHandBack( $posts, $query ) {
		if ( ! $query instanceof \WP_Query || null === $this->pending || ! isset( $this->pending[ $query ] ) ) {
			return $posts;
		}
		unset( $this->pending[ $query ] );
		if ( null !== $posts ) {
			return $posts; // Answered by the short-circuit or another plugin.
		}
		$query->set( 'nf_mode', 'hybrid-failed' );
		// Same hook and arguments as failClosed(); the reason is 'short-circuit handed back'.
		do_action( 'nf/hybrid/failed', 'short-circuit handed back', $query ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			_doing_it_wrong( __CLASS__, esc_html( 'NF could not answer a query on its fields (short-circuit handed back); it returned no rows.' ), '0.1.0' );
		}
		$query->found_posts   = 0;
		$query->max_num_pages = 0;
		return array();
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
			list( $paged, $offset, $sticky ) = $this->restore[ $query ];
			$query->set( 'paged', $paged );
			$query->set( 'offset', $offset );
			$query->set( 'ignore_sticky_posts', $sticky );
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
		if ( ! $query instanceof \WP_Query ) {
			return;
		}
		// A reused WP_Query object must not keep the total or paging of its previous run.
		if ( null !== $this->totals ) {
			unset( $this->totals[ $query ] );
		}
		if ( null !== $this->restore ) {
			unset( $this->restore[ $query ] );
		}
		if ( null !== $this->pending ) {
			unset( $this->pending[ $query ] );
		}
		if ( null !== $query->get( 'nf_mode' ) && '' !== $query->get( 'nf_mode' ) ) {
			return;
		}
		$vars = $query->query_vars;
		$type = $this->postType( $vars );
		try {
			$split = $this->split( $vars, $type, $this->candidateTypes( $vars, $type ) );
		} catch ( \Throwable $e ) {
			$this->failClosed( $query, 'backend: ' . $e->getMessage() );
			return;
		}
		if ( null === $split ) {
			return; // No NF field in the query: core as usual.
		}
		if ( $this->pre->translatable( $query ) ) {
			// The full short-circuit answers it; if it hands back anyway, guardHandBack() fails closed.
			$this->pending         ??= new \WeakMap();
			$this->pending[ $query ] = true;
			return;
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
		if ( count( $ids ) > $cap ) {
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
	 * @param array<int,int|string|null>|null $after Seek cursor (replaces the offset), or null.
	 * @return QuerySpec
	 */
	private function limited( QuerySpec $s, array $extra, int $limit, int $offset, ?array $after = null ): QuerySpec {
		return new QuerySpec( $s->type, $s->subtype, $s->statuses, array_merge( $s->conditions, $extra ), $s->orders, $limit, $offset, $s->masks, $s->rows, $after );
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
		try {
			$statuses = $this->isPure( $vars ) ? $this->coreStatuses( $vars, $full->subtype ) : null;
			$spec     = null === $statuses ? null : $this->pureSpec( $split, $vars, $statuses, $per, $offset );
			if ( null !== $spec ) {
				// Nothing on the core side but type, status and paging: NF pages its own list.
				$ids = $this->context->ids( $spec );
				if ( empty( $vars['no_found_rows'] ) ) {
					$this->totals         ??= new \WeakMap();
					$this->totals[ $query ] = $this->context->count( $spec );
				}
				$this->finish( $query, $ids, true, true );
				return;
			}
			$core = $this->coreIds( $query, array(), $cap + 1 );
			if ( count( $core ) <= $cap ) {
				// Core side is the smaller one: filter it on the NF side.
				$ids = array() === $core ? array() : $this->context->ids( $this->limited( $full, array( new Condition( 'ID', 'in', $core ) ), $cap + 1, 0 ) );
				$this->finish( $query, $ids, (bool) $split['ordered'] );
				return;
			}
			$this->intersect( $query, $full, $split, $offset, $per );
		} catch ( \Throwable $e ) {
			$this->failClosed( $query, 'backend: ' . $e->getMessage() );
		}
	}

	/**
	 * Spec for a query NF pages itself, or null when the translator can't express it.
	 *
	 * @param array<string,mixed> $split    Split.
	 * @param array<string,mixed> $vars     Query vars (NF clauses stripped).
	 * @param Status[]            $statuses Statuses core would return for this query and user.
	 * @param int                 $per      Page size.
	 * @param int                 $offset   Rows to skip.
	 * @return QuerySpec|null
	 */
	private function pureSpec( array $split, array $vars, array $statuses, int $per, int $offset ): ?QuerySpec {
		$args = $split['args'];
		if ( ! is_array( $args ) ) {
			return null;
		}
		unset( $args['nopaging'] );
		$args['post_status']    = 'publish'; // Replaced by $statuses below.
		$args['posts_per_page'] = $per;
		$args['offset']         = $offset;
		if ( ! isset( $args['orderby'] ) && ! empty( $vars['orderby'] ) ) {
			$args['orderby'] = $vars['orderby'];
			$args['order']   = $vars['order'] ?? 'DESC';
		}
		$s = ( new ArgsTranslator( array( $this->context, 'fieldKind' ), is_user_logged_in() ) )->translate( $args );
		return null === $s ? null : new QuerySpec( $s->type, $s->subtype, $statuses, $s->conditions, $s->orders, $s->limit, $s->offset, $s->masks, $s->rows, $s->after );
	}

	/**
	 * Statuses core would return for this query and user, or null when NF can't express them as a status list
	 * (another status argument, custom statuses, or the user's own private posts without `read_private_posts`).
	 *
	 * @param array<string,mixed> $vars Query vars.
	 * @param string              $type Post type.
	 * @return Status[]|null
	 */
	private function coreStatuses( array $vars, string $type ): ?array {
		global $wpdb;
		$status = $vars['post_status'] ?? '';
		if ( 'publish' === $status || array( 'publish' ) === $status ) {
			return array( Status::Publish );
		}
		if ( ( '' !== $status && array() !== $status ) || ( ! empty( $vars['perm'] ) && 'readable' !== $vars['perm'] ) ) {
			return null;
		}
		$names = array_values( get_post_stati( array( 'public' => true ) ) );
		if ( is_admin() ) {
			$names = array_merge(
				$names,
				array_values(
					get_post_stati(
						array(
							'protected'              => true,
							'show_in_admin_all_list' => true,
						)
					)
				)
			);
		}
		if ( is_user_logged_in() ) {
			$private = array_values( get_post_stati( array( 'private' => true ) ) );
			$object  = get_post_type_object( $type );
			if ( current_user_can( $object ? $object->cap->read_private_posts : 'read_private_posts' ) ) {
				$names = array_merge( $names, $private );
			} elseif ( array() !== $private ) {
				// Core adds the user's own private posts: a status list can't say "own", so only go on when there are none.
				$in  = implode( ',', array_fill( 0, count( $private ), '%s' ) );
				$own = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->posts} WHERE post_type = %s AND post_author = %d AND post_status IN ($in) LIMIT 1", array_merge( array( $type, get_current_user_id() ), $private ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				if ( null !== $own ) {
					return null;
				}
			}
		}
		$out = array();
		foreach ( array_unique( $names ) as $name ) {
			$code = Status::fromName( (string) $name );
			if ( null === $code ) {
				return null;
			}
			$out[] = $code;
		}
		return $out;
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
			$this->restore[ $query ] = array( $query->get( 'paged' ), $query->get( 'offset' ), $query->get( 'ignore_sticky_posts' ) );
			if ( (int) $query->get( 'paged' ) > 1 ) {
				// Core adds sticky posts on page 1 only; with `paged` forced to 1 it would add them to every page.
				$query->set( 'ignore_sticky_posts', true );
			}
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
	 * @param bool      $keepOrder Keep the query's own order instead of `none`.
	 * @return int[]
	 */
	private function coreIds( \WP_Query $query, array $in, int $limit, bool $keepOrder = false ): array {
		$vars                        = $query->query_vars;
		$vars['fields']              = 'ids';
		$vars['no_found_rows']       = true;
		$vars['ignore_sticky_posts'] = true;
		$vars['nf_mode']             = 'core-side';
		$vars['paged']               = 1;
		$vars['offset']              = 0;
		$vars['nopaging']            = false;
		$vars['posts_per_page']      = $limit;
		if ( ! $keepOrder || array() !== $in ) {
			$vars['orderby'] = array() === $in ? 'none' : 'post__in';
		}
		if ( array() !== $in ) {
			$own              = array_map( 'intval', (array) ( $vars['post__in'] ?? array() ) );
			$vars['post__in'] = array() === $own ? $in : array_values( array_intersect( $in, $own ) );
			if ( array() === $vars['post__in'] ) {
				return array();
			}
		}
		$chunk = max( 1, min( 50000, (int) apply_filters( 'nf/hybrid/chunk', self::CHUNK ) ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		if ( array() !== $in || $limit <= $chunk ) {
			$sub = new \WP_Query();
			return array_map( 'intval', (array) $sub->query( $vars ) );
		}
		return $keepOrder ? $this->coreIdsPaged( $vars, $limit, $chunk ) : $this->coreIdsSeek( $vars, $limit, $chunk );
	}

	/**
	 * Core's IDs in chunks keyed on the ID (order does not matter), so no single read holds the whole list. Stops one past `$limit`.
	 *
	 * @param array<string,mixed> $vars  Query vars of the core-side query.
	 * @param int                 $limit Ceiling.
	 * @param int                 $chunk Rows per read.
	 * @return int[]
	 */
	private function coreIdsSeek( array $vars, int $limit, int $chunk ): array {
		global $wpdb;
		$vars['orderby'] = 'ID';
		$vars['order']   = 'ASC';
		$last            = 0;
		$out             = array();
		$seek            = static function ( string $where ) use ( &$last, $wpdb ): string {
			return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $last );
		};
		while ( count( $out ) < $limit ) {
			$vars['posts_per_page'] = min( $chunk, $limit - count( $out ) );
			add_filter( 'posts_where', $seek );
			try {
				$rows = array_map( 'intval', (array) ( new \WP_Query() )->query( $vars ) );
			} finally {
				remove_filter( 'posts_where', $seek );
			}
			array_push( $out, ...$rows );
			if ( count( $rows ) < $vars['posts_per_page'] ) {
				break;
			}
			if ( (int) end( $rows ) <= $last ) {
				throw new \RuntimeException( 'Core ID read did not advance.' );
			}
			$last = (int) end( $rows );
		}
		return $out;
	}

	/**
	 * Core's IDs in the query's own order, one page of `$chunk` per read (core cannot seek on an arbitrary order). Stops one past `$limit`.
	 *
	 * @param array<string,mixed> $vars  Query vars of the core-side query.
	 * @param int                 $limit Ceiling.
	 * @param int                 $chunk Rows per read.
	 * @return int[]
	 */
	private function coreIdsPaged( array $vars, int $limit, int $chunk ): array {
		global $wpdb;
		if ( is_string( $vars['orderby'] ?? null ) && str_starts_with( strtolower( $vars['orderby'] ), 'rand' ) ) {
			$vars['orderby'] = 'ID'; // A random order cannot be paged; the order of an intersection is not random-stable anyway.
		}
		// Ties in core's order would make OFFSET pages overlap or skip rows: end every read with the ID.
		$tie = static fn ( string $o ): string => '' === trim( $o ) ? "{$wpdb->posts}.ID ASC" : $o . ", {$wpdb->posts}.ID ASC";
		$out  = array();
		$page = 1;
		while ( count( $out ) < $limit ) {
			$vars['posts_per_page'] = $chunk;
			$vars['paged']          = 1;
			$vars['offset']         = ( $page++ - 1 ) * $chunk;
			add_filter( 'posts_orderby', $tie, 999999 );
			try {
				$rows = array_map( 'intval', (array) ( new \WP_Query() )->query( $vars ) );
			} finally {
				remove_filter( 'posts_orderby', $tie, 999999 );
			}
			if ( array() !== $out && $rows === array_slice( $out, -count( $rows ) ) ) {
				throw new \RuntimeException( 'Core ID read did not advance.' );
			}
			array_push( $out, ...$rows );
			if ( count( $rows ) < $chunk ) {
				break;
			}
		}
		return array_slice( $out, 0, $limit );
	}

	/**
	 * Both sides are large: fetch each side's IDs once, intersect them in PHP and page the result, so the total is exact
	 * for every user and every order. With NF ordering the result keeps NF's order; otherwise core's.
	 *
	 * @param \WP_Query           $query  Query (NF clauses already stripped).
	 * @param QuerySpec           $full   NF spec, no limit.
	 * @param array<string,mixed> $split  Split.
	 * @param int                 $offset Rows to skip.
	 * @param int                 $per    Page size.
	 * @return void
	 */
	private function intersect( \WP_Query $query, QuerySpec $full, array $split, int $offset, int $per ): void {
		$max     = max( 1, (int) apply_filters( 'nf/hybrid/set_max', self::SET_MAX ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$ordered = (bool) $split['ordered'];
		$key     = $this->cacheKey( $query, $full, $ordered, $max );
		$all     = null === $key ? false : wp_cache_get( $key, self::CACHE_GROUP );
		if ( ! is_array( $all ) ) {
			$slot = $this->takeSlot();
			if ( false === $slot ) {
				$this->failClosed( $query, 'no cold slot free' );
				$this->markBusy( $query );
				return;
			}
			try {
				$nf = $this->nfIds( $full, $max );
				if ( count( $nf ) > $max ) {
					$this->failClosed( $query, 'NF side over ' . $max . ' ids' );
					return;
				}
				$core = $this->coreIds( $query, array(), $max + 1, ! $ordered );
				if ( count( $core ) > $max ) {
					$this->failClosed( $query, 'core side over ' . $max . ' ids (' . count( $core ) . ' read)' );
					return;
				}
			} finally {
				$this->releaseSlot( $slot );
			}
			if ( $ordered ) {
				$keep = array_flip( $core );
				$all  = array_values( array_filter( $nf, static fn ( int $id ): bool => isset( $keep[ $id ] ) ) );
			} else {
				$keep = array_flip( $nf );
				$all  = array_values( array_filter( $core, static fn ( int $id ): bool => isset( $keep[ $id ] ) ) );
			}
			if ( null !== $key ) {
				wp_cache_set( $key, $all, self::CACHE_GROUP, 300 );
			}
		}
		if ( empty( $query->query_vars['no_found_rows'] ) ) {
			$this->totals         ??= new \WeakMap();
			$this->totals[ $query ] = count( $all );
		}
		$this->finish( $query, array_slice( $all, $offset, $per ), true, true );
	}

	/**
	 * NF's matching IDs in query order, read in chunks so no single result set holds the whole list. Stops one past `$max`.
	 *
	 * @param QuerySpec $full NF spec, no limit.
	 * @param int       $max  Ceiling.
	 * @return int[]
	 */
	private function nfIds( QuerySpec $full, int $max ): array {
		$chunk = max( 1, min( $max + 1, 50000, (int) apply_filters( 'nf/hybrid/chunk', self::CHUNK ) ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$out   = array();
		$after = null;
		while ( count( $out ) <= $max ) {
			$rows = $this->context->ids( $this->limited( $full, array(), min( $chunk, $max + 1 - count( $out ) ), 0, $after ) );
			array_push( $out, ...$rows );
			if ( count( $rows ) < $chunk || count( $out ) > $max ) {
				break;
			}
			// Seek on the last row's sort key and ID: no OFFSET rescan.
			$after = $this->context->cursor( $full, (int) end( $rows ) );
			if ( null === $after ) {
				// The last row vanished mid-read: fall back to an offset rather than return a short list.
				$rows = $this->context->ids( $this->limited( $full, array(), min( $chunk, $max + 1 - count( $out ) ), count( $out ) ) );
				array_push( $out, ...$rows );
				if ( count( $rows ) < $chunk || count( $out ) > $max ) {
					break;
				}
				$after = $this->context->cursor( $full, (int) end( $rows ) );
				if ( null === $after ) {
					throw new \RuntimeException( 'NF ID list changed during the read.' );
				}
			}
		}
		return $out;
	}

	/**
	 * Take a cold-intersection slot. Logged-in users and WP-CLI pass; every other request is limited, cron included: wp-cron.php is public, so cron is not a trusted context.
	 *
	 * @return string|null|false Lock name held, null when no slot is needed, false when every slot is busy.
	 */
	private function takeSlot(): string|null|false {
		// GET_LOCK is re-entrant within one connection: only a second request (another connection) sees a slot as busy.
		if ( is_user_logged_in() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return null;
		}
		$slots = max( 1, min( 16, (int) apply_filters( 'nf/hybrid/cold_slots', self::COLD_SLOTS ) ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		for ( $i = 0; $i < $slots; $i++ ) {
			if ( $this->context->tryLock( 'hybrid_cold_' . $i ) ) {
				return 'hybrid_cold_' . $i;
			}
		}
		return false;
	}

	/**
	 * Mark a response as not cacheable after a busy slot emptied it, and tell the client when to retry.
	 * The page stays a normal 200 with no rows: front-end lists never answer 503.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	private function markBusy( \WP_Query $query ): void {
		$query->set( 'nf_mode', 'hybrid-busy' );
		// Retry-After on a 200 is a hint for clients and caches; no-cache keeps a page cache from storing the empty list.
		$headers = array_merge( wp_get_nocache_headers(), array( 'Retry-After' => '1' ) );
		/**
		 * Headers sent with a busy-slot response (any secondary query makes the whole page uncacheable).
		 *
		 * @param array<string,string> $headers Header name => value.
		 * @param \WP_Query            $query   Query.
		 */
		$headers = (array) apply_filters( 'nf/hybrid/busy_headers', $headers, $query ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		if ( ! headers_sent() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			foreach ( $headers as $name => $value ) {
				header( $name . ': ' . $value );
			}
		}
		/**
		 * Fires when an anonymous hybrid query found every cold slot busy and returned no rows.
		 *
		 * @param \WP_Query $query Query.
		 */
		do_action( 'nf/hybrid/busy', $query ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	/**
	 * Release a slot taken by takeSlot().
	 *
	 * @param string|null $slot Lock name.
	 * @return void
	 */
	private function releaseSlot( ?string $slot ): void {
		if ( null !== $slot ) {
			$this->context->unlock( $slot );
		}
	}

	/**
	 * Cache key of an intersection: the query, the NF side's version and the bounds. Null when the version is unknown.
	 *
	 * @param \WP_Query $query   Query.
	 * @param QuerySpec $full    NF spec.
	 * @param bool      $ordered Whether NF orders the result.
	 * @param int       $max     Per-side ceiling.
	 * @return string|null
	 */
	private function cacheKey( \WP_Query $query, QuerySpec $full, bool $ordered, int $max ): ?string {
		$version = $this->context->version( $full );
		if ( null === $version ) {
			return null;
		}
		$vars = $query->query_vars;
		foreach ( array( 'paged', 'offset', 'posts_per_page', 'nopaging', 'no_found_rows' ) as $name ) {
			unset( $vars[ $name ] );
		}
		if ( ! empty( $vars['meta_query'] ) ) {
			return null; // Meta writes on core-side keys do not bump any cache version.
		}
		$json = wp_json_encode( array( $vars, $full, $ordered, $max, $version, wp_cache_get_last_changed( 'posts' ), wp_cache_get_last_changed( 'terms' ), is_user_logged_in() ? get_current_user_id() : 0 ) );
		return false === $json ? null : 'x:' . md5( $json );
	}

	/**
	 * Whether the query has no core conditions besides type, status, order and paging.
	 *
	 * @param array<string,mixed> $vars Query vars (NF clauses stripped).
	 * @return bool
	 */
	private function isPure( array $vars ): bool {
		$ok   = array( 'post_type', 'post_status', 'posts_per_page', 'paged', 'offset', 'nopaging', 'order', 'orderby', 'nf_mode', 'nf_count', 'no_found_rows', 'cache_results', 'update_post_term_cache', 'update_post_meta_cache', 'lazy_load_term_meta', 'update_menu_item_cache', 'ignore_sticky_posts', 'suppress_filters', 'fields', 'perm', 'comments_per_page' );
		$zero = array( 'p', 'page', 'page_id', 'attachment_id', 'm', 'year', 'monthnum', 'day', 'w', 'hour', 'minute', 'second', 'tag_id', 'posts_per_archive_page' );
		foreach ( $vars as $name => $value ) {
			if ( in_array( $name, $ok, true ) || ( in_array( $name, $zero, true ) && ( 0 === $value || '0' === $value ) ) ) {
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
	 * Post types a query can reach, for deciding whether a meta key is an NF field.
	 *
	 * @param array<string,mixed> $vars Query vars.
	 * @param string|null         $type Single post type, when there is one.
	 * @return string[]
	 */
	private function candidateTypes( array $vars, ?string $type ): array {
		if ( null !== $type ) {
			return array( $type );
		}
		$t = $vars['post_type'] ?? '';
		if ( is_array( $t ) ) {
			return array_values( array_filter( array_map( 'strval', $t ), static fn ( string $s ): bool => '' !== $s ) );
		}
		return array_values( get_post_types( array( 'exclude_from_search' => false ) ) );
	}

	/**
	 * Separate the NF part of the query from core's.
	 *
	 * @param array<string,mixed> $vars Query vars.
	 * @param string|null         $type  Post type, null when the query names none or several.
	 * @param string[]            $types Post types the query can reach; a key owned in any of them counts as NF's.
	 * @return array{args:array<string,mixed>|null,meta_query:array<mixed>,strip_key:bool,ordered:bool}|null Null when no NF field is involved.
	 */
	private function split( array $vars, ?string $type, array $types ): ?array {
		$owns = function ( $key ) use ( $types ): bool {
			if ( ! is_string( $key ) || '' === $key ) {
				return false;
			}
			foreach ( $types as $t ) {
				$id = $t . "\0" . $key;
				if ( ! isset( $this->owned[ $id ] ) ) {
					try {
						$this->owned[ $id ] = $this->context->ownsField( $key, $t );
					} catch ( \Throwable $e ) {
						continue; // A failed lookup must not blank unrelated queries; not cached, so a later call retries.
					}
				}
				if ( $this->owned[ $id ] ) {
					return true;
				}
			}
			return false;
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
