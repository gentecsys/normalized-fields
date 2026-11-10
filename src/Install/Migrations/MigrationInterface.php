<?php
/**
 * A versioned schema migration.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Install\Migrations;

defined( 'ABSPATH' ) || exit;

/**
 * One migration step, run once per site when the stored schema version is lower than version().
 */
interface MigrationInterface {

	/**
	 * Schema version this migration brings the site to.
	 *
	 * @return int
	 */
	public function version(): int;

	/**
	 * Apply the migration for the current site. Must be safe to run twice.
	 *
	 * @return void
	 */
	public function up(): void;
}
