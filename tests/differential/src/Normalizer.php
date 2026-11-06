<?php
/**
 * Turns raw ACF results into a canonical form, so only real differences remain.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

final class Normalizer {

	/**
	 * Objects become arrays, associative arrays are key-sorted, floats are rounded.
	 * Types are NOT unified: ACF return types (int vs string) are part of the contract.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	public static function normalize( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$value = array( '__class' => get_class( $value ) ) + get_object_vars( $value );
		}
		if ( is_float( $value ) ) {
			return round( $value, 9 );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$out = array();
		foreach ( $value as $key => $item ) {
			$out[ $key ] = self::normalize( $item );
		}
		if ( ! array_is_list( $out ) ) {
			ksort( $out, SORT_STRING );
		}
		return $out;
	}
}
