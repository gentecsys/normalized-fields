<?php
/**
 * Data tables.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Typed value table a field is stored in (nf_fields.data_table).
 */
enum DataTable: string {
	case Int  = 'int';
	case Dec  = 'dec';
	case Date = 'date';
	case Str  = 'str';
	case Text = 'text';
	case Json = 'json';
	case Rows = 'rows';
	case None = 'none';

	/**
	 * Whether values live in a typed value table.
	 *
	 * @return bool
	 */
	public function hasValues(): bool {
		return ! in_array( $this, array( self::Rows, self::None ), true );
	}

	/**
	 * Logical table name.
	 *
	 * @return string
	 */
	public function table(): string {
		return 'nf_' . $this->value;
	}

	/**
	 * Convert a stored value to its PHP form.
	 *
	 * @param scalar|null $raw Raw database value.
	 * @return mixed
	 */
	public function fromDb( $raw ): mixed {
		return match ( $this ) {
			self::Int  => (int) $raw,
			self::Json => json_decode( (string) $raw, true ),
			default    => (string) $raw,
		};
	}

	/**
	 * Convert a PHP value to a bindable one.
	 *
	 * @param mixed $value     Value.
	 * @param bool  $allowLong Let a Str value pass over 191 chars (the Store spills it to nf_text).
	 * @return int|string
	 * @throws \InvalidArgumentException When the value does not fit the table.
	 */
	public function toDb( mixed $value, bool $allowLong = false ): int|string {
		switch ( $this ) {
			case self::Int:
				if ( ! is_numeric( $value ) && ! is_bool( $value ) ) {
					throw new \InvalidArgumentException( 'Integer field needs a numeric value.' );
				}
				return (int) $value;
			case self::Dec:
				if ( ! is_numeric( $value ) ) {
					throw new \InvalidArgumentException( 'Decimal field needs a numeric value.' );
				}
				return (string) $value;
			case self::Json:
				$json = wp_json_encode( $value );
				if ( false === $json ) {
					throw new \InvalidArgumentException( 'Value cannot be encoded as JSON.' );
				}
				return $json;
			case self::Str:
				if ( ! is_scalar( $value ) || ( ! $allowLong && mb_strlen( (string) $value ) > 191 ) ) {
					throw new \InvalidArgumentException( 'String value is longer than 191 characters; use a text field.' );
				}
				return (string) $value;
			default:
				if ( ! is_scalar( $value ) ) {
					throw new \InvalidArgumentException( 'Scalar value expected.' );
				}
				return (string) $value;
		}
	}
}
