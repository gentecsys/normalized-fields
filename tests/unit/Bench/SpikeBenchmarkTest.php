<?php
/**
 * Spike benchmark (WP 1.5): NF returns what WP_Query returns on the bench dataset.
 *
 * Size comes from NF_BENCH_POSTS (default 300). With NF_BENCH_OUT set, the markdown table is written there.
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

/**
 * Both stores hold the same dataset; every benchmarked query gives identical results.
 */
class SpikeBenchmarkTest extends WP_UnitTestCase {

	/**
	 * Dataset under test.
	 *
	 * @var Dataset
	 */
	private Dataset $dataset;

	/**
	 * Write the dataset to both stores.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		global $wpdb;
		NF\Install\Schema::install();
		register_post_type( Dataset::POST_TYPE );
		$posts         = absint( getenv( 'NF_BENCH_POSTS' ) ? getenv( 'NF_BENCH_POSTS' ) : 300 );
		$this->dataset = new Dataset( $posts, 1, 1000000 );
		$acf           = new AcfStore( $wpdb );
		$nf            = new NfStore( $wpdb, nf_db(), false );
		$nf->clear( $this->dataset );
		$acf->clear( $this->dataset );
		$acf->write( $this->dataset );
		$nf->write( $this->dataset );
		wp_cache_flush();
	}

	/**
	 * Remove the dataset.
	 */
	public function tear_down() {
		global $wpdb;
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
		$left = $db->select( 'SELECT field_id, field_key FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_name = ?', array( 'artists' ) );
		$this->assertSame( array(), $left, 'Bench fields were not cleaned up.' );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- NfStore commits its writes; make the clean-up permanent too, the suite only rolls back.
		parent::tear_down();
	}

	/**
	 * Identical IDs, found_posts and max_num_pages for every query.
	 */
	public function test_results_identical(): void {
		global $wpdb;
		$bench = new SpikeBenchmark( $wpdb, nf_db() );
		$rows  = $bench->run( $bench->prepare( $this->dataset ) );
		$this->assertCount( 3, $rows );
		foreach ( $rows as $row ) {
			$this->assertTrue( $row['same'], $row['query'] . ' ' . $row['detail'] );
		}
		$out = getenv( 'NF_BENCH_OUT' );
		if ( $out ) {
			file_put_contents( $out, "Posts: {$this->dataset->posts}\n\n" . SpikeBenchmark::markdown( $rows ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
}
