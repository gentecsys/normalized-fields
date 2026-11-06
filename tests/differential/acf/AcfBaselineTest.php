<?php
/**
 * ACF-vs-ACF baseline on the bench dataset, against real SCF/ACF. Zero diffs required.
 *
 * Both targets call the real global ACF functions. The right target resets with a full cache flush, before every call, so
 * it re-reads from the database, which catches non-determinism in ACF's own formatting.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

use NF\Bench\AcfStore;
use NF\Bench\Dataset;
use PHPUnit\Framework\TestCase;

final class AcfBaselineTest extends TestCase {

	private const POSTS = 40;

	/**
	 * Dataset under test.
	 *
	 * @var Dataset
	 */
	private static Dataset $dataset;

	public static function setUpBeforeClass(): void {
		global $wpdb;
		self::$dataset = new Dataset( self::POSTS );
		$store         = new AcfStore( $wpdb );
		$store->clear( self::$dataset );
		$store->write( self::$dataset );
		wp_cache_flush();
	}

	public static function tearDownAfterClass(): void {
		global $wpdb;
		( new AcfStore( $wpdb ) )->clear( self::$dataset );
		wp_cache_flush();
	}

	/** ACF must be active and the bench Local JSON loaded, or the baseline would compare nothing. */
	public function test_real_acf_is_loaded_and_reads_bench_data(): void {
		$this->assertTrue( function_exists( 'get_field' ), 'ACF/SCF get_field() is missing.' );
		$id = self::$dataset->start_id;
		$this->assertSame( self::$dataset->record( 0 )['fields']['venue'], get_field( 'venue', $id ) );
		$sessions = get_field( 'sessions', $id );
		$this->assertCount( Dataset::SESSION_ROWS, $sessions );
		foreach ( array( 'event_date', 'price', 'capacity', 'venue', 'categories', 'text_1', 'text_10' ) as $name ) {
			$this->assertNotEmpty( get_field( $name, $id ), "{$name} must be readable, or the baseline compares nothing." );
		}
		$this->assertNotEmpty( $sessions[4]['date'] );
		$this->assertNotEmpty( $sessions[4]['time'] );
		$this->assertSame( self::$dataset->record( 0 )['sessions'][0]['room'], $sessions[0]['room'] );
	}

	public function test_baseline_has_zero_diffs(): void {
		$ids = array();
		foreach ( self::$dataset->records() as $record ) {
			$ids[] = $record['id'];
		}
		$fields   = array_merge( array_keys( Dataset::FIELDS ), array( 'sessions' ) );
		$scenario = new BenchReadScenario( $ids, $fields );
		$left     = new GlobalFunctionTarget( 'acf' );
		$right    = new GlobalFunctionTarget( 'acf-reread', static fn() => wp_cache_flush(), true );

		$report = ( new Runner() )->run( array( $scenario ), $left, $right );

		$this->assertGreaterThan( 0, $report->steps );
		$this->assertSame( array(), $report->diffs, 'ACF-vs-ACF baseline must have zero diffs.' );
	}
}
