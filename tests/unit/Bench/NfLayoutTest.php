<?php
/**
 * Tests for the NF store layout of the benchmark dataset.
 *
 * @package NF
 */

declare(strict_types=1);

use NF\Bench\Dataset;
use NF\Bench\NfLayout;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/bench/src/Dataset.php';
require_once dirname( __DIR__, 3 ) . '/bench/src/NfLayout.php';

/**
 * Mapping from dataset values to NF typed-table values.
 *
 * @covers \NF\Bench\NfLayout
 */
final class NfLayoutTest extends TestCase {

	/**
	 * Test every dataset field has a storage table and values survive a round trip.
	 */
	public function test_every_field_round_trips(): void {
		$dataset = new Dataset( 20, 3, 100 );
		foreach ( $dataset->records() as $record ) {
			foreach ( array_keys( Dataset::FIELDS ) as $name ) {
				[ $table, $multi ] = NfLayout::scalar( $name );
				$values            = NfLayout::scalarValues( $record, $name );
				$this->assertNotEmpty( $values, $name );
				$back = array_map( static fn( $v ) => NfLayout::fromDb( $table, $v ), $values );
				$this->assertSame( $multi ? array_values( (array) $record['fields'][ $name ] ) : array( $record['fields'][ $name ] ), $multi && 'int' === $table ? array_map( 'intval', $back ) : $back, $name );
			}
			foreach ( $record['sessions'] as $row ) {
				foreach ( NfLayout::SUBS as $sub => $table ) {
					$this->assertSame( $row[ $sub ], NfLayout::fromDb( $table, NfLayout::toDb( $table, $row[ $sub ] ) ) );
				}
			}
		}
	}

	/**
	 * Test dates are written as DATETIME values.
	 */
	public function test_date_storage_form(): void {
		$this->assertSame( '2024-03-09 00:00:00', NfLayout::toDb( 'date', '20240309' ) );
		$this->assertSame( '20240309', NfLayout::fromDb( 'date', '2024-03-09 00:00:00' ) );
	}

	/**
	 * Test multi-valued fields keep order and the row count matches ACF's value rows.
	 */
	public function test_multi_values_keep_order(): void {
		$record = ( new Dataset( 5, 1, 1 ) )->record( 2 );
		$this->assertCount( count( $record['fields']['categories'] ), NfLayout::scalarValues( $record, 'categories' ) );
		$this->assertSame( array_map( 'intval', $record['fields']['artists'] ), NfLayout::scalarValues( $record, 'artists' ) );
	}
}
