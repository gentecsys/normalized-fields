<?php
/**
 * Schema upgrades on plugin update.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Install;

use NF\Install\Migrations\MigrationInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the stored schema version (nf_db_version, per site) with SCHEMA_VERSION. When it is lower,
 * creates missing tables with dbDelta and runs the versioned migrations in order.
 * Raise SCHEMA_VERSION whenever contracts/schema.sql changes.
 */
final class Upgrader {

	/**
	 * Current schema version. 2 = 1.3 pivot tables added; 3 = 2.2a nf_dict added.
	 */
	public const SCHEMA_VERSION = 3;

	/**
	 * Option holding the installed schema version for the current site.
	 */
	public const OPTION = 'nf_db_version';

	/**
	 * Migrations, run in version order.
	 *
	 * @var MigrationInterface[]
	 */
	private array $migrations;

	/**
	 * Constructor.
	 *
	 * @param MigrationInterface[] $migrations Migrations to run.
	 */
	public function __construct( array $migrations = array() ) {
		usort( $migrations, static fn( MigrationInterface $a, MigrationInterface $b ): int => $a->version() <=> $b->version() );
		$this->migrations = $migrations;
	}

	/**
	 * Option used as an atomic lock so concurrent requests never run dbDelta at the same time.
	 */
	public const LOCK = 'nf_upgrade_lock';

	/**
	 * Seconds after which a lock left by a crashed upgrade is ignored.
	 */
	public const LOCK_TTL = 600;

	/**
	 * Capability a logged-in user needs for their request to run the upgrade.
	 */
	public const CAPABILITY = 'activate_plugins';

	/**
	 * Schema version found when register() upgraded at load (WP-CLI/cron), for the CLI report.
	 *
	 * @var int|null
	 */
	private ?int $upgradedFrom = null;

	/**
	 * Hook the upgrade into trusted contexts only.
	 *
	 * Visitor requests never upgrade (D-18: logged-out GET/HEAD never writes; no DDL from the front end).
	 * The upgrade runs from WP-CLI, WP-Cron (which also follows auto-updates), and any request by a
	 * user who can activate plugins (wp-admin, REST, front end). admin_init alone is not enough: it also
	 * fires for logged-out admin-ajax.php requests, and REST saves never reach it.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() ) {
			$before = (int) get_option( self::OPTION, 0 );
			if ( $this->maybe_upgrade() ) {
				$this->upgradedFrom = $before;
			}
		} else {
			// init: the current user is known; still before any NF write in the request.
			add_action( 'init', array( $this, 'upgradeForPrivilegedUser' ), 1 );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::add_command( 'nf upgrade', array( $this, 'cliUpgrade' ) );
		}
	}

	/**
	 * Run the upgrade when the current user may activate plugins.
	 *
	 * @return void
	 */
	public function upgradeForPrivilegedUser(): void {
		if ( ! self::isCurrent() && current_user_can( self::CAPABILITY ) ) {
			$this->maybe_upgrade();
		}
	}

	/**
	 * Bring the NF schema up to date.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nf upgrade
	 *
	 * @return void
	 */
	public function cliUpgrade(): void {
		$before = $this->upgradedFrom ?? (int) get_option( self::OPTION, 0 );
		$ran    = null !== $this->upgradedFrom || $this->maybe_upgrade();
		if ( $ran ) {
			\WP_CLI::success( sprintf( 'NF schema upgraded from %d to %d.', $before, self::SCHEMA_VERSION ) );
		} elseif ( self::isCurrent() ) {
			\WP_CLI::success( sprintf( 'NF schema is current (%d).', self::SCHEMA_VERSION ) );
		} else {
			\WP_CLI::error( 'Another NF upgrade is running; try again shortly.' );
		}
	}

	/**
	 * Whether the stored schema version is current. Code that writes NF tables checks this and skips
	 * the write instead of creating tables itself.
	 *
	 * @return bool
	 */
	public static function isCurrent(): bool {
		return (int) get_option( self::OPTION, 0 ) >= self::SCHEMA_VERSION;
	}

	/**
	 * Upgrade the current site when its schema version is behind. Callers must be a trusted context
	 * (activation, WP-CLI, cron, privileged user); see register().
	 *
	 * @return bool True when an upgrade ran; false when current or another request holds the lock.
	 */
	public function maybe_upgrade(): bool {
		if ( self::isCurrent() ) {
			return false;
		}
		if ( ! $this->acquireLock() ) {
			return false;
		}
		try {
			// Re-read after taking the lock: another request may have finished the upgrade meanwhile.
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			$stored = (int) get_option( self::OPTION, 0 );
			if ( $stored >= self::SCHEMA_VERSION ) {
				return false;
			}
			Schema::install();
			foreach ( $this->migrations as $migration ) {
				if ( $migration->version() > $stored && $migration->version() <= self::SCHEMA_VERSION ) {
					$migration->up();
				}
			}
			update_option( self::OPTION, self::SCHEMA_VERSION, true );
			return true;
		} finally {
			delete_option( self::LOCK );
		}
	}

	/**
	 * Take the lock. add_option() is an INSERT on a unique option_name, so only one request wins.
	 * A lock older than LOCK_TTL (crashed upgrade) is removed and retaken once.
	 *
	 * @return bool
	 */
	private function acquireLock(): bool {
		if ( add_option( self::LOCK, (string) time(), '', false ) ) {
			return true;
		}
		wp_cache_delete( self::LOCK, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		$since = (int) get_option( self::LOCK, 0 );
		if ( $since > 0 && time() - $since < self::LOCK_TTL ) {
			return false;
		}
		delete_option( self::LOCK );
		return add_option( self::LOCK, (string) time(), '', false );
	}
}
