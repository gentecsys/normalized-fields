<?php
/**
 * Pure-PHP checks of the NF-vs-ACF scenario and the canonical form.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

use PHPUnit\Framework\TestCase;

final class NfBridgeScenarioTest extends TestCase {

	public function test_canonical_unifies_storage_types(): void {
		$this->assertNull( NfProbe::canonical( '' ) );
		$this->assertNull( NfProbe::canonical( array() ) );
		$this->assertSame( '12.5', NfProbe::canonical( '12.5000' ) );
		$this->assertSame( '12.5', NfProbe::canonical( 12.5 ) );
		$this->assertSame( '0', NfProbe::canonical( 0 ) );
		$this->assertSame( '20261224', NfProbe::canonical( '2026-12-24 00:00:00' ) );
		$this->assertSame( '20261224', NfProbe::canonical( '20261224' ) );
		$this->assertSame( '18:30:00', NfProbe::canonical( '18:30:00' ) );
		$this->assertSame( array( '3', '4' ), NfProbe::canonical( array( 3, '4' ) ) );
	}

	public function test_steps_cover_every_spike_field(): void {
		$steps  = ( new NfBridgeScenario( array( 10, 11 ), array( 1, 2, 3 ) ) )->steps();
		$fields = array_unique( array_map( static fn( Step $s ): string => (string) $s->args[0], $steps ) );
		sort( $fields );
		$this->assertSame( array( 'artists', 'capacity', 'categories', 'event_date', 'featured', 'price', 'sessions', 'venue' ), $fields );
		$this->assertCount( 2 * 18, $steps );
		foreach ( $steps as $step ) {
			$this->assertSame( NfBridgeScenario::PROBE, $step->function );
		}
	}
}
