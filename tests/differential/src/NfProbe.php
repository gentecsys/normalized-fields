<?php
/**
 * Write-then-read probe for the NF-vs-ACF differential check. The write always goes through ACF (bridge mode mirrors it
 * into NF); the read comes from the source under test: ACF's own storage, or the NF tables.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

use NF\Bench\AcfMeta;
use NF\Plugin;
use NF\Storage\ObjectType;

final class NfProbe {

	/** Series sub-fields compared, in the bench schema. */
	private const SUBS = array( 'date', 'time', 'room' );

	/**
	 * Delete, write each value in turn (so a second value is an overwrite), read, delete, read again.
	 *
	 * @param string  $source  'acf' or 'nf': where the reads come from.
	 * @param string  $field   Field name.
	 * @param int     $post_id Post ID.
	 * @param mixed[] $values  Values to write in order.
	 * @return array<string,mixed>
	 */
	public static function run( string $source, string $field, int $post_id, array $values ): array {
		$key = AcfMeta::key( $field );
		delete_field( $key, $post_id );
		foreach ( $values as $value ) {
			update_field( $key, $value, $post_id );
		}
		$read = self::read( $source, $field, $post_id );
		delete_field( $key, $post_id );
		return array(
			'read'  => $read,
			'after' => self::read( $source, $field, $post_id ),
		);
	}

	/**
	 * Read a field from one source in canonical form.
	 *
	 * @param string $source  'acf' or 'nf'.
	 * @param string $field   Field name.
	 * @param int    $post_id Post ID.
	 * @return mixed
	 */
	public static function read( string $source, string $field, int $post_id ): mixed {
		wp_cache_flush();
		if ( 'sessions' === $field ) {
			return self::canonical( 'nf' === $source ? self::nf_rows( $post_id ) : self::acf_rows( $post_id ) );
		}
		if ( 'nf' === $source ) {
			$def = Plugin::fields()->byKey( AcfMeta::key( $field ) );
			return self::canonical( null === $def ? null : Plugin::store()->get( ObjectType::Post, $post_id, $def->key ) );
		}
		return self::canonical( get_field( $field, $post_id, false ) );
	}

	/**
	 * Series rows as ACF stores them (row meta), since get_field() returns only a count for a Series.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,array<string,mixed>>
	 */
	private static function acf_rows( int $post_id ): array {
		$count = (int) get_post_meta( $post_id, 'sessions', true );
		$rows  = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$row = array();
			foreach ( self::SUBS as $sub ) {
				$row[ $sub ] = get_post_meta( $post_id, "sessions_{$i}_{$sub}", true );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Series rows from the NF tables.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,array<string,mixed>>
	 */
	private static function nf_rows( int $post_id ): array {
		$def = Plugin::fields()->byKey( AcfMeta::key( 'sessions' ) );
		if ( null === $def ) {
			return array();
		}
		$rows = Plugin::store()->getRows( ObjectType::Post, $post_id, $def->key );
		return array_map(
			static fn( array $row ): array => array_intersect_key( $row, array_flip( self::SUBS ) ),
			$rows
		);
	}

	/**
	 * Canonical form: empty values are null, numeric strings are numbers as strings, `Y-m-d 00:00:00` is `Ymd`, so the
	 * ACF and NF storage types compare on value, not on representation.
	 *
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	public static function canonical( mixed $value ): mixed {
		if ( null === $value || '' === $value || false === $value || array() === $value ) {
			return null;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ $k ] = self::canonical( $v );
			}
			return $out;
		}
		$string = (string) $value;
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2}) 00:00:00$/', $string, $m ) ) {
			return $m[1] . $m[2] . $m[3];
		}
		return is_numeric( $string ) ? (string) (float) $string : $string;
	}
}
