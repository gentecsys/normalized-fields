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
	 * DDL in test_missing_table_is_created commits implicitly, so the lock row can outlive the
	 * test's rollback. Remove it for good after every test.
	 */
	public function tear_down() {
		global $wpdb;
		delete_option( Upgrader::LOCK );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		parent::tear_down();
	}

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
	 * A visitor's request never upgrades: no DDL or option write from the front end (D-18).
	 */
	public function test_anonymous_request_does_not_upgrade() {
		wp_set_current_user( 0 );
		update_option( Upgrader::OPTION, 1 );
		$up = new Upgrader();
		$up->register();
		do_action( 'init' );
		$this->assertSame( 1, (int) get_option( Upgrader::OPTION ) );
		$this->assertFalse( Upgrader::isCurrent() );
		remove_action( 'init', array( $up, 'upgradeForPrivilegedUser' ), 1 );
	}

	/**
	 * A user without activate_plugins does not upgrade either.
	 */
	public function test_editor_request_does_not_upgrade() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		update_option( Upgrader::OPTION, 1 );
		( new Upgrader() )->upgradeForPrivilegedUser();
		$this->assertSame( 1, (int) get_option( Upgrader::OPTION ) );
	}

	/**
	 * An administrator's request upgrades on init.
	 */
	public function test_admin_request_upgrades() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Upgrader::OPTION, 1 );
		( new Upgrader() )->upgradeForPrivilegedUser();
		$this->assertTrue( Upgrader::isCurrent() );
	}

	/**
	 * A fresh lock held by another request blocks the upgrade; the lock is left alone.
	 */
	public function test_fresh_lock_blocks_upgrade() {
		update_option( Upgrader::OPTION, 1 );
		add_option( Upgrader::LOCK, (string) time(), '', false );
		$this->assertFalse( ( new Upgrader() )->maybe_upgrade() );
		$this->assertSame( 1, (int) get_option( Upgrader::OPTION ) );
		$this->assertNotFalse( get_option( Upgrader::LOCK ) );
		delete_option( Upgrader::LOCK );
	}

	/**
	 * A stale lock (crashed upgrade) is taken over, and the lock is released afterwards.
	 */
	public function test_stale_lock_is_taken_over_and_released() {
		update_option( Upgrader::OPTION, 1 );
		add_option( Upgrader::LOCK, (string) ( time() - Upgrader::LOCK_TTL - 1 ), '', false );
		$this->assertTrue( ( new Upgrader() )->maybe_upgrade() );
		$this->assertTrue( Upgrader::isCurrent() );
		$this->assertFalse( get_option( Upgrader::LOCK ) );
	}
}
