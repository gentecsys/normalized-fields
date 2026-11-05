<?php
/**
 * Tests for the benchmark dataset generator.
 *
 * @package NF
 */

declare(strict_types=1);

use NF\Bench\AcfMeta;
use NF\Bench\AcfSchema;
use NF\Bench\Dataset;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/bench/src/Dataset.php';
require_once dirname( __DIR__, 3 ) . '/bench/src/AcfMeta.php';
require_once dirname( __DIR__, 3 ) . '/bench/src/AcfSchema.php';

/**
 * Dataset determinism, shape and ACF row format.
 *
 * @covers \NF\Bench\Dataset
 * @covers \NF\Bench\AcfMeta
 * @covers \NF\Bench\AcfSchema
 */
final class DatasetTest extends TestCase {

	/**
	 * Test records are deterministic and order independent.
	 */
	public function test_records_are_deterministic_and_order_independent(): void {
		$a = new Dataset( 50, 7, 500 );
		$b = new Dataset( 50, 7, 500 );
		$this->assertSame( $a->record( 42 ), $b->record( 42 ) );
		$this->assertSame( $a->record( 3 ), $b->record( 3 ) );
		$this->assertNotSame( $a->record( 3 ), ( new Dataset( 50, 8, 500 ) )->record( 3 ) );
	}

	/**
	 * Test ids are sequential from start id.
	 */
	public function test_ids_are_sequential_from_start_id(): void {
		$ids = array_column( iterator_to_array( ( new Dataset( 5, 1, 900 ) )->records() ), 'id' );
		$this->assertSame( array( 900, 901, 902, 903, 904 ), $ids );
	}

	/**
	 * Test record shape matches design benchmark.
	 */
	public function test_record_shape_matches_design_benchmark(): void {
		$dataset = new Dataset( 200, 1 );
		foreach ( $dataset->records() as $record ) {
			$this->assertCount( 17, $record['fields'] );
			$this->assertCount( 5, $record['sessions'] );
			$this->assertGreaterThanOrEqual( 1, count( $record['fields']['categories'] ) );
			$this->assertLessThanOrEqual( 4, count( $record['fields']['artists'] ) );
			$this->assertSame( count( $record['fields']['artists'] ), count( array_unique( $record['fields']['artists'] ) ) );
			$this->assertMatchesRegularExpression( '/^\d{8}$/', $record['fields']['event_date'] );
			$this->assertContains( $record['fields']['featured'], array( 0, 1 ) );
		}
	}

	/**
	 * Test acf rows use acf storage format.
	 */
	public function test_acf_rows_use_acf_storage_format(): void {
		$record = ( new Dataset( 3, 1 ) )->record( 1 );
		$rows   = AcfMeta::rows( $record );
		$map    = array_column( $rows, 1, 0 );
		// 17 fields x2 + repeater count x2 + 5 rows x 3 subs x2 = 66, as in the design benchmark.
		$this->assertCount( 66, $rows );
		$this->assertSame( '5', $map['sessions'] );
		$this->assertSame( 'field_nfbench_sessions', $map['_sessions'] );
		$this->assertSame( 'field_nfbench_sessions_room', $map['_sessions_4_room'] );
		$this->assertSame( 'field_nfbench_price', $map['_price'] );
		$this->assertSame( array_map( 'strval', $record['fields']['artists'] ), unserialize( $map['artists'] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- Test of ACF format.
	}

	/**
	 * Test out of range index throws.
	 */
	public function test_out_of_range_index_throws(): void {
		$this->expectException( OutOfRangeException::class );
		( new Dataset( 2 ) )->record( 2 );
	}

	/**
	 * Test fingerprint is stable and seed-sensitive.
	 */
	public function test_fingerprint_is_stable_and_seed_sensitive(): void {
		if ( ! function_exists( 'wp_json_encode' ) ) {
			$this->markTestSkipped( 'Needs WordPress.' );
		}
		$this->assertSame( ( new Dataset( 20, 1 ) )->fingerprint(), ( new Dataset( 20, 1 ) )->fingerprint() );
		$this->assertNotSame( ( new Dataset( 20, 1 ) )->fingerprint(), ( new Dataset( 20, 2 ) )->fingerprint() );
	}

	/**
	 * Test the ACF group covers every dataset field with matching keys and 10 text fields.
	 */
	public function test_acf_group_matches_dataset_fields(): void {
		$group = AcfSchema::group();
		$names = array_column( $group['fields'], 'name' );
		$this->assertSame( array_merge( array_keys( Dataset::FIELDS ), array( 'sessions' ) ), $names );
		$this->assertCount( 10, array_filter( array_keys( Dataset::FIELDS ), static fn( $n ) => 0 === strpos( $n, 'text_' ) ) );

		$keys = array_column( $group['fields'], 'key', 'name' );
		foreach ( AcfMeta::rows( ( new Dataset( 1 ) )->record( 0 ) ) as $row ) {
			if ( 0 === strpos( $row[0], '_' ) && 0 !== strpos( $row[0], '_sessions_' ) ) {
				$this->assertSame( $keys[ substr( $row[0], 1 ) ], $row[1] );
			}
		}

		$sessions = $group['fields'][ count( $group['fields'] ) - 1 ];
		$this->assertSame( 'repeater', $sessions['type'] );
		$this->assertSame( array( 'field_nfbench_sessions_date', 'field_nfbench_sessions_time', 'field_nfbench_sessions_room' ), array_column( $sessions['sub_fields'], 'key' ) );

		$checkbox = $group['fields'][ array_search( 'categories', $names, true ) ];
		$this->assertCount( 12, $checkbox['choices'] );
		$this->assertSame( 'id', $group['fields'][ array_search( 'artists', $names, true ) ]['return_format'] );
	}

	/**
	 * Test the committed Local JSON equals the generated group.
	 */
	public function test_local_json_is_in_sync(): void {
		$file = dirname( __DIR__, 3 ) . '/bench/acf-json/group_nfbench_events.json';
		$this->assertSame( AcfSchema::json(), file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}
}
