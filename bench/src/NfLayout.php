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
		'price'      => array( 'int', false ),
		'capacity'   => array( 'int', false ),
		'featured'   => array( 'int', false ),
		'categories' => array( 'str', true ),
		'artists'    => array( 'int', true ),
	);

	/** Series sub-field storage. */
	public const SUBS = array(
		'date' => 'date',
		'time' => 'str',
		'room' => 'str',
	);

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
			'date'  => substr( (string) $value, 0, 4 ) . '-' . substr( (string) $value, 4, 2 ) . '-' . substr( (string) $value, 6, 2 ) . ' 00:00:00',
			default => (string) $value,
		};
	}

	/**
	 * Convert a database value back to the dataset form.
	 *
	 * @param string $table Table suffix.
	 * @param mixed  $raw   Database value.
	 * @return int|string
	 */
	public static function fromDb( string $table, mixed $raw ): int|string {
		return match ( $table ) {
			'int'   => (int) $raw,
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
