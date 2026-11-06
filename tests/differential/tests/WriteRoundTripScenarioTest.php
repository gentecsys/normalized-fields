<?php
/**
 * Pure-PHP checks of the write scenario's step list.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

use PHPUnit\Framework\TestCase;

final class WriteRoundTripScenarioTest extends TestCase {

	public function test_steps_cover_spike_types_and_have_unique_labels(): void {
		$steps  = ( new WriteRoundTripScenario( array( 10, 11 ), array( 1, 2, 3 ) ) )->steps();
		$labels = array_map( static fn( Step $s ): string => $s->label, $steps );
		$this->assertSame( count( $labels ), count( array_unique( $labels ) ) );
		$this->assertCount( 2 * ( 8 * 2 + 3 ), $steps );
		foreach ( array( 'venue', 'price', 'capacity', 'event_date', 'featured', 'categories', 'artists', 'sessions' ) as $field ) {
			$this->assertContains( "wrd:{$field}:10", $labels );
			$this->assertContains( "overwrite:{$field}:11", $labels );
		}
		$this->assertContains( 'wrd:sessions-empty:10', $labels );
	}
}
