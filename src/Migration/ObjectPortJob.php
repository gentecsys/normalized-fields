<?php
/**
 * Background job: port the ACF values of terms, users, comments or options into NF (WP 2.6c).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Migration;

use NF\Core\Jobs\JobInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Same walk as PostPortJob for the other object types, using ACF's object IDs ('term_5', 'user_3', 'comment_7',
 * 'options'). User values are network-wide and land in the network tables through the bridge (ADR-0011).
 * Options pages (ACF's `post_id` of each page, plus 'options') are ported in one step.
 *
 * Arguments: kind (term, user, comment or option), chunk (int, optional).
 * State counts the objects visited, the values copied and the objects that held no ACF value.
 */
final class ObjectPortJob implements JobInterface {

	public const CHUNK = 50;

	public const KINDS = array( 'term', 'user', 'comment', 'option' );

	/**
	 * Constructor.
	 *
	 * @param AcfSource     $source Field source.
	 * @param AcfCopier     $copier Value copier.
	 * @param \Closure|null $lister IDs after an ID: fn( kind, after, limit ): int[]; for options, fn( 'option', 0, 0 ): string[] of ACF post IDs. Defaults to core's tables.
	 */
	public function __construct( private AcfSource $source, private AcfCopier $copier, private ?\Closure $lister = null ) {}

	/**
	 * Job type.
	 *
	 * @return string
	 */
	public function key(): string {
		return 'port_objects';
	}

	/**
	 * Initial state.
	 *
	 * @param array<string,mixed> $args Arguments.
	 * @return array<string,mixed>
	 */
	public function init( array $args ): array {
		return array(
			'kind'    => sanitize_key( (string) ( $args['kind'] ?? '' ) ),
			'chunk'   => max( 1, absint( $args['chunk'] ?? self::CHUNK ) ),
			'after'   => 0,
			'objects' => 0,
			'empty'   => 0,
			'values'  => 0,
			'error'   => '',
			'done'    => false,
		);
	}

	/**
	 * Port the next chunk.
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 */
	public function step( array $state ): array {
		$kind = (string) $state['kind'];
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			$state['error'] = 'bad_kind';
			$state['done']  = true;
			return $state;
		}
		if ( ! $this->source->available() ) {
			$state['error'] = 'acf_unavailable';
			$state['done']  = true;
			return $state;
		}
		$fields = $this->source->portableFields( $this->source->groups() );
		if ( 'option' === $kind ) {
			foreach ( $this->optionIds() as $acf_id ) {
				$state = $this->portObject( $state, $acf_id, $fields );
			}
			$this->copier->flush();
			$state['done'] = true;
			return $state;
		}
		$chunk = max( 1, absint( $state['chunk'] ) );
		$ids   = $this->listIds( $kind, absint( $state['after'] ), $chunk );
		foreach ( $ids as $id ) {
			$state          = $this->portObject( $state, $kind . '_' . $id, $fields );
			$state['after'] = $id;
		}
		$this->copier->flush();
		if ( count( $ids ) < $chunk ) {
			$state['done'] = true;
		}
		return $state;
	}

	/**
	 * Copy every portable field of one object and update the counters.
	 *
	 * @param array<string,mixed>             $state  State.
	 * @param string                          $acf_id ACF object ID.
	 * @param array<int,array<string,mixed>> $fields Portable fields.
	 * @return array<string,mixed>
	 */
	private function portObject( array $state, string $acf_id, array $fields ): array {
		$copied = 0;
		foreach ( $fields as $field ) {
			if ( $this->copier->copy( $acf_id, $field ) ) {
				++$copied;
			}
		}
		$state['objects'] = absint( $state['objects'] ) + 1;
		$state['values']  = absint( $state['values'] ) + $copied;
		if ( 0 === $copied ) {
			$state['empty'] = absint( $state['empty'] ) + 1;
		}
		return $state;
	}

	/**
	 * ACF post IDs of the options pages: 'options' plus each registered page's own post_id.
	 *
	 * @return string[]
	 */
	private function optionIds(): array {
		if ( null !== $this->lister ) {
			return array_map( 'strval', ( $this->lister )( 'option', 0, 0 ) );
		}
		$ids = array( 'options' );
		if ( function_exists( 'acf_get_options_pages' ) ) {
			foreach ( (array) acf_get_options_pages() as $page ) {
				$id = is_array( $page ) ? sanitize_text_field( (string) ( $page['post_id'] ?? '' ) ) : '';
				if ( '' !== $id ) {
					$ids[] = $id;
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Object IDs of a kind after an ID, ascending.
	 *
	 * @param string $kind  term, user or comment.
	 * @param int    $after Last ID done.
	 * @param int    $limit Chunk size.
	 * @return int[]
	 */
	private function listIds( string $kind, int $after, int $limit ): array {
		if ( null !== $this->lister ) {
			return array_map( 'intval', ( $this->lister )( $kind, $after, $limit ) );
		}
		global $wpdb;
		// Core tables, so $wpdb (ADR-0009). Table and column names are fixed here, never from input.
		if ( 'term' === $kind ) {
			$sql = $wpdb->prepare( "SELECT DISTINCT term_id FROM {$wpdb->term_taxonomy} WHERE term_id > %d ORDER BY term_id ASC LIMIT %d", $after, $limit );
		} elseif ( 'user' === $kind ) {
			// Users are network-wide: the base prefix table, whichever site runs the job.
			$sql = $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT %d", $after, $limit );
		} else {
			$sql = $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_ID > %d ORDER BY comment_ID ASC LIMIT %d", $after, $limit );
		}
		return array_map( 'intval', (array) $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}
}
