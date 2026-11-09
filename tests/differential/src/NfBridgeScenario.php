<?php
/**
 * Scenario for the NF-vs-ACF check: the same writes through ACF, read back from ACF and from the NF tables.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

final class NfBridgeScenario implements ScenarioInterface {

	/** Step function name; the targets map it to NfProbe::run() with their own source. */
	public const PROBE = 'nf_probe';

	/**
	 * @param int[] $post_ids   Posts to write to.
	 * @param int[] $artist_ids Post IDs usable as relationship targets (at least 3).
	 */
	public function __construct(
		private readonly array $post_ids,
		private readonly array $artist_ids,
	) {}

	public function name(): string {
		return 'nf-bridge-round-trip';
	}

	public function steps(): array {
		$a     = $this->artist_ids;
		$row   = static fn( string $d, string $t, string $r ): array => array(
			'date' => $d,
			'time' => $t,
			'room' => $r,
		);
		$cases = array(
			'venue'      => array( array( 'Blue Hall' ), array( 'Blue Hall', 'Café "Ünï" <b>1</b>' ) ),
			'price'      => array( array( 12.5 ), array( 12.5, 0 ) ),
			'capacity'   => array( array( 300 ), array( 300, 7 ) ),
			'event_date' => array( array( '20261224' ), array( '20261224', '20270101' ) ),
			'featured'   => array( array( 1 ), array( 1, 0 ) ),
			'categories' => array( array( array( 'music', 'film' ) ), array( array( 'music', 'film' ), array( 'comedy' ) ) ),
			'artists'    => array( array( array( $a[0], $a[1] ) ), array( array( $a[0], $a[1] ), array( $a[2] ) ) ),
			'sessions'   => array(
				array( array( $row( '20261224', '18:30:00', 'A' ), $row( '20261225', '20:00:00', 'B' ) ) ),
				array( array( $row( '20261224', '18:30:00', 'A' ), $row( '20261225', '20:00:00', 'B' ) ), array( $row( '20270101', '09:00:00', 'C' ) ) ),
				array( array() ),
			),
		);

		$steps = array();
		foreach ( $this->post_ids as $post_id ) {
			foreach ( $cases as $field => $variants ) {
				foreach ( $variants as $n => $values ) {
					$steps[] = new Step( self::PROBE, array( $field, $post_id, $values ), "nf:{$field}:{$n}:{$post_id}" );
				}
			}
			$steps[] = new Step( self::PROBE, array( 'artists', $post_id, array( array() ) ), "nf:artists-empty:{$post_id}" );
		}
		return $steps;
	}
}
