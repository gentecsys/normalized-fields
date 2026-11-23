<?php
/**
 * Count cache table fallback and its cleanup job (WP 3.4c).
 *
 * @package NF
 */

use NF\Core\Jobs\CountCacheCleanupJob;
use NF\Query\CountTable;

/**
 * The table tier is version-checked, never written by anonymous requests, and cleaned by the job.
 */
class CountTableTest extends WP_UnitTestCase {

	/**
	 * Table under test.
	 *
	 * @var CountTable
	 */
	private CountTable $table;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		update_option( NF\Install\Upgrader::OPTION, NF\Install\Upgrader::SCHEMA_VERSION );
		delete_option( CountTable::CLEANUP_OPTION );
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_count_cache' ) );
		$this->table = new CountTable( $db );
	}

	/**
	 * Number of rows in the table.
	 *
	 * @return int
	 */
	private function rows(): int {
		$db   = nf_db();
		$rows = $db->select( 'SELECT COUNT(*) AS c FROM ' . $db->table( 'nf_count_cache' ) );
		return (int) $rows[0]['c'];
	}

	/**
	 * A logged-in write is read back at the same version only.
	 */
	public function test_hit_requires_same_version(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->table->put( '9:3:abc', 9, 3, 42 );
		$this->assertSame( 42, $this->table->get( '9:3:abc', 9, 3 ) );
		$this->assertNull( $this->table->get( '9:3:abc', 9, 4 ), 'A newer write_version is a miss.' );
	}

	/**
	 * A second put with the same key replaces the row and does not throw.
	 */
	public function test_repeated_put_replaces_row(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->table->put( '9:3:abc', 9, 3, 42 );
		$this->table->put( '9:3:abc', 9, 3, 43 );
		$this->assertSame( 1, $this->rows() );
		$this->assertSame( 43, $this->table->get( '9:3:abc', 9, 3 ) );
	}

	/**
	 * Anonymous requests read the table but never write to it.
	 */
	public function test_anonymous_never_writes(): void {
		wp_set_current_user( 0 );
		$this->table->put( '9:3:abc', 9, 3, 42 );
		$this->assertSame( 0, $this->rows() );
		$this->assertFalse( get_option( CountTable::CLEANUP_OPTION ) );
	}

	/**
	 * Nothing is read or written while the schema is behind.
	 */
	public function test_schema_behind_skips(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		update_option( NF\Install\Upgrader::OPTION, NF\Install\Upgrader::SCHEMA_VERSION - 1 );
		$this->table->put( '9:3:abc', 9, 3, 42 );
		$this->assertSame( 0, $this->rows() );
		$this->assertNull( $this->table->get( '9:3:abc', 9, 3 ) );
	}

	/**
	 * The cleanup job removes expired rows and rows older than the set's write_version.
	 */
	public function test_cleanup_job_deletes_stale_rows(): void {
		$db = nf_db();
		$t  = $db->table( 'nf_count_cache' );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot_sets' ) . ' WHERE pivot_id = ?', array( 901 ) );
		$db->execute( 'INSERT INTO ' . $db->table( 'nf_pivot_sets' ) . ' (pivot_id, object_type, subtype, write_version) VALUES (?, ?, ?, ?)', array( 901, 1, 'nf_ct', 5 ) );
		$ok  = gmdate( 'Y-m-d H:i:s', time() + 3600 );
		$old = gmdate( 'Y-m-d H:i:s', time() - 3600 );
		$ins = "INSERT INTO {$t} (cache_key, pivot_id, version, n, expires) VALUES (?, ?, ?, ?, ?)";
		$db->execute( $ins, array( sha1( 'a' ), 901, 5, 1, $ok ) );
		$db->execute( $ins, array( sha1( 'b' ), 901, 4, 1, $ok ) );
		$db->execute( $ins, array( sha1( 'c' ), 901, 5, 1, $old ) );
		$job   = new CountCacheCleanupJob( $db );
		$state = $job->step( $job->init( array() ) );
		$this->assertTrue( $state['done'] );
		$this->assertSame( 1, $this->rows() );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot_sets' ) . ' WHERE pivot_id = ?', array( 901 ) );
	}
}
