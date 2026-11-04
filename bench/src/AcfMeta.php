<?php
/**
 * Maps a dataset record to ACF-format wp_postmeta rows.
 *
 * @package NF\Bench
 */

declare(strict_types=1);

namespace NF\Bench;

/**
 * ACF storage format: value row plus `_name` => field key row; checkbox and
 * relationship values serialized; repeater as `name`, `name_N_sub`.
 */
final class AcfMeta {

	/**
	 * ACF field key for a (sub-)field name.
	 *
	 * @param string $name Field name.
	 */
	public static function key( string $name ): string {
		return 'field_nfbench_' . $name;
	}

	/**
	 * Ordered [meta_key, meta_value] pairs for one record.
	 *
	 * @param array<string,mixed> $record Record from Dataset::record().
	 * @return list<array{0:string,1:string}>
	 */
	public static function rows( array $record ): array {
		$rows = array();
		foreach ( $record['fields'] as $name => $value ) {
			if ( is_array( $value ) ) {
				$value = serialize( array_map( 'strval', $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- ACF's own storage format.
			}
			$rows[] = array( $name, (string) $value );
			$rows[] = array( '_' . $name, self::key( $name ) );
		}
		$rows[] = array( 'sessions', (string) count( $record['sessions'] ) );
		$rows[] = array( '_sessions', self::key( 'sessions' ) );
		foreach ( $record['sessions'] as $r => $row ) {
			foreach ( $row as $sub => $value ) {
				$rows[] = array( "sessions_{$r}_{$sub}", (string) $value );
				$rows[] = array( "_sessions_{$r}_{$sub}", self::key( 'sessions_' . $sub ) );
			}
		}
		return $rows;
	}
}
