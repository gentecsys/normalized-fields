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
	 * Current schema version. 2 = 1.3 pivot tables added.
	 */
	public const SCHEMA_VERSION = 2;

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
	 * Hook the check on load and in the admin.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade' ), 5 );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
	}

	/**
	 * Upgrade the current site when its schema version is behind.
	 *
	 * @return bool True when an upgrade ran.
	 */
	public function maybe_upgrade(): bool {
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
		update_option( self::OPTION, self::SCHEMA_VERSION, false );
		return true;
	}
}
