<?php
/**
 * Test double migration.
 *
 * @package NF
 */

/**
 * Records its version when run.
 */
class UpgraderTestMigration implements NF\Install\Migrations\MigrationInterface {

	/**
	 * Constructor.
	 *
	 * @param int         $v   Version.
	 * @param ArrayObject $log Run log.
	 */
	public function __construct( private int $v, private ArrayObject $log ) {}

	/**
	 * Version.
	 *
	 * @return int
	 */
	public function version(): int {
		return $this->v;
	}

	/**
	 * Record the run.
	 *
	 * @return void
	 */
	public function up(): void {
		$this->log[] = $this->v;
	}
}
