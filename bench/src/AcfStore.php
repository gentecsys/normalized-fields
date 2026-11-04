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
	 * @param array $posts   Prepared wp_posts value tuples.
	 * @param array $meta    Prepared wp_postmeta value tuples.
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
	 * @param Dataset $dataset Dataset.
	 * @return int
	 */
	public function verify( Dataset $dataset ): int {
		$bad = 0;
		foreach ( $dataset->records() as $record ) {
			$actual = $this->db->get_results(
				$this->db->prepare( "SELECT meta_key, meta_value FROM {$this->db->postmeta} WHERE post_id = %d ORDER BY meta_id", $record['id'] ),
				ARRAY_N
			);
			$title  = $this->db->get_var( $this->db->prepare( "SELECT post_title FROM {$this->db->posts} WHERE ID = %d AND post_type = %s", $record['id'], Dataset::POST_TYPE ) );
			if ( $record['title'] !== $title || AcfMeta::rows( $record ) !== $actual ) {
				++$bad;
			}
		}
		return $bad;
	}
}
