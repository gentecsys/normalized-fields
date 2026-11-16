<?php
/**
 * Finds arrays stored in scalar ACF fields (WP 3.0b).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Importers sometimes write arrays into text-like fields. NF keeps them in `nf_json` and they are not queryable
 * (design: "Arrays in scalar fields"). This counts them per field, sampled and capped, so the owner knows before
 * the port. Top-level post meta only. Core tables, so `$wpdb` (ADR-0009). Read only.
 */
class OffTypeScanner {

	/**
	 * ACF types whose value is a scalar.
	 *
	 * @var string[]
	 */
	public const SCALAR_TYPES = array(
		'text',
		'textarea',
		'number',
		'range',
		'email',
		'url',
		'password',
		'wysiwyg',
		'oembed',
		'radio',
		'button_group',
		'true_false',
		'date_picker',
		'date_time_picker',
		'time_picker',
		'color_picker',
	);

	/**
	 * Top-level scalar fields of the groups, by meta name.
	 *
	 * @param array<int,array<string,mixed>> $groups Groups with `fields`.
	 * @return array<string,string> Field name => type.
	 */
	public function scalarFields( array $groups ): array {
		$out = array();
		foreach ( $groups as $group ) {
			foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				$type = (string) ( $field['type'] ?? '' );
				$name = (string) ( $field['name'] ?? '' );
				if ( '' !== $name && in_array( $type, self::SCALAR_TYPES, true ) ) {
					$out[ $name ] = $type;
				}
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Count serialized arrays in one post meta key, up to a cap.
	 *
	 * @param string $name Meta key.
	 * @param int    $cap  Most rows to count.
	 * @return int
	 */
	public function countArrays( string $name, int $cap ): int {
		global $wpdb;
		$cap = max( 1, $cap );
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM ( SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s LIMIT %d ) AS t", $name, 'a:%', $cap ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Fields that hold arrays.
	 *
	 * @param array<int,array<string,mixed>> $groups Groups with `fields`.
	 * @param int                            $cap    Count cap per field.
	 * @return array<int,array{name:string,type:string,arrays:int,capped:bool}>
	 */
	public function scan( array $groups, int $cap = 1000 ): array {
		$out = array();
		foreach ( $this->scalarFields( $groups ) as $name => $type ) {
			$n = $this->countArrays( $name, $cap );
			if ( $n > 0 ) {
				$out[] = array(
					'name'   => $name,
					'type'   => $type,
					'arrays' => $n,
					'capped' => $n >= $cap,
				);
			}
		}
		return $out;
	}

	/**
	 * Field IDs NF already stores off-type (`nf_offtype_fields`), for the health data.
	 *
	 * @return int[]
	 */
	public function registered(): array {
		return array_values( array_map( 'intval', (array) get_option( \NF\Storage\Store::OFF_TYPE_OPTION, array() ) ) );
	}
}
