<?php
/**
 * Compares an ACF value with the NF value of the same field (WP 2.6d).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Pure. Both sides are normalised first, because ACF keeps everything as strings in `wp_postmeta` while NF returns
 * typed values: scalars compare as strings (numbers numerically, `true` as `1`), null, '' and empty lists are all
 * "empty", associative arrays compare by key without empty entries, and lists keep their order.
 */
final class ValueComparer {

	/**
	 * Paths where the two values differ.
	 *
	 * @param mixed  $acf  Raw ACF value.
	 * @param mixed  $nf   NF value.
	 * @param string $path Path of this value (the field name at the top).
	 * @return string[] Empty when equal.
	 */
	public static function diff( mixed $acf, mixed $nf, string $path = '' ): array {
		$a = self::normalize( $acf );
		$b = self::normalize( $nf );
		if ( $a === $b ) {
			return array();
		}
		if ( is_array( $a ) && is_array( $b ) ) {
			$out = array();
			if ( array_is_list( $a ) && array_is_list( $b ) ) {
				for ( $i = 0, $n = max( count( $a ), count( $b ) ); $i < $n; $i++ ) {
					$out = array_merge( $out, self::diff( $a[ $i ] ?? null, $b[ $i ] ?? null, $path . '[' . $i . ']' ) );
				}
				return $out;
			}
			foreach ( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) as $key ) {
				$out = array_merge( $out, self::diff( $a[ $key ] ?? null, $b[ $key ] ?? null, '' === $path ? (string) $key : $path . '.' . $key ) );
			}
			return $out;
		}
		return array( '' === $path ? '(value)' : $path );
	}

	/**
	 * Normalise a value for comparison.
	 *
	 * @param mixed $value Value.
	 * @return string|array<int|string,mixed> A string, or an array of normalised values (no empty entries when associative).
	 */
	public static function normalize( mixed $value ): string|array {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::normalize( $v );
			}
			if ( array() === $out ) {
				return '';
			}
			if ( array_is_list( $out ) ) {
				return $out;
			}
			$out = array_filter( $out, static fn( $v ) => '' !== $v );
			ksort( $out );
			return array() === $out ? '' : $out;
		}
		if ( null === $value || false === $value ) {
			return '';
		}
		if ( true === $value ) {
			return '1';
		}
		if ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ) {
			$f = (float) $value;
			return floor( $f ) === $f && abs( $f ) < 1e15 ? (string) (int) $f : rtrim( rtrim( number_format( $f, 10, '.', '' ), '0' ), '.' );
		}
		return (string) $value;
	}
}
