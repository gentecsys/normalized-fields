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
			case 'group':
			case 'clone':
			case 'link':
			case 'google_map':
				// Stored as child fields on the parent (see composite()); the parent itself holds no value.
				return array( DataTable::None, false );
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
			case 'page_link':
			case 'user':
			case 'taxonomy':
				return array( DataTable::Int, $multiple || 'taxonomy' === $type );
			case 'date_picker':
			case 'date_time_picker':
				return array( DataTable::Date, false );
			case 'textarea':
			case 'wysiwyg':
			case 'oembed':
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
				// Multi-select: one nf_str row per chosen value, position = order.
				return array( DataTable::Str, $multiple );
			case 'checkbox':
				return array( DataTable::Str, true );
			default:
				return array( DataTable::Json, false );
		}
	}

	/**
	 * Child fields of a group-like type.
	 *
	 * Link and Google Map become pseudo sub-fields with fixed names; a Group uses its own sub-fields, which are
	 * stored on the parent's row (design: "Field type to table").
	 *
	 * @param array<string,mixed> $field ACF field.
	 * @return array<int,array{key:string,name:string,table:DataTable,multi:bool,field:array<string,mixed>|null}>|null Null when the type is not group-like.
	 */
	public static function composite( array $field ): ?array {
		$type = (string) ( $field['type'] ?? '' );
		$key  = (string) ( $field['key'] ?? '' );
		if ( 'link' === $type ) {
			$parts = array(
				'url'    => DataTable::Str,
				'title'  => DataTable::Str,
				'target' => DataTable::Str,
			);
		} elseif ( 'google_map' === $type ) {
			$parts = array(
				'lat'     => DataTable::Dec,
				'lng'     => DataTable::Dec,
				'address' => DataTable::Str,
				'zoom'    => DataTable::Int,
			);
		} elseif ( 'group' === $type || ( 'clone' === $type && self::isGroupClone( $field ) ) ) {
			$out = array();
			foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub ) {
				if ( ! is_array( $sub ) || empty( $sub['key'] ) || 'repeater' === ( $sub['type'] ?? '' ) ) {
					continue;
				}
				[ $table, $multi ] = self::storage( $sub );
				$out[]             = array(
					'key'   => (string) $sub['key'],
					'name'  => (string) ( $sub['name'] ?? '' ),
					'table' => $table,
					'multi' => $multi,
					'field' => $sub,
				);
			}
			return $out;
		} else {
			return null;
		}
		$out = array();
		foreach ( $parts as $name => $table ) {
			$out[] = array(
				'key'   => substr( $key, 0, 64 - strlen( $name ) - 2 ) . '__' . $name,
				'name'  => $name,
				'table' => $table,
				'multi' => false,
				'field' => null,
			);
		}
		return $out;
	}

	/**
	 * Whether a clone field behaves as a group (display `group`). A seamless clone has no value of its own.
	 *
	 * @param array<string,mixed> $field ACF clone field.
	 * @return bool
	 */
	public static function isGroupClone( array $field ): bool {
		return 'clone' === ( $field['type'] ?? '' ) && 'group' === ( $field['display'] ?? 'seamless' );
	}

	/**
	 * The fields a seamless clone adds at its own level, with the names ACF gives them.
	 *
	 * ACF resolves the cloned fields into `sub_fields`. With `prefix_name` on, each name is prefixed with the
	 * clone field's name (`<clone>_<name>`), unless ACF already did. Each field keeps its own key, so values
	 * are stored like any top-level field.
	 *
	 * @param array<string,mixed> $field ACF clone field.
	 * @return array<int,array<string,mixed>> Empty for a group clone or any other type.
	 */
	public static function seamlessFields( array $field ): array {
		if ( 'clone' !== ( $field['type'] ?? '' ) || self::isGroupClone( $field ) ) {
			return array();
		}
		$prefix = ! empty( $field['prefix_name'] ) ? (string) ( $field['name'] ?? '' ) . '_' : '';
		$out    = array();
		foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub ) {
			if ( ! is_array( $sub ) || empty( $sub['key'] ) ) {
				continue;
			}
			$name = (string) ( $sub['name'] ?? '' );
			if ( '' !== $prefix && ! str_starts_with( $name, $prefix ) ) {
				$sub['name'] = $prefix . $name;
			}
			$out[] = $sub;
		}
		return $out;
	}

	/**
	 * Normalise a group-like value (see composite()) into an array keyed by child name.
	 *
	 * Link: {url,title,target}. Google Map: {lat,lng,address,zoom}; extra keys are dropped.
	 *
	 * @param array<string,mixed> $field ACF field.
	 * @param mixed               $value Raw ACF value.
	 * @return array<string,mixed>|null Null when empty.
	 */
	public static function normalizeComposite( array $field, mixed $value ): ?array {
		$parts = self::composite( $field );
		if ( null === $parts || ! is_array( $value ) ) {
			return null;
		}
		$out = array();
		foreach ( $parts as $part ) {
			$raw = $value[ $part['name'] ] ?? $value[ $part['key'] ] ?? null;
			if ( is_array( $part['field'] ) && null !== $raw ) {
				$sub_parts = self::composite( $part['field'] );
				$raw       = null !== $sub_parts ? self::normalizeComposite( $part['field'], $raw ) : self::normalize( $raw, $part['table'], $part['multi'] );
			} else {
				$raw = self::normalize( $raw, $part['table'], $part['multi'] );
			}
			$out[ $part['name'] ] = $raw;
		}
		return array() === array_filter( $out, static fn( $v ) => null !== $v ) ? null : $out;
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
		if ( DataTable::Int === $table && is_object( $value ) ) {
			// WP_Post, WP_User or WP_Term passed instead of an ID.
			$id = $value->ID ?? $value->term_id ?? null;
			return null === $id ? null : (int) $id;
		}
		if ( DataTable::Int === $table && is_string( $value ) && preg_match( '/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m ) ) {
			// Time picker: seconds since midnight.
			return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) ( $m[3] ?? 0 );
		}
		return $value;
	}
}
