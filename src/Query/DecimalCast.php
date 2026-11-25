<?php
/**
 * Core-parity decimal comparisons for the WP_Query translator (WP 3.3a2).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Query;

defined( 'ABSPATH' ) || exit;

/**
 * WP_Query casts the stored string for the clause `type` before comparing: `NUMERIC` and `SIGNED` truncate toward zero,
 * `DECIMAL(p,s)` rounds half away from zero to s places. NF stores the exact decimal, so a condition on the cast value is
 * rewritten as a range on the exact value. Pure integer arithmetic on decimal strings; no floats.
 */
final class DecimalCast {

	private const MAX_DIGITS = 17;

	/**
	 * Cast mode of a clause `type` for decimal fields.
	 *
	 * @param string $type Upper-case clause type.
	 * @return array{0:string,1:int}|null Mode (`trunc` or `round`) and scale; null for casts NF cannot reproduce.
	 */
	public static function parseType( string $type ): ?array {
		if ( 'NUMERIC' === $type || 'SIGNED' === $type ) {
			return array( 'trunc', 0 );
		}
		if ( 'DECIMAL' === $type ) {
			return array( 'round', 0 );
		}
		if ( 1 === preg_match( '/^DECIMAL\((\d+)(?:,(\d+))?\)$/', $type, $m ) ) {
			$scale = (int) ( $m[2] ?? 0 );
			if ( $scale > 10 || (int) $m[1] > 30 || $scale > (int) $m[1] ) {
				return null;
			}
			return array( 'round', $scale );
		}
		return null;
	}

	/**
	 * Whether a clause type is a numeric cast at all (used by the exact-value mode).
	 *
	 * @param string $type Upper-case clause type.
	 * @return bool
	 */
	public static function isNumericType( string $type ): bool {
		return in_array( $type, array( 'NUMERIC', 'SIGNED', 'UNSIGNED', 'DECIMAL' ), true ) || 1 === preg_match( '/^DECIMAL\(\d+(,\d+)?\)$/', $type );
	}

	/**
	 * Rewrite `CAST(value) op clause_value` as conditions on the exact stored value (ANDed).
	 *
	 * @param string          $field Field name.
	 * @param string          $op    Operator: `= < <= > >= between`.
	 * @param string|string[] $value Decimal string, or two for between.
	 * @param string          $mode  `trunc` or `round`.
	 * @param int             $scale Places kept by the cast.
	 * @return Condition[]|null Null when not expressible (`!=`, `in`, `not_in`, an `=` off the grid, too many digits).
	 */
	public static function conditions( string $field, string $op, string|array $value, string $mode, int $scale ): ?array {
		if ( 'between' === $op ) {
			if ( ! is_array( $value ) || 2 !== count( $value ) ) {
				return null;
			}
			$lo = self::grid( (string) $value[0], $scale );
			$hi = self::grid( (string) $value[1], $scale );
			if ( null === $lo || null === $hi ) {
				return null;
			}
			return array( self::ge( $field, $lo[1], $mode, $scale ), self::le( $field, $hi[0], $mode, $scale ) );
		}
		if ( ! is_string( $value ) ) {
			return null;
		}
		$g = self::grid( $value, $scale );
		if ( null === $g ) {
			return null;
		}
		[ $floor, $ceil ] = $g;
		return match ( $op ) {
			'>='    => array( self::ge( $field, $ceil, $mode, $scale ) ),
			'>'     => array( self::ge( $field, $floor + 1, $mode, $scale ) ),
			'<='    => array( self::le( $field, $floor, $mode, $scale ) ),
			'<'     => array( self::le( $field, $ceil - 1, $mode, $scale ) ),
			'='     => $floor === $ceil ? array( self::ge( $field, $floor, $mode, $scale ), self::le( $field, $floor, $mode, $scale ) ) : null,
			default => null,
		};
	}

	/**
	 * Floor and ceiling of a decimal string on the grid of `$scale` places, as integers scaled by 10^scale.
	 *
	 * @param string $value Decimal string.
	 * @param int    $scale Places.
	 * @return array{0:int,1:int}|null
	 */
	private static function grid( string $value, int $scale ): ?array {
		if ( 1 !== preg_match( '/^(-?)(\d+)(?:\.(\d+))?$/', $value, $m ) ) {
			return null;
		}
		$frac = $m[3] ?? '';
		$kept = str_pad( substr( $frac, 0, $scale ), $scale, '0' );
		$rest = substr( $frac, $scale );
		$int  = ltrim( $m[2] . $kept, '0' );
		if ( strlen( $int ) > self::MAX_DIGITS - 2 ) {
			return null;
		}
		$abs   = '' === $int ? 0 : (int) $int;
		$exact = '' === $rest || 0 === strlen( rtrim( $rest, '0' ) );
		if ( '-' === $m[1] ) {
			return array( -$abs - ( $exact ? 0 : 1 ), -$abs );
		}
		return array( $abs, $abs + ( $exact ? 0 : 1 ) );
	}

	/**
	 * Condition for cast(value) >= grid index M.
	 *
	 * @param string $field Field.
	 * @param int    $m     Grid index (value x 10^scale).
	 * @param string $mode  trunc|round.
	 * @param int    $scale Places.
	 * @return Condition
	 */
	private static function ge( string $field, int $m, string $mode, int $scale ): Condition {
		$x = $m * 10;
		if ( 'trunc' === $mode ) {
			return $m > 0 ? new Condition( $field, '>=', self::format( $x, $scale ) ) : new Condition( $field, '>', self::format( $x - 10, $scale ) );
		}
		return new Condition( $field, $m > 0 ? '>=' : '>', self::format( $x - 5, $scale ) );
	}

	/**
	 * Condition for cast(value) <= grid index N.
	 *
	 * @param string $field Field.
	 * @param int    $n     Grid index.
	 * @param string $mode  trunc|round.
	 * @param int    $scale Places.
	 * @return Condition
	 */
	private static function le( string $field, int $n, string $mode, int $scale ): Condition {
		$x = $n * 10;
		if ( 'trunc' === $mode ) {
			return $n >= 0 ? new Condition( $field, '<', self::format( $x + 10, $scale ) ) : new Condition( $field, '<=', self::format( $x, $scale ) );
		}
		return new Condition( $field, $n >= 0 ? '<' : '<=', self::format( $x + 5, $scale ) );
	}

	/**
	 * Format an integer scaled by 10^(scale+1) as a decimal string.
	 *
	 * @param int $x     Scaled integer.
	 * @param int $scale Places of the grid.
	 * @return string
	 */
	private static function format( int $x, int $scale ): string {
		$neg  = $x < 0;
		$digs = str_pad( (string) abs( $x ), $scale + 2, '0', STR_PAD_LEFT );
		$out  = substr( $digs, 0, -( $scale + 1 ) ) . '.' . substr( $digs, -( $scale + 1 ) );
		return ( $neg ? '-' : '' ) . $out;
	}
}
