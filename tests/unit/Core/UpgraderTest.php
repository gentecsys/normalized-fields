<?php
/**
 * Schema upgrade tests.
 *
 * @package NF
 */

use NF\Install\Upgrader;

require_once __DIR__ . '/UpgraderTestMigration.php';
require_once __DIR__ . '/UpgraderTestFailingMigration.php';

/**
 * A lower stored version recreates missing tables and runs migrations once; only explicit triggers upgrade.
 */
class UpgraderTest extends WP_UnitTestCase {

	/**
	 * Remove a lock left by an earlier test, before and after each test.
	 *
	 * The DDL that dbDelta runs (CREATE TABLE here; ALTER TABLE on MySQL 5.7 and MariaDB, where dbDelta sees
	 * column differences) commits implicitly, so the lock row can be committed while its delete
	 * in finally is rolled back with the test. Production runs outside a transaction.
	 */
	public static function clear_lock(): void {
		global $wpdb;
		delete_option( Upgrader::LOCK );
		$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Start each test without a lock.
	 */
	public function set_up() {
		parent::set_up();
		self::clear_lock();
	}

	/**
	 * Leave no lock behind.
	 */
	public function tear_down() {
		self::clear_lock();
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
	 * No request upgrades on its own: visitor, editor or admin, front end, admin page, REST or cron.
	 * wp-cron.php is public, so cron is not a trusted context.
	 */
	public function test_no_request_context_upgrades() {
		foreach ( array( 0, 'editor', 'administrator' ) as $who ) {
			wp_set_current_user( 0 === $who ? 0 : self::factory()->user->create( array( 'role' => $who ) ) );
			update_option( Upgrader::OPTION, 1 );
			$up = new Upgrader();
			$up->register();
			do_action( 'init' );
			$this->assertSame( 1, (int) get_option( Upgrader::OPTION ), "No upgrade for {$who}" );
			// The only hooks are the notice and the button handler: nothing on init, admin_init, REST or cron.
			$this->assertSame( array( 'admin_notices', 'admin_post_' . Upgrader::ACTION ), $this->hooks_of( $up ) );
			remove_action( 'admin_notices', array( $up, 'notice' ) );
			remove_action( 'admin_post_' . Upgrader::ACTION, array( $up, 'handleButton' ) );
		}
	}

	/**
	 * Hooks that have a callback on an object.
	 *
	 * @param object $obj Object.
	 * @return string[]
	 */
	private function hooks_of( object $obj ): array {
		global $wp_filter;
		$out = array();
		foreach ( $wp_filter as $hook => $wp_hook ) {
			foreach ( $wp_hook->callbacks as $callbacks ) {
				foreach ( $callbacks as $cb ) {
					if ( is_array( $cb['function'] ) && $cb['function'][0] === $obj ) {
						$out[] = (string) $hook;
					}
				}
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Render the notice.
	 *
	 * @param array<string,string> $get Query args.
	 * @return string
	 */
	private function notice_html( array $get = array() ): string {
		$_GET = $get;
		ob_start();
		( new Upgrader() )->notice();
		$_GET = array();
		return (string) ob_get_clean();
	}

	/**
	 * Admins see the update button while the schema is behind; others and current sites see nothing.
	 */
	public function test_notice() {
		update_option( Upgrader::OPTION, 1 );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( '', $this->notice_html() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$html = $this->notice_html();
		$this->assertStringContainsString( 'admin-post.php', $html );
		$this->assertStringContainsString( 'value="' . Upgrader::ACTION . '"', $html );
		$this->assertStringContainsString( '_wpnonce', $html );
		update_option( Upgrader::OPTION, Upgrader::SCHEMA_VERSION );
		$this->assertSame( '', $this->notice_html() );
		$this->assertStringContainsString( 'up to date', $this->notice_html( array( 'nf_upgrade' => 'done' ) ) );
	}

	/**
	 * The button needs activate_plugins.
	 */
	public function test_button_requires_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		update_option( Upgrader::OPTION, 1 );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Upgrader::ACTION );
		try {
			( new Upgrader() )->runFromAdmin();
			$this->fail( 'Expected wp_die' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 1, (int) get_option( Upgrader::OPTION ) );
		} finally {
			unset( $_REQUEST['_wpnonce'] );
		}
	}

	/**
	 * The button needs a valid nonce.
	 */
	public function test_button_requires_nonce() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Upgrader::OPTION, 1 );
		$_REQUEST['_wpnonce'] = 'bad';
		try {
			( new Upgrader() )->runFromAdmin();
			$this->fail( 'Expected wp_die' );
		} catch ( WPDieException $e ) {
			$this->assertSame( 1, (int) get_option( Upgrader::OPTION ) );
		} finally {
			unset( $_REQUEST['_wpnonce'] );
		}
	}

	/**
	 * An admin's click with a valid nonce upgrades.
	 */
	public function test_button_upgrades() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Upgrader::OPTION, 1 );
		$_REQUEST['_wpnonce'] = wp_create_nonce( Upgrader::ACTION );
		$result               = ( new Upgrader() )->runFromAdmin();
		unset( $_REQUEST['_wpnonce'] );
		$this->assertSame( 'done', $result );
		$this->assertTrue( Upgrader::isCurrent() );
	}

	/**
	 * A failed migration is recorded, keeps the old version and releases the lock; it is shown in the notice.
	 */
	public function test_failure_is_recorded() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Upgrader::OPTION, 1 );
		$up = new Upgrader( array( new UpgraderTestFailingMigration() ) );
		$this->assertFalse( $up->maybe_upgrade() );
		$this->assertSame( 1, (int) get_option( Upgrader::OPTION ) );
		$this->assertFalse( get_option( Upgrader::LOCK ) );
		$failed = get_option( Upgrader::FAILED );
		$this->assertSame( Upgrader::SCHEMA_VERSION, $failed['version'] );
		$this->assertSame( 'migration broke', $failed['message'] );
		$this->assertStringContainsString( 'migration broke', $this->notice_html() );
		$this->assertTrue( ( new Upgrader() )->maybe_upgrade() );
		$this->assertFalse( get_option( Upgrader::FAILED ), 'A successful run clears the failure' );
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
