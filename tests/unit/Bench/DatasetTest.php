<?php
/**
 * Tests for the benchmark dataset generator.
 *
 * @package NF
 */

declare(strict_types=1);

use NF\Bench\AcfMeta;
use NF\Bench\Dataset;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/bench/src/Dataset.php';
require_once dirname( __DIR__, 3 ) . '/bench/src/AcfMeta.php';

/**
 * @covers \NF\Bench\Dataset
 * @covers \NF\Bench\AcfMeta
 */
final class DatasetTest extends TestCase {

	public function test_records_are_deterministic_and_order_independent(): void {
		$a = new Dataset( 50, 7, 500 );
		$b = new Dataset( 50, 7, 500 );
		$this->assertSame( $a->record( 42 ), $b->record( 42 ) );
		$this->assertSame( $a->record( 3 ), $b->record( 3 ) );
		$this->assertNotSame( $a->record( 3 ), ( new Dataset( 50, 8, 500 ) )->record( 3 ) );
	}

	public function test_ids_are_sequential_from_start_id(): void {
		$ids = array_column( iterator_to_array( ( new Dataset( 5, 1, 900 ) )->records() ), 'id' );
		$this->assertSame( array( 900, 901, 902, 903, 904 ), $ids );
	}

	public function test_record_shape_matches_design_benchmark(): void {
		$dataset = new Dataset( 200, 1 );
		foreach ( $dataset->records() as $record ) {
			$this->assertCount( 16, $record['fields'] );
			$this->assertCount( 5, $record['sessions'] );
			$this->assertGreaterThanOrEqual( 1, count( $record['fields']['categories'] ) );
			$this->assertLessThanOrEqual( 4, count( $record['fields']['artists'] ) );
			$this->assertSame( count( $record['fields']['artists'] ), count( array_unique( $record['fields']['artists'] ) ) );
			$this->assertMatchesRegularExpression( '/^\d{8}$/', $record['fields']['event_date'] );
			$this->assertContains( $record['fields']['featured'], array( 0, 1 ) );
		}
	}

	public function test_acf_rows_use_acf_storage_format(): void {
		$record = ( new Dataset( 3, 1 ) )->record( 1 );
		$rows   = AcfMeta::rows( $record );
		$map    = array_column( $rows, 1, 0 );
		// 16 fields x2 + repeater count x2 + 5 rows x 3 subs x2.
		$this->assertCount( 64, $rows );
		$this->assertSame( '5', $map['sessions'] );
		$this->assertSame( 'field_nfbench_sessions', $map['_sessions'] );
		$this->assertSame( 'field_nfbench_sessions_room', $map['_sessions_4_room'] );
		$this->assertSame( 'field_nfbench_price', $map['_price'] );
		$this->assertSame( array_map( 'strval', $record['fields']['artists'] ), unserialize( $map['artists'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Test of ACF format.
	}

	public function test_out_of_range_index_throws(): void {
		$this->expectException( OutOfRangeException::class );
		( new Dataset( 2 ) )->record( 2 );
	}
}
