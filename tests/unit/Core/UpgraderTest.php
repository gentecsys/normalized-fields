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
		$log = new ArrayObject();
		$mk  = static fn( int $v ) => new UpgraderTestMigration( $v, $log );
		update_option( Upgrader::OPTION, 0 );
		$up = new Upgrader( array( $mk( 2 ), $mk( 1 ), $mk( 99 ) ) );
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
