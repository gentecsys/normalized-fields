<?php
/**
 * Writes the dataset as ACF would: wp_posts + wp_postmeta.
 *
 * @package NF\Bench
 */

declare(strict_types=1);

namespace NF\Bench;

/**
 * Core tables only, so `$wpdb` is correct here (ADR-0009 covers NF tables).
 */
final class AcfStore implements StoreWriter {

	private const CHUNK = 200;

	/**
	 * Create the store writer.
	 *
	 * @param \wpdb $db WordPress database object.
	 */
	public function __construct( private readonly \wpdb $db ) {}

	/**
	 * Number of dataset posts already present.
	 *
	 * @param Dataset $dataset Dataset.
	 * @return int Count.
	 */
	public function existing( Dataset $dataset ): int {
		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$this->db->posts} WHERE post_type = %s AND ID BETWEEN %d AND %d",
				Dataset::POST_TYPE,
				$dataset->start_id,
				$dataset->start_id + $dataset->posts - 1
			)
		);
	}

	/**
	 * Remove the dataset posts and their meta.
	 *
	 * @param Dataset $dataset Dataset.
	 */
	public function clear( Dataset $dataset ): void {
		$low  = $dataset->start_id;
		$high = $dataset->start_id + $dataset->posts - 1;
		$this->db->query( $this->db->prepare( "DELETE m FROM {$this->db->postmeta} m INNER JOIN {$this->db->posts} p ON p.ID = m.post_id WHERE p.post_type = %s AND p.ID BETWEEN %d AND %d", Dataset::POST_TYPE, $low, $high ) );
		$this->db->query( $this->db->prepare( "DELETE FROM {$this->db->posts} WHERE post_type = %s AND ID BETWEEN %d AND %d", Dataset::POST_TYPE, $low, $high ) );
	}

	/**
	 * Write the whole dataset.
	 *
	 * @param Dataset $dataset Dataset.
	 */
	public function write( Dataset $dataset ): void {
		$posts = array();
		$meta  = array();
		foreach ( $dataset->records() as $record ) {
			$posts[] = $this->db->prepare(
				'(%d,1,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)',
				$record['id'],
				$record['post_date'],
				$record['post_date'],
				'',
				$record['title'],
				'',
				'publish',
				'closed',
				'closed',
				'event-' . $record['id'],
				$record['post_date'],
				$record['post_date'],
				Dataset::POST_TYPE
			);
			foreach ( AcfMeta::rows( $record ) as $row ) {
				$meta[] = $this->db->prepare( '(%d,%s,%s)', $record['id'], $row[0], $row[1] );
			}
			if ( count( $posts ) >= self::CHUNK ) {
				$this->flush( $posts, $meta );
			}
		}
		$this->flush( $posts, $meta );
	}

	/**
	 * Insert and empty the buffers.
	 *
	 * @param string[] $posts Prepared wp_posts value tuples.
	 * @param string[] $meta  Prepared wp_postmeta value tuples.
	 */
	private function flush( array &$posts, array &$meta ): void {
		if ( $posts ) {
			$sql = "INSERT INTO {$this->db->posts} (ID,post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_name,post_modified,post_modified_gmt,post_type) VALUES " . implode( ',', $posts ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tuples are prepared.
			$this->db->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
		if ( $meta ) {
			$this->db->query( "INSERT INTO {$this->db->postmeta} (post_id,meta_key,meta_value) VALUES " . implode( ',', $meta ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$posts = array();
		$meta  = array();
	}

	/**
	 * Compare the store with the dataset; returns mismatching posts.
	 *
	 * Two queries per chunk of posts, never one per post.
	 *
	 * @param Dataset $dataset Dataset.
	 * @return int
	 */
	public function verify( Dataset $dataset ): int {
		$bad     = 0;
		$records = array();
		foreach ( $dataset->records() as $record ) {
			$records[ $record['id'] ] = $record;
			if ( count( $records ) >= self::CHUNK ) {
				$bad    += $this->verify_chunk( $records );
				$records = array();
			}
		}
		return $bad + $this->verify_chunk( $records );
	}

	/**
	 * Verify one chunk of records keyed by post ID.
	 *
	 * @param array<int,array<string,mixed>> $records Records.
	 * @return int Mismatching posts.
	 */
	private function verify_chunk( array $records ): int {
		if ( ! $records ) {
			return 0;
		}
		$ids = array_map( 'intval', array_keys( $records ) );
		$in  = implode( ',', $ids ); // Integers only.

		$meta = array_fill_keys( $ids, array() );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integer ID list.
		foreach ( $this->db->get_results( "SELECT post_id, meta_key, meta_value FROM {$this->db->postmeta} WHERE post_id IN ({$in}) ORDER BY post_id, meta_id", ARRAY_N ) as $row ) {
			$meta[ (int) $row[0] ][] = array( $row[1], $row[2] );
		}
		$titles = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integer ID list.
		foreach ( $this->db->get_results( $this->db->prepare( "SELECT ID, post_title FROM {$this->db->posts} WHERE post_type = %s AND ID IN ({$in})", Dataset::POST_TYPE ), ARRAY_N ) as $row ) {
			$titles[ (int) $row[0] ] = $row[1];
		}

		$bad = 0;
		foreach ( $records as $id => $record ) {
			if ( ( $titles[ $id ] ?? null ) !== $record['title'] || AcfMeta::rows( $record ) !== $meta[ $id ] ) {
				++$bad;
			}
		}
		return $bad;
	}
}
