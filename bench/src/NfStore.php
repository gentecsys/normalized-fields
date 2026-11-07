<?php
/**
 * Writes the dataset into the NF typed tables (WP 0.3b).
 *
 * @package NF\Bench
 */

declare(strict_types=1);

namespace NF\Bench;

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core wp_posts only; values come from prepare() and integer ID lists.

use NF\Backend\BackendInterface;
use NF\Storage\DataTable;
use NF\Storage\FieldDef;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;

/**
 * Posts go to `wp_posts` (core table, `$wpdb`); every field value goes to the NF
 * tables through the backend adapter, in bulk. Field keys equal the ACF keys.
 */
final class NfStore implements StoreWriter {

	private const CHUNK = 100;

	/**
	 * Resolved field definitions: name => FieldDef, and 'sessions.sub' => FieldDef.
	 *
	 * @var array<string,FieldDef>
	 */
	private array $defs = array();

	/**
	 * Create the writer.
	 *
	 * @param \wpdb            $wpdb WordPress database object (core tables only).
	 * @param BackendInterface $db   NF backend adapter.
	 */
	public function __construct( private readonly \wpdb $wpdb, private readonly BackendInterface $db ) {}

	/**
	 * Number of dataset posts already present.
	 *
	 * @param Dataset $dataset Dataset.
	 * @return int
	 */
	public function existing( Dataset $dataset ): int {
		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->wpdb->posts} WHERE post_type = %s AND ID BETWEEN %d AND %d",
				Dataset::POST_TYPE,
				$dataset->start_id,
				$dataset->start_id + $dataset->posts - 1
			)
		);
	}

	/**
	 * Remove dataset posts and their NF values.
	 *
	 * @param Dataset $dataset Dataset.
	 */
	public function clear( Dataset $dataset ): void {
		$low  = $dataset->start_id;
		$high = $dataset->start_id + $dataset->posts - 1;
		foreach ( array( 'nf_int', 'nf_dec', 'nf_date', 'nf_str', 'nf_text', 'nf_json', 'nf_rows' ) as $table ) {
			$this->db->execute(
				'DELETE FROM ' . $this->db->table( $table ) . ' WHERE object_type = ? AND object_id BETWEEN ? AND ?',
				array( ObjectType::Post->value, $low, $high )
			);
		}
		$this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->wpdb->posts} WHERE post_type = %s AND ID BETWEEN %d AND %d", Dataset::POST_TYPE, $low, $high ) );
	}

	/**
	 * Write the whole dataset.
	 *
	 * @param Dataset $dataset Dataset.
	 */
	public function write( Dataset $dataset ): void {
		$this->resolve();
		$chunk = array();
		foreach ( $dataset->records() as $record ) {
			$chunk[] = $record;
			if ( count( $chunk ) >= self::CHUNK ) {
				$this->write_chunk( $chunk );
				$chunk = array();
			}
		}
		$this->write_chunk( $chunk );
	}

	/**
	 * Compare NF tables with the dataset; returns mismatching posts.
	 *
	 * @param Dataset $dataset Dataset.
	 * @return int
	 */
	public function verify( Dataset $dataset ): int {
		$this->resolve();
		$bad   = 0;
		$chunk = array();
		foreach ( $dataset->records() as $record ) {
			$chunk[ $record['id'] ] = $record;
			if ( count( $chunk ) >= self::CHUNK ) {
				$bad  += $this->verify_chunk( $chunk );
				$chunk = array();
			}
		}
		return $bad + $this->verify_chunk( $chunk );
	}

	/**
	 * Register the fields (idempotent) and cache their definitions.
	 */
	private function resolve(): void {
		if ( $this->defs ) {
			return;
		}
		$map = new FieldMap( $this->db );
		foreach ( array_keys( Dataset::FIELDS ) as $name ) {
			[ $table, $multi ]   = NfLayout::scalar( $name );
			$this->defs[ $name ] = $map->register( AcfMeta::key( $name ), $name, DataTable::from( NfLayout::table( $table ) ), $multi );
		}
		$series = $map->register( AcfMeta::key( 'sessions' ), 'sessions', DataTable::Rows );
		foreach ( NfLayout::SUBS as $sub => $table ) {
			$this->defs[ 'sessions.' . $sub ] = $map->register( AcfMeta::key( 'sessions_' . $sub ), $sub, DataTable::from( NfLayout::table( $table ) ), false, $series->id );
		}
		$this->defs['sessions'] = $series;
	}

	/**
	 * Write posts, Series rows and values for up to CHUNK records.
	 *
	 * @param array<int,array<string,mixed>> $records Records.
	 */
	private function write_chunk( array $records ): void {
		if ( ! $records ) {
			return;
		}
		$this->db->transaction(
			function () use ( $records ): void {
				$this->insert_posts( $records );
				$values = array(); // Table suffix => flat tuples [object_id, field_id, row_id, position, value].
				$type   = ObjectType::Post->value;
				foreach ( $records as $record ) {
					foreach ( array_keys( Dataset::FIELDS ) as $name ) {
						$table = NfLayout::table( NfLayout::scalar( $name )[0] );
						foreach ( NfLayout::scalarValues( $record, $name ) as $pos => $v ) {
							$values[ $table ][] = array( $record['id'], $this->defs[ $name ]->id, 0, $pos, $v );
						}
					}
				}
				$row_ids = $this->insert_rows( $records );
				foreach ( $records as $record ) {
					foreach ( $record['sessions'] as $order => $row ) {
						$row_id = $row_ids[ $record['id'] ][ $order ];
						foreach ( NfLayout::SUBS as $sub => $table ) {
							$values[ NfLayout::table( $table ) ][] = array( $record['id'], $this->defs[ 'sessions.' . $sub ]->id, $row_id, 0, NfLayout::toDb( $table, $row[ $sub ] ) );
						}
					}
				}
				foreach ( $values as $table => $tuples ) {
					$params = array();
					foreach ( $tuples as $t ) {
						array_push( $params, $type, $t[0], $t[1], $t[2], $t[3], $t[4] );
					}
					$this->db->execute(
						'INSERT INTO ' . $this->db->table( 'nf_' . $table ) . ' (object_type, object_id, field_id, row_id, position, value) VALUES ' . implode( ', ', array_fill( 0, count( $tuples ), '(?, ?, ?, ?, ?, ?)' ) ),
						$params
					);
				}
			}
		);
	}

	/**
	 * Insert the wp_posts rows (same columns as the ACF store).
	 *
	 * @throws \RuntimeException When the insert fails.
	 *
	 * @param array<int,array<string,mixed>> $records Records.
	 */
	private function insert_posts( array $records ): void {
		$tuples = array();
		foreach ( $records as $record ) {
			$tuples[] = $this->wpdb->prepare(
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
		}
		$sql = "INSERT INTO {$this->wpdb->posts} (ID,post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_name,post_modified,post_modified_gmt,post_type) VALUES " . implode( ',', $tuples ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tuples are prepared.
		if ( false === $this->wpdb->query( $sql ) ) {
			throw new \RuntimeException( 'Inserting the dataset posts failed: ' . esc_html( (string) $this->wpdb->last_error ) );
		}
	}

	/**
	 * Insert Series rows for the chunk and return their IDs.
	 *
	 * @param array<int,array<string,mixed>> $records Records.
	 * @return array<int,array<int,int>> Post ID => row order => row_id.
	 */
	private function insert_rows( array $records ): array {
		$params = array();
		$marks  = array();
		$ids    = array();
		$field  = $this->defs['sessions']->id;
		$type   = ObjectType::Post->value;
		foreach ( $records as $record ) {
			$ids[] = $record['id'];
			foreach ( array_keys( $record['sessions'] ) as $order ) {
				array_push( $params, $type, $record['id'], $field, 0, $order );
				$marks[] = '(?, ?, ?, ?, ?)';
			}
		}
		if ( ! $marks ) {
			return array();
		}
		$this->db->execute(
			'INSERT INTO ' . $this->db->table( 'nf_rows' ) . ' (object_type, object_id, field_id, parent_row_id, row_order) VALUES ' . implode( ', ', $marks ),
			$params
		);
		return $this->read_row_ids( $ids );
	}

	/**
	 * Series row IDs by post and order.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array<int,array<int,int>>
	 */
	private function read_row_ids( array $ids ): array {
		$out = array();
		$sel = $this->db->select(
			'SELECT object_id, row_order, row_id FROM ' . $this->db->table( 'nf_rows' ) . ' WHERE object_type = ? AND field_id = ? AND object_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '?' ) ) . ')',
			array_merge( array( ObjectType::Post->value, $this->defs['sessions']->id ), $ids )
		);
		foreach ( $sel as $r ) {
			$out[ (int) $r['object_id'] ][ (int) $r['row_order'] ] = (int) $r['row_id'];
		}
		return $out;
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
		$ids   = array_keys( $records );
		$marks = implode( ', ', array_fill( 0, count( $ids ), '?' ) );
		$type  = ObjectType::Post->value;

		// Titles from the core table; integer ID list only.
		$in     = implode( ',', array_map( 'intval', $ids ) );
		$titles = array();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integer ID list.
		foreach ( $this->wpdb->get_results( $this->wpdb->prepare( "SELECT ID, post_title FROM {$this->wpdb->posts} WHERE post_type = %s AND ID IN ({$in})", Dataset::POST_TYPE ), ARRAY_N ) as $row ) {
			$titles[ (int) $row[0] ] = $row[1];
		}

		// Read-back per table: id => field_id => row_id => position => value.
		$found  = array();
		$tables = array();
		foreach ( $this->defs as $def ) {
			if ( $def->table->hasValues() ) {
				$tables[ $def->table->value ] = true;
			}
		}
		foreach ( array_keys( $tables ) as $table ) {
			$rows = $this->db->select(
				'SELECT object_id, field_id, row_id, position, value FROM ' . $this->db->table( 'nf_' . $table ) . " WHERE object_type = ? AND object_id IN ({$marks}) ORDER BY object_id, field_id, row_id, position",
				array_merge( array( $type ), $ids )
			);
			foreach ( $rows as $r ) {
				$found[ (int) $r['object_id'] ][ (int) $r['field_id'] ][ (int) $r['row_id'] ][ (int) $r['position'] ] = $r['value'];
			}
		}
		$rowmap = $this->read_row_ids( $ids );

		$bad = 0;
		foreach ( $records as $id => $record ) {
			$ok = ( $titles[ $id ] ?? null ) === $record['title'];
			foreach ( array_keys( Dataset::FIELDS ) as $name ) {
				[ $table, $multi ] = NfLayout::scalar( $name );
				$source            = $multi ? (array) $record['fields'][ $name ] : array( $record['fields'][ $name ] );
				$want              = array_map( static fn( $v ) => NfLayout::fromDb( $table, NfLayout::toDb( $table, $v ) ), array_values( $source ) );
				$got               = array_map( static fn( $v ) => NfLayout::fromDb( $table, $v ), array_values( $found[ $id ][ $this->defs[ $name ]->id ][0] ?? array() ) );
				$ok                = $ok && $want === $got;
			}
			$count = count( $record['sessions'] );
			$ok    = $ok && count( $rowmap[ $id ] ?? array() ) === $count;
			foreach ( $record['sessions'] as $order => $row ) {
				foreach ( NfLayout::SUBS as $sub => $table ) {
					$raw = $found[ $id ][ $this->defs[ 'sessions.' . $sub ]->id ][ $rowmap[ $id ][ $order ] ?? -1 ][0] ?? null;
					$ok  = $ok && null !== $raw && NfLayout::fromDb( $table, $raw ) === NfLayout::fromDb( $table, NfLayout::toDb( $table, $row[ $sub ] ) );
				}
			}
			if ( ! $ok ) {
				++$bad;
			}
		}
		return $bad;
	}
}
