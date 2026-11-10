<?php
/**
 * Spike benchmark (WP 1.5): NF returns what WP_Query returns on the bench dataset.
 *
 * Size comes from NF_BENCH_POSTS (default 300). With NF_BENCH_OUT set, the markdown table is written there.
 *
 * @package NF
 */

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
		$nf            = new NfStore( $wpdb, nf_db() );
		$acf->clear( $this->dataset );
		$nf->clear( $this->dataset );
		$acf->write( $this->dataset );
		$nf->write( $this->dataset );
		wp_cache_flush();
	}

	/**
	 * Remove the dataset.
	 */
	public function tear_down() {
		global $wpdb;
		( new AcfStore( $wpdb ) )->clear( $this->dataset );
		( new NfStore( $wpdb, nf_db() ) )->clear( $this->dataset );
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
			$this->assertTrue( $row['same'], $row['query'] );
		}
		$out = getenv( 'NF_BENCH_OUT' );
		if ( $out ) {
			file_put_contents( $out, "Posts: {$this->dataset->posts}\n\n" . SpikeBenchmark::markdown( $rows ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
}
