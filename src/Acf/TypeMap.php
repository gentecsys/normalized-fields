<?php
/**
 * Maps ACF field types to NF storage.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Acf;

use NF\Storage\DataTable;

defined( 'ABSPATH' ) || exit;

/**
 * Pure mapping from an ACF field array to a data table, plus value normalisation for storage.
 */
final class TypeMap {

	/**
	 * Data table and multi flag for an ACF field array.
	 *
	 * @param array<string,mixed> $field ACF field.
	 * @return array{0:DataTable,1:bool}
	 */
	public static function storage( array $field ): array {
		$type     = (string) ( $field['type'] ?? 'text' );
		$multiple = ! empty( $field['multiple'] );
		switch ( $type ) {
			case 'repeater':
				return array( DataTable::Rows, false );
			case 'number':
			case 'range':
				return array( DataTable::Dec, false );
			case 'time_picker':
				return array( DataTable::Int, false );
			case 'true_false':
			case 'image':
			case 'file':
				return array( DataTable::Int, false );
			case 'gallery':
			case 'relationship':
				return array( DataTable::Int, true );
			case 'post_object':
			case 'user':
			case 'taxonomy':
				return array( DataTable::Int, $multiple || 'taxonomy' === $type );
			case 'date_picker':
			case 'date_time_picker':
				return array( DataTable::Date, false );
			case 'textarea':
			case 'wysiwyg':
				return array( DataTable::Text, false );
			case 'text':
			case 'email':
			case 'url':
			case 'password':
			case 'radio':
			case 'button_group':
			case 'color_picker':
				// Values over 191 chars spill to nf_text inside the Store; the field stays in nf_str.
				return array( DataTable::Str, false );
			case 'select':
				return $multiple ? array( DataTable::Json, false ) : array( DataTable::Str, false );
			default:
				return array( DataTable::Json, false );
		}
	}

	/**
	 * Normalise an ACF value for the target table. Empty values become null (removed).
	 *
	 * @param mixed     $value Raw ACF value.
	 * @param DataTable $table Target table.
	 * @param bool      $multi Multi-valued.
	 * @return mixed
	 */
	public static function normalize( mixed $value, DataTable $table, bool $multi ): mixed {
		if ( null === $value || '' === $value || array() === $value ) {
			return null;
		}
		if ( $multi ) {
			$list = is_array( $value ) ? $value : array( $value );
			return array_map( fn( $v ) => self::normalize( $v, $table, false ), array_values( $list ) );
		}
		if ( DataTable::Date === $table ) {
			$s = (string) $value;
			if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $s, $m ) ) {
				return $m[1] . '-' . $m[2] . '-' . $m[3] . ' 00:00:00';
			}
			return $s;
		}
		if ( DataTable::Int === $table && is_array( $value ) ) {
			return isset( $value['ID'] ) ? (int) $value['ID'] : null;
		}
		if ( DataTable::Int === $table && is_string( $value ) && preg_match( '/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m ) ) {
			// Time picker: seconds since midnight.
			return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) ( $m[3] ?? 0 );
		}
		return $value;
	}
}
