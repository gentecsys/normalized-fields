<?php
/**
 * Versioned count cache (WP 3.4b).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Query;

defined( 'ABSPATH' ) || exit;

/**
 * Totals cached in the object cache. The key carries the pivot set's write_version, so every write
 * to the set makes older entries unreachable without any invalidation. Counters never go to a table
 * or file here (anonymous reads must not write to NF tables).
 */
final class CountCache {

	public const GROUP = 'nf_counts';

	/**
	 * Seconds an entry lives (filter `nf/count/cache_ttl`).
	 */
	private const TTL = 300;

	/**
	 * Cache key for a statement.
	 *
	 * @param int               $pivotId Pivot set id.
	 * @param int               $version Pivot write_version.
	 * @param string            $sql     Count statement.
	 * @param array<int,scalar> $params  Bound parameters.
	 * @return string
	 */
	public static function key( int $pivotId, int $version, string $sql, array $params ): string {
		return $pivotId . ':' . $version . ':' . sha1( $sql . "\0" . wp_json_encode( $params ) );
	}

	/**
	 * Cached total, or null on a miss.
	 *
	 * @param string $key Key from {@see self::key()}.
	 * @return int|null
	 */
	public static function get( string $key ): ?int {
		$found = false;
		$value = wp_cache_get( $key, self::GROUP, false, $found );
		return $found && is_int( $value ) ? $value : null;
	}

	/**
	 * Store a total.
	 *
	 * @param string $key   Key from {@see self::key()}.
	 * @param int    $count Total.
	 * @return void
	 */
	public static function set( string $key, int $count ): void {
		// The version in the key is read before the count runs, so a racing write only leaves an unreachable entry.
		$ttl = (int) apply_filters( 'nf/count/cache_ttl', self::TTL ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		wp_cache_set( $key, $count, self::GROUP, max( 1, $ttl ) );
	}
}
