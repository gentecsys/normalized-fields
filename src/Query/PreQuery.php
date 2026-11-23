<?php
/**
 * Answers translatable WP_Query objects from the pivot through posts_pre_query (WP 3.3b).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Query;

use NF\Install\Upgrader;

defined( 'ABSPATH' ) || exit;

/**
 * Full short-circuit mode. The page of IDs comes from the compiler; the total goes through core's own path: the
 * `found_posts_query` statement is neutralised and the count is supplied by an early `found_posts` filter, so core
 * computes `max_num_pages` and other `found_posts` filters run as usual. Anything the translator cannot answer exactly
 * returns the filter value untouched and core runs its own SQL.
 */
final class PreQuery {

	/**
	 * Default cap for `nf_count => capped`: found_posts is the cap plus one.
	 */
	public const CAP = 1000;

	/**
	 * Totals for queries this class answered.
	 *
	 * @var \WeakMap<\WP_Query,int>|null
	 */
	private ?\WeakMap $totals = null;

	/**
	 * Constructor.
	 *
	 * @param QueryContext $context Query context.
	 */
	public function __construct( private QueryContext $context ) {}

	/**
	 * Add hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'posts_pre_query', array( $this, 'answer' ), 10, 2 );
		add_filter( 'found_posts_query', array( $this, 'foundPostsQuery' ), 10, 2 );
		add_filter( 'found_posts', array( $this, 'foundPosts' ), 1, 2 );
	}

	/**
	 * `posts_pre_query` callback.
	 *
	 * @param array<mixed>|null $posts Posts so far; non-null means someone else answered.
	 * @param \WP_Query         $query Query.
	 * @return array<mixed>|null
	 */
	public function answer( $posts, $query ) {
		if ( null !== $posts || ! $query instanceof \WP_Query ) {
			return $posts;
		}
		$result = $this->resolve( $query );
		if ( null === $result && ! empty( $query->query_vars['nf'] ) && null !== $this->args( $query ) ) {
			// Core ignores the `nf` argument and would return every post of the type: fail closed with an empty result.
			$query->set( 'nf_mode', 'unanswerable' );
			return array();
		}
		return $result;
	}

	/**
	 * Whether the full short-circuit can answer this query (same gates as `answer()`, without running it).
	 *
	 * @param \WP_Query $query Query.
	 * @return bool
	 */
	public function translatable( \WP_Query $query ): bool {
		$args = $this->args( $query );
		if ( null === $args || ! Upgrader::isCurrent() ) {
			return false;
		}
		return null !== ( new ArgsTranslator( array( $this->context, 'fieldKind' ), is_user_logged_in() ) )->translate( $args );
	}

	/**
	 * Answer a query, or null when core must.
	 *
	 * @param \WP_Query $query Query.
	 * @return array<mixed>|null
	 */
	private function resolve( \WP_Query $query ): ?array {
		$args = $this->args( $query );
		if ( null === $args || ! Upgrader::isCurrent() ) {
			return null;
		}
		$logged = is_user_logged_in();
		$spec   = ( new ArgsTranslator( array( $this->context, 'fieldKind' ), $logged ) )->translate( $args );
		if ( null === $spec ) {
			return null;
		}
		try {
			$compiled = $this->context->compile( $spec );
			if ( null === $compiled ) {
				return null; // No pivot set for the type yet: core answers.
			}
			$ids = $this->context->ids( $spec );
			$no  = ! empty( $args['no_found_rows'] );
			if ( ! $no && $spec->limit > 0 ) {
				$total = $this->total( $spec, $args, $query, count( $ids ) );
				if ( count( $ids ) > 0 ) {
					$this->totals         ??= new \WeakMap();
					$this->totals[ $query ] = $total;
					// Core does not count after a posts_pre_query answer, so set the total here, through the usual filter.
					$query->found_posts   = (int) apply_filters( 'found_posts', $total, $query );
					$query->max_num_pages = (int) ceil( $query->found_posts / $spec->limit );
				}
			}
		} catch ( \Throwable $e ) {
			return null; // Unsupported or a backend error: core answers.
		}
		$query->set( 'nf_mode', 'short-circuit' );
		if ( 'ids' === ( $args['fields'] ?? '' ) ) {
			return $ids;
		}
		if ( array() !== $ids && ! empty( $args['cache_results'] ) ) {
			_prime_post_caches( $ids, ! empty( $args['update_post_term_cache'] ), ! empty( $args['update_post_meta_cache'] ) );
		}
		return array_values( array_filter( array_map( 'get_post', $ids ) ) );
	}

	/**
	 * Replace core's `SELECT FOUND_ROWS()` for answered queries so it never runs against the wrong statement.
	 *
	 * @param string    $sql   Statement.
	 * @param \WP_Query $query Query.
	 * @return string
	 */
	public function foundPostsQuery( $sql, $query ) {
		if ( $query instanceof \WP_Query && null !== $this->totals && isset( $this->totals[ $query ] ) ) {
			return 'SELECT ' . (int) $this->totals[ $query ];
		}
		return $sql;
	}

	/**
	 * Early `found_posts`: supply the count; core derives `max_num_pages` from it afterwards.
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
	 * Total matches. A capped query reports the cap plus one and flags the query; a request that pages beyond the cap
	 * gets the exact count so a deep link never 404s falsely.
	 *
	 * @param QuerySpec           $spec  Spec.
	 * @param array<string,mixed> $args  Arguments.
	 * @param \WP_Query           $query Query.
	 * @param int                 $page  Rows on this page.
	 * @return int
	 */
	private function total( QuerySpec $spec, array $args, \WP_Query $query, int $page ): int {
		if ( $page > 0 && $page < $spec->limit && 0 === $spec->offset ) {
			return $page; // First page not full: that is the whole set.
		}
		$total = $this->context->count( $spec );
		if ( 'capped' === ( $args['nf_count'] ?? '' ) ) {
			$cap = max( 1, (int) apply_filters( 'nf/count/cap', self::CAP ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			if ( $total > $cap && $spec->offset < $cap ) {
				$query->set( 'nf_count_capped', true );
				return $cap + 1;
			}
		}
		return $total;
	}

	/**
	 * Whether anything is attached to the SQL clause filters of WP_Query.
	 *
	 * @return bool
	 */
	private function hasClauseFilters(): bool {
		foreach ( array( 'posts_where', 'posts_join', 'posts_clauses', 'posts_orderby', 'posts_groupby', 'posts_distinct', 'posts_fields', 'post_limits', 'posts_where_paged', 'posts_join_paged', 'posts_clauses_request', 'posts_request', 'posts_where_request', 'posts_join_request', 'posts_groupby_request', 'posts_orderby_request', 'posts_distinct_request', 'posts_fields_request', 'post_limits_request' ) as $tag ) {
			if ( has_filter( $tag ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Arguments to translate, or null for queries NF must not answer (admin, sticky home, singular and so on).
	 *
	 * @param \WP_Query $query Query.
	 * @return array<string,mixed>|null
	 */
	private function args( \WP_Query $query ): ?array {
		if ( is_admin() || $query->is_singular() || $query->is_search() || $query->is_feed() ) {
			return null;
		}
		$vars = $query->query_vars;
		if ( empty( $vars['suppress_filters'] ) && $this->hasClauseFilters() ) {
			return null; // Third-party clause filters (language, restriction) only apply to core's SQL.
		}
		if ( $query->is_home() && empty( $vars['ignore_sticky_posts'] ) && array() !== (array) get_option( 'sticky_posts', array() ) ) {
			return null; // Sticky posts are core's: it adds them to page 1 after the IDs come back.
		}
		unset( $vars['comments_per_page'] );
		if ( empty( $vars['nf_count'] ) && is_array( $vars['nf'] ?? null ) && isset( $vars['nf']['count'] ) ) {
			$vars['nf_count'] = $vars['nf']['count']; // The NF argument's count mode is the same as nf_count.
		}
		// Core fills these integer arguments with 0 when unset; the translator treats 0 as a real filter.
		foreach ( array( 'p', 'page', 'page_id', 'attachment_id', 'm', 'year', 'monthnum', 'day', 'w', 'hour', 'minute', 'second', 'tag_id', 'posts_per_archive_page' ) as $zero ) {
			if ( isset( $vars[ $zero ] ) && is_numeric( $vars[ $zero ] ) && 0 === (int) $vars[ $zero ] ) {
				unset( $vars[ $zero ] );
			}
		}
		return $vars;
	}
}
