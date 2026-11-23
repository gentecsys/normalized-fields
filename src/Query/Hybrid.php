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
	}

	/**
	 * `pre_get_posts` callback.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public function apply( $query ): void {
		if ( ! $query instanceof \WP_Query || null !== $query->get( 'nf_mode' ) && '' !== $query->get( 'nf_mode' ) ) {
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
		try {
			$spec = ( new ArgsTranslator( array( $this->context, 'fieldKind' ), false ) )->translate( $split['args'] );
			if ( null === $spec ) {
				$this->failClosed( $query, 'unsupported NF condition' );
				return;
			}
			$spec = new QuerySpec( $spec->type, $spec->subtype, array_values( Status::cases() ), $spec->conditions, $spec->orders, 0, 0, $spec->masks, $spec->rows );
			$ids  = null === $this->context->compile( $spec ) ? array() : $this->context->ids( $spec );
		} catch ( \Throwable $e ) {
			$this->failClosed( $query, 'backend: ' . $e->getMessage() );
			return;
		}
		$cap = max( 1, (int) apply_filters( 'nf/hybrid/id_cap', self::ID_CAP ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		if ( count( $ids ) > $cap ) {
			$this->failClosed( $query, 'more than ' . $cap . ' ids' );
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
		$owns = fn( $key ): bool => null !== $type && is_string( $key ) && '' !== $key && $this->context->ownsField( $key, $type );
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
		if ( array() !== $nf && 'AND' !== $relation && array() !== $core ) {
			$bad = true; // OR between an NF clause and a core clause cannot be split.
		}
		$key     = $vars['meta_key'] ?? '';
		$nf_key  = $owns( $key );
		$nf_arg  = isset( $vars['nf'] ) && is_array( $vars['nf'] ) && array() !== $vars['nf'];
		$orderby = $vars['orderby'] ?? '';
		$ordered = $nf_arg && isset( $vars['nf']['orderby'] ) || ( $nf_key && is_string( $orderby ) && 1 === preg_match( '/\bmeta_value(_num)?\b/', $orderby ) );
		if ( array() === $nf && ! $nf_key && ! $nf_arg && ! $bad ) {
			return null;
		}
		$args = null;
		if ( ! $bad && ! ( $nf_key && isset( $vars['meta_value'] ) && '' !== $vars['meta_value'] ) ) {
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
			if ( $ordered ) {
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
