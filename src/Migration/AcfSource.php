<?php
/**
 * Feeds ACF's field groups to the port (WP 2.6b).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the field groups through ACF's own API (database, acf-json and PHP registrations alike), with seamless
 * clones expanded as ACF resolves them. Nothing is written.
 */
class AcfSource {

	/**
	 * Whether ACF (or SCF) is loaded.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) && function_exists( 'acf_get_value' );
	}

	/**
	 * Field groups with their fields, in the shape `Scanner::scan()` takes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function groups(): array {
		if ( ! $this->available() ) {
			return array();
		}
		$out = array();
		foreach ( (array) acf_get_field_groups() as $group ) {
			if ( ! is_array( $group ) ) {
				continue;
			}
			$group['fields'] = array_values( array_filter( (array) acf_get_fields( $group ), 'is_array' ) );
			$out[]           = $group;
		}
		return $out;
	}

	/**
	 * Top-level fields of every collection the scan marks portable. A collection with flexible content is skipped
	 * as a whole (ADR-0008).
	 *
	 * @param array<int,array<string,mixed>> $groups Groups from groups().
	 * @return array<int,array<string,mixed>>
	 */
	public function portableFields( array $groups ): array {
		$report = ( new Scanner() )->scan( $groups );
		$fields = array();
		foreach ( $groups as $i => $group ) {
			if ( 'portable' !== ( $report['collections'][ $i ]['status'] ?? '' ) ) {
				continue;
			}
			foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
				if ( is_array( $field ) && ! empty( $field['key'] ) && ! empty( $field['name'] ) ) {
					$fields[] = $field;
				}
			}
		}
		return $fields;
	}
}
