<?php
/**
 * Schema upgrade tests.
 *
 * @package NF
 */

use NF\Install\Upgrader;

/**
 * A lower stored version recreates missing tables and runs migrations once.
 */
class UpgraderTest extends WP_UnitTestCase {

	/**
	 * Whether a table exists.
	 *
	 * @param string $name Table name.
	 * @return bool
	 */
	private function table_exists( string $name ): bool {
		global $wpdb;
		return $name === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Dropped table comes back when the stored version is lower.
	 */
	public function test_missing_table_is_created() {
		global $wpdb;
		$table = $wpdb->prefix . 'nf_pivot_sets';
		// The test suite rewrites DROP/CREATE TABLE to TEMPORARY; lift that so the real table is dropped and recreated.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB
		update_option( Upgrader::OPTION, 1 );
		$this->assertFalse( $this->table_exists( $table ) );
		$upgraded = ( new Upgrader() )->maybe_upgrade();
		$exists   = $this->table_exists( $table );
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		$this->assertTrue( $upgraded );
		$this->assertTrue( $exists );
		$this->assertSame( Upgrader::SCHEMA_VERSION, (int) get_option( Upgrader::OPTION ) );
		$this->assertFalse( ( new Upgrader() )->maybe_upgrade() );
	}

	/**
	 * Only migrations newer than the stored version run, in order.
	 */
	public function test_migrations_run_in_order_once() {
		require_once __DIR__ . '/UpgraderTestMigration.php';
		$log = new ArrayObject();
		update_option( Upgrader::OPTION, 0 );
		$up = new Upgrader(
			array(
				new UpgraderTestMigration( 2, $log ),
				new UpgraderTestMigration( 1, $log ),
				new UpgraderTestMigration( 99, $log ),
			)
		);
		$up->maybe_upgrade();
		$up->maybe_upgrade();
		$this->assertSame( array( 1, 2 ), $log->getArrayCopy() );
	}

	/**
	 * Register upgrades immediately, so non-admin requests repair the schema.
	 */
	public function test_register_upgrades_on_non_admin_request() {
		update_option( Upgrader::OPTION, 1 );
		( new Upgrader() )->register();
		$this->assertSame( Upgrader::SCHEMA_VERSION, (int) get_option( Upgrader::OPTION ) );
	}
}
