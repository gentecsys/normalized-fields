<?php
/**
 * Failing migration used by UpgraderTest.
 *
 * @package NF
 */

/**
 * A migration that throws.
 */
class UpgraderTestFailingMigration implements NF\Install\Migrations\MigrationInterface {

	/**
	 * Version.
	 *
	 * @return int
	 */
	public function version(): int {
		return 2;
	}

	/**
	 * Fail.
	 *
	 * @return void
	 * @throws RuntimeException Always.
	 */
	public function up(): void {
		throw new RuntimeException( 'migration broke' );
	}
}
