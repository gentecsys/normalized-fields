<?php
/**
 * Counts orphaned ACF meta (WP 2.6c2).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * ACF stores `_name = field_key` next to each value. A reference whose field key no longer exists is orphaned meta:
 * it is not ported and is only removed by cleanup when the owner asks. Core tables, so `$wpdb` (ADR-0009).
 */
class OrphanCounter {

	/**
	 * Meta tables by object kind (fixed names, never from input).
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	private const TABLES = array(
		'post'    => array( 'postmeta', 'meta_id' ),
		'user'    => array( 'usermeta', 'umeta_id' ),
		'term'    => array( 'termmeta', 'meta_id' ),
		'comment' => array( 'commentmeta', 'meta_id' ),
	);

	/**
	 * Count references to unknown field keys among a batch.
	 *
	 * @param string[] $refs  Field keys that `_name` rows point at.
	 * @param string[] $known Known field keys.
	 * @return int
	 */
	public function countOrphans( array $refs, array $known ): int {
		$known = array_flip( $known );
		$n     = 0;
		foreach ( $refs as $ref ) {
			if ( ! isset( $known[ $ref ] ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Count orphaned references in one meta table.
	 *
	 * @param string   $kind  post, user, term or comment.
	 * @param string[] $known Known field keys.
	 * @param int      $chunk Rows per query.
	 * @return int
	 */
	public function count( string $kind, array $known, int $chunk = 1000 ): int {
		global $wpdb;
		if ( ! isset( self::TABLES[ $kind ] ) ) {
			return 0;
		}
		list( $suffix, $id ) = self::TABLES[ $kind ];
		$table               = $wpdb->$suffix;
		$after               = 0;
		$total               = 0;
		do {
			$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare( "SELECT {$id} AS id, meta_value FROM {$table} WHERE {$id} > %d AND meta_key LIKE %s AND meta_value LIKE %s ORDER BY {$id} ASC LIMIT %d", $after, '\\_%', 'field\\_%', max( 1, $chunk ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
			$refs = array();
			foreach ( $rows as $row ) {
				$after  = (int) $row['id'];
				$refs[] = (string) $row['meta_value'];
			}
			$total += $this->countOrphans( $refs, $known );
			$more   = count( $rows ) >= max( 1, $chunk );
		} while ( $more );
		return $total;
	}
}
