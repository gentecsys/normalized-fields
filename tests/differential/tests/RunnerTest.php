<?php
/**
 * Tests for the differential harness itself (WP 0.6a).
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential\Tests;

use NF\Tests\Differential\ClosureTarget;
use NF\Tests\Differential\Comparator;
use NF\Tests\Differential\Runner;
use NF\Tests\Differential\ScenarioInterface;
use NF\Tests\Differential\Step;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/bootstrap.php';

final class RunnerTest extends TestCase {

	/** A fake "ACF": an in-memory field store behind update_field/get_field. */
	private function fakeAcf( string $name, ?\Closure $tamper = null ): ClosureTarget {
		$store = new \ArrayObject();
		return new ClosureTarget(
			$name,
			static function ( Step $step ) use ( $store, $tamper ) {
				if ( 'update_field' === $step->function ) {
					$store[ $step->args[0] . ':' . $step->args[1] ] = $step->args[2];
					return true;
				}
				if ( 'boom' === $step->function ) {
					throw new \RuntimeException( 'x' );
				}
				$value = $store[ $step->args[0] . ':' . $step->args[1] ] ?? null;
				return null !== $tamper ? $tamper( $value ) : $value;
			},
			static function () use ( $store ): void {
				$store->exchangeArray( array() );
			}
		);
	}

	private function scenario(): ScenarioInterface {
		return new class() implements ScenarioInterface {
			public function name(): string {
				return 'series-roundtrip';
			}
			public function steps(): array {
				return array(
					new Step( 'update_field', array( 'sessions', 1, array( array( 'room' => 'A', 'b' => 2.0 ), array( 'room' => 'B', 'b' => 3.0 ) ) ) ),
					new Step( 'get_field', array( 'sessions', 1 ), 'sessions' ),
					new Step( 'boom', array() ),
				);
			}
		};
	}

	public function test_acf_vs_acf_baseline_has_zero_diffs(): void {
		$report = ( new Runner() )->run( array( $this->scenario() ), $this->fakeAcf( 'acf' ), $this->fakeAcf( 'acf-2' ) );
		$this->assertTrue( $report->isClean(), $report->summary() );
		$this->assertSame( 3, $report->steps );
	}

	public function test_harness_detects_a_changed_nested_value(): void {
		$tamper = static function ( $value ) {
			$value[1]['room'] = 'C';
			return $value;
		};
		$report = ( new Runner() )->run( array( $this->scenario() ), $this->fakeAcf( 'acf' ), $this->fakeAcf( 'nf', $tamper ) );
		$this->assertFalse( $report->isClean() );
		$this->assertSame( '[1].room', $report->diffs[0]->path );
	}

	public function test_type_differences_are_diffs(): void {
		$diffs = ( new Comparator() )->compare( 's', 'x', array( 'n' => 1 ), array( 'n' => '1' ) );
		$this->assertCount( 1, $diffs );
	}

	public function test_key_order_objects_and_float_noise_are_ignored(): void {
		$left  = array( 'b' => 1.0000000000001, 'a' => (object) array( 'x' => 1 ) );
		$right = array( 'a' => (object) array( 'x' => 1 ), 'b' => 1.0000000000002 );
		$this->assertSame( array(), ( new Comparator() )->compare( 's', 'x', $left, $right ) );
	}

	public function test_missing_keys_and_list_length_are_reported(): void {
		$diffs = ( new Comparator() )->compare( 's', 'x', array( 1, 2, 3 ), array( 1, 2 ) );
		$this->assertCount( 1, $diffs );
		$this->assertSame( '[2]', $diffs[0]->path );
	}
}
