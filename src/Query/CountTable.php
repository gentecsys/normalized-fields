<?php
/**
 * Table fallback for the versioned count cache (WP 3.4c).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Query;

use NF\Backend\BackendInterface;
use NF\Install\Upgrader;

defined( 'ABSPATH' ) || exit;

/**
 * `nf_count_cache`, used only when the site has no persistent object cache (contracts/schema.sql). A row is a hit only
 * when its version equals the pivot set's current write_version and it has not expired. Anonymous requests read but
 * never write, and nothing is read or written while the schema is behind.
 */
final class CountTable {

	/**
	 * Seconds a row lives (filter `nf/count_cache/ttl`).
	 */
	private const TTL = 86400;

	/**
	 * Cleanup job id.
	 */
	public const CLEANUP = 'count_cache_cleanup';

	/**
	 * Option holding the time the cleanup was last queued.
	 */
	public const CLEANUP_OPTION = 'nf_count_cleanup_at';

	/**
	 * Constructor.
	 *
	 * @param BackendInterface $db Backend.
	 */
	public function __construct( private BackendInterface $db ) {}

	/**
	 * Whether the table tier applies on this request.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return ! wp_using_ext_object_cache() && Upgrader::isCurrent();
	}

	/**
	 * Cached total, or null.
	 *
	 * @param string $key     Key from CountCache::key().
	 * @param int    $pivotId Pivot set id.
	 * @param int    $version Current write_version.
	 * @return int|null
	 */
	public function get( string $key, int $pivotId, int $version ): ?int {
		if ( ! $this->enabled() ) {
			return null;
		}
		$rows = $this->db->select(
			'SELECT n FROM ' . $this->db->table( 'nf_count_cache' ) . ' WHERE cache_key = ? AND pivot_id = ? AND version = ? AND expires > ?',
			array( sha1( $key ), $pivotId, $version, gmdate( 'Y-m-d H:i:s' ) )
		);
		return isset( $rows[0]['n'] ) ? (int) $rows[0]['n'] : null;
	}

	/**
	 * Store a total. Skipped for anonymous requests and while the schema is behind.
	 *
	 * @param string $key     Key from CountCache::key().
	 * @param int    $pivotId Pivot set id.
	 * @param int    $version write_version the count was computed at.
	 * @param int    $count   Total.
	 * @return void
	 */
	public function put( string $key, int $pivotId, int $version, int $count ): void {
		$cli = defined( 'WP_CLI' ) && WP_CLI;
		if ( ! $this->enabled() || ! ( $cli || is_user_logged_in() ) ) {
			return;
		}
		$ttl = max( 60, (int) apply_filters( 'nf/count_cache/ttl', self::TTL ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$t   = $this->db->table( 'nf_count_cache' );
		$this->db->execute( "DELETE FROM {$t} WHERE cache_key = ?", array( sha1( $key ) ) );
		$this->db->execute(
			"INSERT INTO {$t} (cache_key, pivot_id, version, n, expires) VALUES (?, ?, ?, ?, ?)",
			array( sha1( $key ), $pivotId, $version, $count, gmdate( 'Y-m-d H:i:s', time() + $ttl ) )
		);
		// Queue the cleanup at most once a day, from this writing request (never from an anonymous one).
		if ( (int) get_option( self::CLEANUP_OPTION, 0 ) < time() - DAY_IN_SECONDS ) {
			update_option( self::CLEANUP_OPTION, time(), false );
			\NF\Plugin::jobs()->start( self::CLEANUP );
		}
	}
}
