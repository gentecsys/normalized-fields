<?php
/**
 * Slot sync triggers (WP 3.0e).
 *
 * @package NF
 */

use NF\Pivot\SlotSync;

/**
 * Only trusted contexts sync; anonymous requests write nothing.
 */
class SlotSyncTest extends WP_UnitTestCase {

	/**
	 * Clean state.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		update_option( NF\Install\Upgrader::OPTION, NF\Install\Upgrader::SCHEMA_VERSION );
	}

	/**
	 * A visitor is never trusted.
	 */
	public function test_anonymous_is_not_trusted(): void {
		wp_set_current_user( 0 );
		$this->assertFalse( SlotSync::trusted() );
	}

	/**
	 * A non-admin screen is not trusted even for an administrator (front end).
	 */
	public function test_front_end_admin_user_is_not_trusted(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'front' );
		$this->assertFalse( SlotSync::trusted() );
	}

	/**
	 * An administrator in the admin is trusted.
	 */
	public function test_admin_screen_is_trusted(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
		$this->assertTrue( SlotSync::trusted() );
	}

	/**
	 * An untrusted group save queues nothing and hooks no shutdown callback.
	 */
	public function test_untrusted_group_save_queues_nothing(): void {
		wp_set_current_user( 0 );
		$sync = new SlotSync();
		$sync->onGroupSaved( array( 'key' => 'group_t30e' ) );
		$this->assertFalse( has_action( 'shutdown', array( $sync, 'flush' ) ) );
	}

	/**
	 * A schema that is behind skips the sync and flags a resync.
	 */
	public function test_schema_behind_skips_and_flags(): void {
		update_option( NF\Install\Upgrader::OPTION, '0' );
		delete_option( NF\Install\Upgrader::NEEDS_RESYNC );
		$this->assertSame( array(), ( new SlotSync() )->syncGroups( array() ) );
		$this->assertNotEmpty( get_option( NF\Install\Upgrader::NEEDS_RESYNC ) );
	}
}
