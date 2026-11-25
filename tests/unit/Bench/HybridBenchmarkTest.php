<?php
/**
 * Hybrid intersection benchmark: both sides over the cap, cold and warm, extra memory, correct totals.
 *
 * Size comes from NF_BENCH_POSTS (default 600). Time and memory limits are asserted only when NF_BENCH_GATES is set
 * (the 200k run); every size asserts the results. With NF_BENCH_OUT set, a markdown table is written there.
 *
 * @package NF
 */

foreach ( array( 'Dataset', 'AcfMeta', 'AcfSchema', 'StoreWriter', 'AcfStore', 'NfLayout', 'NfStore', 'SpikeBenchmark' ) as $nf_bench_class ) {
	require_once dirname( __DIR__, 3 ) . '/bench/src/' . $nf_bench_class . '.php';
}

use NF\Bench\AcfStore;
use NF\Bench\Dataset;
use NF\Bench\NfStore;
use NF\Bench\SpikeBenchmark;
use NF\Query\Hybrid;
use NF\Query\PreQuery;
use NF\Query\QueryContext;

// phpcs:disable WordPress.DB.SlowDBQuery -- the benchmark runs WP_Query with meta_key / meta_query against NF.

/**
 * The hybrid intersection answers a mixed query on the bench dataset within the time and memory limits.
 */
class HybridBenchmarkTest extends WP_UnitTestCase {

	private const COLD_MS  = 300.0;
	private const WARM_MS  = 10.0;
	private const EXTRA_MB = 32;

	/**
	 * Dataset under test.
	 *
	 * @var Dataset
	 */
	private Dataset $dataset;

	/**
	 * Hook object.
	 *
	 * @var Hybrid
	 */
	private Hybrid $hook;

	/**
	 * Reason of the last fail-closed answer.
	 *
	 * @var string
	 */
	private string $reason = '';

	/**
	 * Write the dataset to both stores, build the pivot and register the hybrid hooks.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		global $wpdb;
		NF\Install\Schema::install();
		update_option( NF\Install\Upgrader::OPTION, NF\Install\Upgrader::SCHEMA_VERSION );
		register_post_type( Dataset::POST_TYPE );
		$posts         = absint( getenv( 'NF_BENCH_POSTS' ) ? getenv( 'NF_BENCH_POSTS' ) : 600 );
		$this->dataset = new Dataset( $posts, 1, 1000000 );
		$acf           = new AcfStore( $wpdb );
		$nf            = new NfStore( $wpdb, nf_db(), false );
		$nf->clear( $this->dataset );
		$acf->clear( $this->dataset );
		$acf->write( $this->dataset );
		$nf->write( $this->dataset );
		$spike = new SpikeBenchmark( $wpdb, nf_db() );
		$pivot = $spike->prepare( $this->dataset );
		wp_cache_flush();
		$context    = new QueryContext( nf_db(), $pivot, $pivot->dictionary() );
		$this->hook = new Hybrid( $context, new PreQuery( $context ) );
		$this->hook->register();
		// Both sides far over the cap, so every run takes the intersection path.
		add_filter( 'nf/hybrid/id_cap', static fn () => 100 );
		add_filter( 'nf/hybrid/set_max', static fn () => 1000000 );
		wp_set_current_user( 0 );
	}

	/**
	 * Remove hooks and the dataset.
	 */
	public function tear_down() {
		global $wpdb;
		remove_all_filters( 'nf/hybrid/id_cap' );
		remove_all_filters( 'nf/hybrid/set_max' );
		remove_action( 'pre_get_posts', array( $this->hook, 'apply' ), 9999 );
		remove_filter( 'posts_pre_query', array( $this->hook, 'guardHandBack' ), 20 );
		remove_filter( 'found_posts', array( $this->hook, 'foundPosts' ), 1 );
		remove_filter( 'the_posts', array( $this->hook, 'restorePaging' ), 1 );
		$db  = nf_db();
		$set = $db->select( 'SELECT pivot_id FROM ' . $db->table( 'nf_pivot_sets' ) . ' WHERE subtype = ?', array( Dataset::POST_TYPE ) );
		foreach ( $set as $row ) {
			foreach ( array( 'nf_pivot', 'nf_pivot_slots', 'nf_pivot_sets' ) as $table ) {
				$db->execute( 'DELETE FROM ' . $db->table( $table ) . ' WHERE pivot_id = ?', array( (int) $row['pivot_id'] ) );
			}
		}
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_nfbench%' ) );
		( new NfStore( $wpdb, nf_db(), false ) )->clear( $this->dataset );
		( new AcfStore( $wpdb ) )->clear( $this->dataset );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the stores commit their writes; make the clean-up permanent too.
		parent::tear_down();
	}

	/**
	 * Featured events: NF conditions plus a core-only filter, so the intersection path runs.
	 *
	 * @param bool $ordered Order by the NF field event_date.
	 * @return array<string,mixed>
	 */
	private function args( bool $ordered ): array {
		$args = array(
			'post_type'      => Dataset::POST_TYPE,
			'post_status'    => 'publish',
			'menu_order'     => 0, // Core-only filter, so the hybrid path takes the query.
			'posts_per_page' => 20,
			'paged'          => 2,
			'cache_results'  => false,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'   => 'featured',
					'value' => '1',
					'type'  => 'NUMERIC',
				),
			),
		);
		if ( $ordered ) {
			$args['meta_key'] = 'event_date';
			$args['orderby']  = 'meta_value';
			$args['order']    = 'ASC';
		}
		return $args;
	}

	/**
	 * Matching post count straight from the dataset.
	 */
	private function expected_total(): int {
		$n = 0;
		foreach ( $this->dataset->records() as $record ) {
			if ( 1 === (int) $record['fields']['featured'] ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Cold and warm timing, extra memory and the result of one query.
	 *
	 * @param bool $ordered Ordered by the NF field.
	 * @return array{cold:float,warm:float,mb:float,total:int,pages:int,ids:int[],mode:string}
	 */
	private function measure( bool $ordered ): array {
		wp_cache_flush();
		$this->reason = '';
		add_action(
			'nf/hybrid/failed',
			function ( $reason ) {
				$this->reason = (string) $reason;
			}
		);
		$can = function_exists( 'memory_reset_peak_usage' );
		if ( $can ) {
			memory_reset_peak_usage();
		}
		$base = memory_get_usage();
		$t    = microtime( true );
		$q    = new WP_Query( $this->args( $ordered ) );
		$cold = ( microtime( true ) - $t ) * 1000;
		$peak = $can ? memory_get_peak_usage() : memory_get_usage();
		$t    = microtime( true );
		$w    = new WP_Query( $this->args( $ordered ) );
		$warm = ( microtime( true ) - $t ) * 1000;
		$this->assertSame( wp_list_pluck( $q->posts, 'ID' ), wp_list_pluck( $w->posts, 'ID' ), 'Warm run differs from cold.' );
		$this->assertSame( (int) $q->found_posts, (int) $w->found_posts, 'Warm total differs from cold.' );
		return array(
			'cold'  => $cold,
			'warm'  => $warm,
			'mb'    => max( 0, $peak - $base ) / 1048576,
			'total' => (int) $q->found_posts,
			'pages' => (int) $q->max_num_pages,
			'ids'   => array_map( 'intval', wp_list_pluck( $q->posts, 'ID' ) ),
			'mode'  => (string) $q->get( 'nf_mode' ),
		);
	}

	/**
	 * Exact total, hybrid mode, and (at 200k) the time and memory limits.
	 */
	public function test_intersection_gates(): void {
		$total = $this->expected_total();
		$rows  = array();
		foreach ( array(
			'unordered (core order)' => false,
			'ordered by event_date'  => true,
		) as $label => $ordered ) {
			$r = $this->measure( $ordered );
			$this->assertSame( 'hybrid', $r['mode'], $label . ' ' . $this->reason );
			$this->assertSame( $total, $r['total'], $label . ': found_posts' );
			$this->assertSame( (int) ceil( $total / 20 ), $r['pages'], $label . ': max_num_pages' );
			$this->assertCount( min( 20, max( 0, $total - 20 ) ), $r['ids'], $label . ': page 2 size' );
			foreach ( $r['ids'] as $id ) {
				$this->assertSame( '1', (string) get_post_meta( $id, 'featured', true ), $label . ': featured' );
			}
			if ( $ordered ) {
				$dates  = array_map( static fn ( int $id ): string => (string) get_post_meta( $id, 'event_date', true ), $r['ids'] );
				$sorted = $dates;
				sort( $sorted );
				$this->assertSame( $sorted, $dates, 'event_date order' );
			}
			$rows[ $label ] = $r;
			if ( getenv( 'NF_BENCH_GATES' ) ) {
				$this->assertLessThanOrEqual( self::COLD_MS, $r['cold'], $label . ': cold ms' );
				$this->assertLessThanOrEqual( self::WARM_MS, $r['warm'], $label . ': warm ms' );
				$this->assertLessThanOrEqual( self::EXTRA_MB, $r['mb'], $label . ': extra MB' );
			}
		}
		$out = getenv( 'NF_BENCH_OUT' );
		if ( $out ) {
			$md = "Posts: {$this->dataset->posts}\n\n| Query | Matches | Cold ms | Warm ms | Extra MB |\n| --- | --- | --- | --- | --- |\n";
			foreach ( $rows as $label => $r ) {
				$md .= sprintf( "| %s | %d | %.1f | %.1f | %.1f |\n", $label, $r['total'], $r['cold'], $r['warm'], $r['mb'] );
			}
			file_put_contents( $out, $md ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
}
