<?php
/**
 * Maps a dataset record to NF typed-table values (and back).
 *
 * @package NF\Bench
 */

declare(strict_types=1);

namespace NF\Bench;

/**
 * Pure mapping, no database. Dates are stored as DATETIME (`Y-m-d 00:00:00`) and
 * read back as the dataset's `Ymd` form.
 */
final class NfLayout {

	/** Storage table per scalar field: name => [table suffix, multi]. */
	private const SPECIAL = array(
		'event_date' => array( 'date', false ),
		'price'      => array( 'dec', false ),
		'capacity'   => array( 'dec', false ),
		'featured'   => array( 'int', false ),
		'categories' => array( 'str', true ),
		'artists'    => array( 'int', true ),
	);

	/** Series sub-field storage. */
	public const SUBS = array(
		'date' => 'date',
		'time' => 'time',
		'room' => 'str',
	);

	/**
	 * Database table suffix for a kind (`time` is stored in `int` as seconds since midnight).
	 *
	 * @param string $kind Kind.
	 */
	public static function table( string $kind ): string {
		return 'time' === $kind ? 'int' : $kind;
	}

	/**
	 * Storage for a top-level scalar field.
	 *
	 * @param string $name Field name.
	 * @return array{0:string,1:bool} Table suffix and multi flag.
	 */
	public static function scalar( string $name ): array {
		return self::SPECIAL[ $name ] ?? array( 'str', false );
	}

	/**
	 * Convert a dataset value to its database form.
	 *
	 * @param string $table Table suffix.
	 * @param mixed  $value Dataset value.
	 * @return int|string
	 */
	public static function toDb( string $table, mixed $value ): int|string {
		return match ( $table ) {
			'int'   => (int) $value,
			'dec'   => (string) $value,
			'time'  => (int) substr( (string) $value, 0, 2 ) * 3600 + (int) substr( (string) $value, 3, 2 ) * 60,
			'date'  => substr( (string) $value, 0, 4 ) . '-' . substr( (string) $value, 4, 2 ) . '-' . substr( (string) $value, 6, 2 ) . ' 00:00:00',
			default => (string) $value,
		};
	}

	/**
	 * Convert a database value back to the dataset form.
	 *
	 * @param string $table Table suffix.
	 * @param mixed  $raw   Database value.
	 * @return int|string|float
	 */
	public static function fromDb( string $table, mixed $raw ): int|string|float {
		return match ( $table ) {
			'int'   => (int) $raw,
			'dec'   => (float) $raw,
			'time'  => sprintf( '%02d:%02d', intdiv( (int) $raw, 3600 ), intdiv( (int) $raw % 3600, 60 ) ),
			'date'  => str_replace( '-', '', substr( (string) $raw, 0, 10 ) ),
			default => (string) $raw,
		};
	}

	/**
	 * Values of one record for a scalar field, in position order.
	 *
	 * @param array<string,mixed> $record Record.
	 * @param string              $name   Field name.
	 * @return array<int,int|string>
	 */
	public static function scalarValues( array $record, string $name ): array {
		[ $table, $multi ] = self::scalar( $name );
		$raw               = $multi ? (array) $record['fields'][ $name ] : array( $record['fields'][ $name ] );
		return array_map( static fn( $v ) => self::toDb( $table, $v ), array_values( $raw ) );
	}
}
