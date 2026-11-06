<?php
/**
 * Write scenario for the spike types: scalar (text, number, date, true/false, checkbox), relationship and Series rows.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

final class WriteRoundTripScenario implements ScenarioInterface {

	/**
	 * @param int[] $post_ids   Bench posts to write to.
	 * @param int[] $artist_ids Existing post IDs usable as relationship targets (at least 3).
	 */
	public function __construct(
		private readonly array $post_ids,
		private readonly array $artist_ids,
	) {}

	public function name(): string {
		return 'write-round-trip';
	}

	public function steps(): array {
		$a = $this->artist_ids;
		$w = __NAMESPACE__ . '\\write_read_delete';
		$o = __NAMESPACE__ . '\\overwrite_read';

		$cases = array(
			'venue'      => array( 'Blue Hall', 'Café "Ünï" <b>1</b>' ),
			'price'      => array( 12.5, 0 ),
			'capacity'   => array( 300, 7 ),
			'event_date' => array( '20261224', '20270101' ),
			'featured'   => array( 1, 0 ),
			'categories' => array( array( 'music', 'film' ), array( 'comedy' ) ),
			'artists'    => array( array( $a[0], $a[1] ), array( $a[2] ) ),
			'sessions'   => array(
				array(
					array(
						'date' => '20261224',
						'time' => '18:30:00',
						'room' => 'A',
					),
					array(
						'date' => '20261225',
						'time' => '20:00:00',
						'room' => 'B',
					),
				),
				array(
					array(
						'date' => '20270101',
						'time' => '09:00:00',
						'room' => 'C',
					),
				),
			),
		);

		$steps = array();
		foreach ( $this->post_ids as $post_id ) {
			foreach ( $cases as $field => $values ) {
				$steps[] = new Step( $w, array( $field, $post_id, $values[0] ), "wrd:{$field}:{$post_id}" );
				$steps[] = new Step( $o, array( $field, $post_id, $values[0], $values[1] ), "overwrite:{$field}:{$post_id}" );
			}
			// Empty Series and empty relationship.
			$steps[] = new Step( $w, array( 'sessions', $post_id, array() ), "wrd:sessions-empty:{$post_id}" );
			$steps[] = new Step( $w, array( 'artists', $post_id, array() ), "wrd:artists-empty:{$post_id}" );
			$steps[] = new Step( 'get_fields', array( $post_id ), "get_fields:{$post_id}" );
		}
		return $steps;
	}
}
