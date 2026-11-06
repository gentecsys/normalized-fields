<?php
/**
 * ACF-vs-ACF baseline for write round-trips (scalar, relationship, Series). Zero diffs required.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

use NF\Bench\AcfStore;
use NF\Bench\Dataset;
use PHPUnit\Framework\TestCase;

final class AcfWriteBaselineTest extends TestCase {

	private const POSTS = 3;

	/**
	 * Dataset under test.
	 *
	 * @var Dataset
	 */
	private static Dataset $dataset;

	/**
	 * Real posts used as relationship targets.
	 *
	 * @var int[]
	 */
	private static array $artist_ids = array();

	public static function setUpBeforeClass(): void {
		global $wpdb;
		self::$dataset = new Dataset( self::POSTS );
		$store         = new AcfStore( $wpdb );
		$store->clear( self::$dataset );
		$store->write( self::$dataset );
		foreach ( array( 'Artist A', 'Artist B', 'Artist C' ) as $title ) {
			self::$artist_ids[] = (int) wp_insert_post(
				array(
					'post_title'  => $title,
					'post_status' => 'publish',
				)
			);
		}
		wp_cache_flush();
	}

	public static function tearDownAfterClass(): void {
		global $wpdb;
		( new AcfStore( $wpdb ) )->clear( self::$dataset );
		foreach ( self::$artist_ids as $id ) {
			wp_delete_post( $id, true );
		}
		wp_cache_flush();
	}

	public function test_probe_returns_what_was_written(): void {
		$id     = self::$dataset->start_id;
		$result = write_read_delete( 'venue', $id, 'Blue Hall' );
		$this->assertTrue( $result['updated'] );
		$this->assertSame( 'Blue Hall', $result['formatted'] );
		$this->assertTrue( $result['deleted'] );
		$this->assertEmpty( $result['after_empty'] );

		$rows = write_read_delete(
			'sessions',
			$id,
			array(
				array(
					'date' => '20261224',
					'time' => '18:30:00',
					'room' => 'A',
				),
			)
		);
		$this->assertCount( 1, $rows['formatted'], 'Series write must come back as one row.' );
		update_field( \NF\Bench\AcfMeta::key( 'sessions' ), $rows['raw'], $id );
		$this->assertSame( 'field_nfbench_sessions', get_post_meta( $id, '_sessions', true ), 'Write must store the field reference (typed).' );
		$this->assertSame( 'A', get_post_meta( $id, 'sessions_0_room', true ), 'Series must be stored as row meta, not serialized.' );
		$this->assertSame( 'A', $rows['formatted'][0]['room'] );
	}

	public function test_baseline_has_zero_diffs(): void {
		$ids = array();
		foreach ( self::$dataset->records() as $record ) {
			$ids[] = $record['id'];
		}
		$scenario = new WriteRoundTripScenario( $ids, self::$artist_ids );
		$left     = new GlobalFunctionTarget( 'acf' );
		$right    = new GlobalFunctionTarget( 'acf-reread', static fn() => wp_cache_flush(), true );

		$report = ( new Runner() )->run( array( $scenario ), $left, $right );

		$this->assertGreaterThan( 0, $report->steps );
		$this->assertSame( array(), $report->diffs, 'ACF-vs-ACF write baseline must have zero diffs.' );
	}
}
