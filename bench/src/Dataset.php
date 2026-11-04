<?php
/**
 * Deterministic synthetic event catalogue used by every benchmark.
 *
 * @package NF\Bench
 */

declare(strict_types=1);

namespace NF\Bench;

/**
 * Generates the same event records for a given (seed, posts, start_id), in any order.
 *
 * The shape follows docs/design.md "Benchmark: query speed, ACF vs NF": 16 top-level
 * fields (including a 12-value category checkbox and a 1-4 artist relationship) and a
 * 5-row x 3-sub-field `sessions` repeater.
 */
final class Dataset {

	public const POST_TYPE = 'nf_bench_event';

	public const CATEGORIES = array(
		'music',
		'theatre',
		'comedy',
		'dance',
		'film',
		'sport',
		'talk',
		'workshop',
		'festival',
		'family',
		'food',
		'art',
	);

	/** Scalar fields in storage order: name => ACF field type. */
	public const FIELDS = array(
		'event_date'   => 'date_picker',
		'price'        => 'number',
		'capacity'     => 'number',
		'featured'     => 'true_false',
		'venue'        => 'text',
		'categories'   => 'checkbox',
		'artists'      => 'relationship',
		'text_1'       => 'text',
		'text_2'       => 'text',
		'text_3'       => 'text',
		'text_4'       => 'text',
		'text_5'       => 'text',
		'text_6'       => 'text',
		'text_7'       => 'text',
		'text_8'       => 'text',
		'text_9'       => 'text',
	);

	public const SESSION_ROWS     = 5;
	public const SESSION_SUBS     = array( 'date', 'time', 'room' );
	public const ARTIST_POOL_SIZE = 500;

	private const WORDS = array( 'alpha', 'bravo', 'cedar', 'delta', 'ember', 'fjord', 'garnet', 'harbor', 'iris', 'juniper', 'kelp', 'lumen', 'mosaic', 'nectar', 'onyx', 'prism' );

	/**
	 * @param int $posts    Number of events.
	 * @param int $seed     Seed; same seed gives the same data.
	 * @param int $start_id Post ID of event 0; event i has ID start_id + i.
	 */
	public function __construct(
		public readonly int $posts,
		public readonly int $seed = 1,
		public readonly int $start_id = 1000000
	) {
		if ( $posts < 1 || $start_id < 1 ) {
			throw new \InvalidArgumentException( 'posts and start_id must be positive.' );
		}
	}

	/**
	 * Stable 48-bit pseudo-random integer for (index, salt), independent of call order.
	 *
	 * @param int    $index Event index.
	 * @param string $salt  Purpose label.
	 */
	private function rand( int $index, string $salt ): int {
		return (int) hexdec( substr( md5( $this->seed . ':' . $index . ':' . $salt ), 0, 12 ) );
	}

	/**
	 * Inclusive random integer in [min, max].
	 *
	 * @param int    $index Event index.
	 * @param string $salt  Purpose label.
	 * @param int    $min   Lower bound.
	 * @param int    $max   Upper bound.
	 */
	private function between( int $index, string $salt, int $min, int $max ): int {
		return $min + ( $this->rand( $index, $salt ) % ( $max - $min + 1 ) );
	}

	/**
	 * Date as Ymd, `$offset` days after 2024-01-01.
	 *
	 * @param int $offset Day offset.
	 */
	private static function day( int $offset ): string {
		return gmdate( 'Ymd', 1704067200 + $offset * 86400 );
	}

	/**
	 * Build the canonical record for event `$index`.
	 *
	 * @param int $index Zero-based event index.
	 * @return array<string,mixed>
	 */
	public function record( int $index ): array {
		if ( $index < 0 || $index >= $this->posts ) {
			throw new \OutOfRangeException( 'Event index out of range.' );
		}

		$cat_count  = $this->between( $index, 'cat_n', 1, 4 );
		$categories = array();
		for ( $i = 0; count( $categories ) < $cat_count; $i++ ) {
			$slug = self::CATEGORIES[ $this->between( $index, 'cat_' . $i, 0, 11 ) ];
			if ( ! in_array( $slug, $categories, true ) ) {
				$categories[] = $slug;
			}
		}
		sort( $categories );

		$artist_count = $this->between( $index, 'artist_n', 1, 4 );
		$artists      = array();
		for ( $i = 0; count( $artists ) < $artist_count; $i++ ) {
			$artist = $this->between( $index, 'artist_' . $i, 1, self::ARTIST_POOL_SIZE );
			if ( ! in_array( $artist, $artists, true ) ) {
				$artists[] = $artist;
			}
		}
		sort( $artists );

		$event_day = $this->between( $index, 'event_date', 0, 1460 );
		$fields    = array(
			'event_date' => self::day( $event_day ),
			'price'      => $this->between( $index, 'price', 0, 500 ),
			'capacity'   => $this->between( $index, 'capacity', 10, 5000 ),
			'featured'   => $this->between( $index, 'featured', 1, 100 ) <= 15 ? 1 : 0,
			'venue'      => 'Venue ' . $this->between( $index, 'venue', 1, 200 ),
			'categories' => $categories,
			'artists'    => $artists,
		);
		for ( $t = 1; $t <= 9; $t++ ) {
			$words = array();
			for ( $w = 0; $w < 6; $w++ ) {
				$words[] = self::WORDS[ $this->between( $index, "t{$t}_{$w}", 0, 15 ) ];
			}
			$fields[ 'text_' . $t ] = implode( ' ', $words );
		}

		$sessions = array();
		for ( $r = 0; $r < self::SESSION_ROWS; $r++ ) {
			$sessions[] = array(
				'date' => self::day( $event_day + $r ),
				'time' => sprintf( '%02d:%02d', $this->between( $index, "s{$r}_h", 8, 23 ), 15 * $this->between( $index, "s{$r}_m", 0, 3 ) ),
				'room' => 'Room ' . $this->between( $index, "s{$r}_room", 1, 30 ),
			);
		}

		return array(
			'id'        => $this->start_id + $index,
			'title'     => 'Event ' . ( $index + 1 ),
			'post_date' => gmdate( 'Y-m-d H:i:s', 1704067200 + ( $index % 1461 ) * 86400 + ( $index % 86400 ) ),
			'fields'    => $fields,
			'sessions'  => $sessions,
		);
	}

	/**
	 * Iterate all records in ID order.
	 *
	 * @return \Generator<int,array<string,mixed>>
	 */
	public function records(): \Generator {
		for ( $i = 0; $i < $this->posts; $i++ ) {
			yield $i => $this->record( $i );
		}
	}

	/**
	 * Hash of the whole logical dataset; both stores must reproduce it.
	 */
	public function fingerprint(): string {
		$ctx = hash_init( 'sha256' );
		foreach ( $this->records() as $record ) {
			hash_update( $ctx, wp_json_encode( $record ) . "\n" );
		}
		return hash_final( $ctx );
	}
}
