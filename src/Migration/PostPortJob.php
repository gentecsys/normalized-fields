<?php
/**
 * Background job: port the ACF values of one post type into NF (WP 2.6b).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Migration;

use NF\Core\Jobs\JobInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Walks the posts of one post type by ascending ID, a chunk per step, and copies each post's ACF values into NF.
 * Resumable (the state holds the last ID) and idempotent (a re-run copies the same values again). The job runner
 * refuses to run while the NF schema is behind, so nothing is written then.
 *
 * Arguments: post_type (string), chunk (int, optional).
 */
final class PostPortJob implements JobInterface {

	public const CHUNK = 50;

	/**
	 * Constructor.
	 *
	 * @param AcfSource                                       $source Field source.
	 * @param AcfCopier                                       $copier Value copier.
	 * @param (\Closure(string,int,int):array<int,int>)|null $lister Post IDs after an ID: fn( post_type, after, limit ). Defaults to core's posts table.
	 */
	public function __construct( private AcfSource $source, private AcfCopier $copier, private ?\Closure $lister = null ) {}

	/**
	 * Job type.
	 *
	 * @return string
	 */
	public function key(): string {
		return 'port_posts';
	}

	/**
	 * Initial state.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<string,mixed>
	 */
	public function init( array $args ): array {
		return array(
			'post_type' => sanitize_key( (string) ( $args['post_type'] ?? '' ) ),
			'chunk'     => max( 1, absint( $args['chunk'] ?? self::CHUNK ) ),
			'after'     => 0,
			'posts'     => 0,
			'values'    => 0,
			'error'     => '',
			'done'      => false,
		);
	}

	/**
	 * Port the next chunk of posts.
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 */
	public function step( array $state ): array {
		$type = sanitize_key( (string) $state['post_type'] );
		if ( '' === $type ) {
			$state['error'] = 'no_post_type';
			$state['done']  = true;
			return $state;
		}
		if ( ! $this->source->available() ) {
			$state['error'] = 'acf_unavailable';
			$state['done']  = true;
			return $state;
		}
		$chunk  = max( 1, absint( $state['chunk'] ) );
		$fields = $this->source->portableFields( $this->source->groups() );
		$ids    = $this->listIds( $type, absint( $state['after'] ), $chunk );
		foreach ( $ids as $id ) {
			foreach ( $fields as $field ) {
				if ( $this->copier->copy( $id, $field ) ) {
					$state['values'] = absint( $state['values'] ) + 1;
				}
			}
			$state['after'] = $id;
			$state['posts'] = absint( $state['posts'] ) + 1;
		}
		$this->copier->flush();
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
		// Core table, so $wpdb (ADR-0009). Auto-drafts hold no values worth porting.
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'auto-draft' AND ID > %d ORDER BY ID ASC LIMIT %d", $type, $after, $limit )
		);
		return array_map( 'intval', (array) $ids );
	}
}
