<?php
/**
 * Spike benchmark (WP 1.5): WP_Query on wp_postmeta vs the NF pivot translation.
 *
 * @package NF\Bench
 */

declare(strict_types=1);

namespace NF\Bench;

use NF\Backend\BackendInterface;
use NF\Pivot\Pivot;
use NF\Query\SpikeQuery;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Status;
use NF\Storage\Store;

/**
 * Runs the spike queries on the same dataset in both stores and compares IDs, found_posts and max_num_pages.
 */
final class SpikeBenchmark {

	private const RUNS = 5;

	/**
	 * Constructor.
	 *
	 * @param \wpdb            $wpdb WordPress database.
	 * @param BackendInterface $db   NF backend.
	 */
	public function __construct( private readonly \wpdb $wpdb, private readonly BackendInterface $db ) {}

	/**
	 * The benchmarked queries: label => WP_Query arguments.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function queries(): array {
		$base = array(
			'post_type' => Dataset::POST_TYPE,
			'fields'    => 'ids',
		);
		$arch = array(
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'AND',
				array(
					'key'   => 'featured',
					'value' => '1',
				),
				array(
					'key'     => 'event_date',
					'value'   => '20260101',
					'compare' => '>=',
					'type'    => 'DATE',
				),
			),
			'meta_key'   => 'event_date', // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'    => 'meta_value',
			'order'      => 'ASC',
		);
		return array(
			'Archive: featured, upcoming, by date, page 1 + total' => $base + $arch + array(
				'posts_per_page' => 20,
				'paged'          => 1,
			),
			'Deep page: featured by date, page 20 + total' => $base + $arch + array(
				'posts_per_page' => 20,
				'paged'          => 20,
			),
			'Reverse relationship: events featuring artist 123' => $base + array(
				'posts_per_page' => 20,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => 'artists',
						'value'   => '"123"',
						'compare' => 'LIKE',
					),
				),
			),
		);
	}

	/**
	 * Create the pivot set, slots and pivot rows for the NF store (idempotent).
	 *
	 * @param Dataset $dataset Dataset already written to the NF store.
	 * @return Pivot
	 */
	public function prepare( Dataset $dataset ): Pivot {
		$fields = new FieldMap( $this->db );
		$store  = new Store( $this->db, $fields );
		$pivot  = new Pivot( $this->db, $fields, $store );
		$set    = $pivot->setId( ObjectType::Post, Dataset::POST_TYPE );
		$slots  = array(
			'featured'   => 'i1',
			'event_date' => 't1',
			'price'      => 'n1',
		);
		foreach ( $slots as $name => $slot ) {
			$pivot->assignSlot( $set, AcfMeta::key( $name ), $slot );
		}
		$high = $dataset->start_id + $dataset->posts - 1;
		$last = $dataset->start_id - 1;
		do {
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- core table name from $wpdb, values bound.
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT ID, post_status, post_author, post_parent, post_date, post_modified, menu_order FROM {$this->wpdb->posts} WHERE post_type = %s AND ID > %d AND ID <= %d ORDER BY ID LIMIT 500",
					Dataset::POST_TYPE,
					$last,
					$high
				)
			);
			// phpcs:enable
			foreach ( $rows as $row ) {
				$last = (int) $row->ID;
				$pivot->sync(
					$set,
					$last,
					array(
						'status'     => Status::fromName( $row->post_status )->value,
						'author_id'  => (int) $row->post_author,
						'parent_id'  => (int) $row->post_parent,
						'published'  => $row->post_date,
						'modified'   => $row->post_modified,
						'menu_order' => (int) $row->menu_order,
					)
				);
			}
		} while ( $rows );
		return $pivot;
	}

	/**
	 * Run every query in both stores.
	 *
	 * @param Pivot $pivot Prepared pivot.
	 * @return array<int,array{query:string,acf_ms:float,nf_ms:float,speedup:float,same:bool,found:int}>
	 */
	public function run( Pivot $pivot ): array {
		$spike = new SpikeQuery( $this->db, $pivot );
		$out   = array();
		foreach ( self::queries() as $label => $args ) {
			$acf = null;
			$nf  = null;
			$a   = array();
			$n   = array();
			for ( $i = 0; $i < self::RUNS; $i++ ) {
				$t   = microtime( true );
				$q   = new \WP_Query( $args );
				$a[] = ( microtime( true ) - $t ) * 1000;
				$acf = array(
					'ids'           => array_map( 'intval', $q->posts ),
					'found_posts'   => (int) $q->found_posts,
					'max_num_pages' => (int) $q->max_num_pages,
				);
				$t   = microtime( true );
				$nf  = $spike->run( $args );
				$n[] = ( microtime( true ) - $t ) * 1000;
			}
			sort( $a );
			sort( $n );
			$acf_ms = $a[ intdiv( self::RUNS, 2 ) ];
			$nf_ms  = $n[ intdiv( self::RUNS, 2 ) ];
			$out[]  = array(
				'query'   => $label,
				'acf_ms'  => $acf_ms,
				'nf_ms'   => $nf_ms,
				'speedup' => $nf_ms > 0 ? $acf_ms / $nf_ms : 0.0,
				'same'    => null !== $nf && $nf === $acf,
				'found'   => (int) ( $acf['found_posts'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * Markdown table of results.
	 *
	 * @param array<int,array{query:string,acf_ms:float,nf_ms:float,speedup:float,same:bool,found:int}> $rows Results.
	 */
	public static function markdown( array $rows ): string {
		$md = "| Query | Matches | ACF (stock) ms | NF ms | Speed-up | Same result |\n| --- | --- | --- | --- | --- | --- |\n";
		foreach ( $rows as $r ) {
			$md .= sprintf( "| %s | %d | %.2f | %.2f | %.1fx | %s |\n", $r['query'], $r['found'], $r['acf_ms'], $r['nf_ms'], $r['speedup'], $r['same'] ? 'yes' : '**NO**' );
		}
		return $md;
	}
}
