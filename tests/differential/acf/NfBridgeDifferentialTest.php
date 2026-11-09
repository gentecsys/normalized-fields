<?php
/**
 * NF-vs-ACF differential check (WP 1.4): values written through ACF, read back from ACF storage and from the NF tables
 * that bridge mode filled. Zero diffs required.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

use NF\Bench\AcfStore;
use NF\Bench\Dataset;
use NF\Install\Schema;
use NF\Plugin;
use NF\Storage\ObjectType;
use PHPUnit\Framework\TestCase;

final class NfBridgeDifferentialTest extends TestCase {

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
		Schema::install();
		// Bridge mode never writes for a logged-out GET (D-18): run as an administrator.
		wp_set_current_user( 1 );
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
		foreach ( self::$dataset->records() as $record ) {
			Plugin::store()->deleteObject( ObjectType::Post, $record['id'] );
		}
		( new AcfStore( $wpdb ) )->clear( self::$dataset );
		foreach ( self::$artist_ids as $id ) {
			wp_delete_post( $id, true );
		}
		// The bridge registered the bench fields; NF tables are real, so remove them or later suites see
		// two top-level fields with the same name (SpikeQuery then refuses the ambiguous reverse lookup).
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field\\_nfbench\\_%' ) );
		wp_cache_flush();
	}

	public function test_bridge_is_registered(): void {
		$this->assertTrue( function_exists( 'update_field' ), 'ACF must be loaded.' );
		$this->assertTrue( is_user_logged_in() );
	}

	public function test_probe_sees_nf_rows(): void {
		$id = self::$dataset->start_id;
		$r  = NfProbe::run( 'nf', 'venue', $id, array( 'Blue Hall' ) );
		$this->assertSame( 'Blue Hall', $r['read'], 'The bridge must have copied the ACF write into NF.' );
		$this->assertNull( $r['after'] );
	}

	public function test_nf_matches_acf(): void {
		$ids = array();
		foreach ( self::$dataset->records() as $record ) {
			$ids[] = $record['id'];
		}
		$scenario = new NfBridgeScenario( $ids, self::$artist_ids );
		$left     = new ClosureTarget( 'acf', static fn( Step $step ): mixed => NfProbe::run( 'acf', ...$step->args ) );
		$right    = new ClosureTarget( 'nf', static fn( Step $step ): mixed => NfProbe::run( 'nf', ...$step->args ) );

		$report = ( new Runner() )->run( array( $scenario ), $left, $right );

		$this->assertGreaterThan( 0, $report->steps );
		$this->assertSame( array(), $report->diffs, $report->summary() );
	}
}
