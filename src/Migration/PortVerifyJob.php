<?php
/**
 * Background job: compare ACF and NF values for one post type (WP 2.6d).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Migration;

use NF\Core\Jobs\JobInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Walks the posts of one post type by ascending ID and lists the fields whose NF value differs from ACF's. Read
 * only, so it is safe at any schema version. The state keeps the count and the first MAX_LIST mismatches.
 *
 * Arguments: post_type (string), chunk (int, optional).
 */
final class PortVerifyJob implements JobInterface {

	public const CHUNK    = 50;
	public const MAX_LIST = 100;

	/**
	 * Constructor.
	 *
	 * @param AcfSource     $source   Field source.
	 * @param PortVerifier  $verifier Verifier.
	 * @param \Closure|null $lister   Post IDs after an ID: fn( post_type, after, limit ): int[]. Defaults to core's posts table.
	 */
	public function __construct( private AcfSource $source, private PortVerifier $verifier, private ?\Closure $lister = null ) {}

	/**
	 * Job type.
	 *
	 * @return string
	 */
	public function key(): string {
		return 'port_verify';
	}

	/**
	 * Initial state.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<string,mixed>
	 */
	public function init( array $args ): array {
		return array(
			'post_type'  => sanitize_key( (string) ( $args['post_type'] ?? '' ) ),
			'chunk'      => max( 1, absint( $args['chunk'] ?? self::CHUNK ) ),
			'after'      => 0,
			'posts'      => 0,
			'mismatches' => 0,
			'list'       => array(),
			'error'      => '',
			'done'       => false,
		);
	}

	/**
	 * Verify the next chunk of posts.
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 */
	public function step( array $state ): array {
		$type = sanitize_key( (string) $state['post_type'] );
		if ( '' === $type || ! $this->source->available() ) {
			$state['error'] = '' === $type ? 'no_post_type' : 'acf_unavailable';
			$state['done']  = true;
			return $state;
		}
		$chunk  = max( 1, absint( $state['chunk'] ) );
		$fields = $this->source->portableFields( $this->source->groups() );
		$ids    = $this->listIds( $type, absint( $state['after'] ), $chunk );
		foreach ( $ids as $id ) {
			foreach ( $this->verifier->verify( $id, $fields ) as $mismatch ) {
				$state['mismatches'] = absint( $state['mismatches'] ) + 1;
				if ( count( (array) $state['list'] ) < self::MAX_LIST ) {
					$state['list'][] = $mismatch;
				}
			}
			$state['after'] = $id;
			$state['posts'] = absint( $state['posts'] ) + 1;
		}
		if ( count( $ids ) < $chunk ) {
			$state['done'] = true;
		}
		return $state;
	}

	/**
	 * Post IDs of a type after an ID, ascending.
	 *
	 * @param string $type  Post type.
	 * @param int    $after Last ID done.
	 * @param int    $limit Chunk size.
	 * @return int[]
	 */
	private function listIds( string $type, int $after, int $limit ): array {
		if ( null !== $this->lister ) {
			return array_map( 'intval', ( $this->lister )( $type, $after, $limit ) );
		}
		global $wpdb;
		// Core table, so $wpdb (ADR-0009).
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft' AND ID > %d ORDER BY ID ASC LIMIT %d", $type, $after, $limit )
		);
		return array_map( 'intval', (array) $ids );
	}
}
