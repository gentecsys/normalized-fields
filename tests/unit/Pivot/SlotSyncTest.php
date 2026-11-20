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
	 * A synthetic group.
	 *
	 * @return array<string,mixed>
	 */
	private function group(): array {
		return array(
			'key'      => 'group_t30e',
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'nf_sync_t30e',
					),
				),
			),
			'fields'   => array(
				array(
					'key'   => 'field_t30e_n',
					'name'  => 'n',
					'label' => 'N',
					'type'  => 'number',
				),
			),
		);
	}

	/**
	 * Syncing assigns the slot and queues one rebuild; a second sync is a no-op.
	 */
	public function test_sync_assigns_and_is_idempotent(): void {
		$sync  = new SlotSync();
		$first = $sync->syncGroups( array( $this->group() ) );
		$this->assertSame( array( 'nf_sync_t30e' => array( 'n1' ) ), $first );
		$this->assertSame( array(), $sync->syncGroups( array( $this->group() ) ) );
	}

	/**
	 * A trusted group save hooks shutdown; an untrusted flush does nothing.
	 */
	public function test_trusted_save_hooks_shutdown(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
		$sync = new SlotSync();
		$sync->onGroupSaved( $this->group() );
		$this->assertNotFalse( has_action( 'shutdown', array( $sync, 'flush' ) ) );
	}

	/**
	 * Schema behind: nothing is written.
	 */
	public function test_schema_behind_with_group_writes_nothing(): void {
		update_option( NF\Install\Upgrader::OPTION, '0' );
		$this->assertSame( array(), ( new SlotSync() )->syncGroups( array( $this->group() ) ) );
	}

	/**
	 * The port-finished hook is registered.
	 */
	public function test_port_finished_hook_registered(): void {
		$sync = new SlotSync();
		$sync->register();
		$this->assertNotFalse( has_action( 'nf/port/finished', array( $sync, 'onPortFinished' ) ) );
	}

	/**
	 * A source that serves one synthetic group.
	 *
	 * @param array<string,mixed> $group Group.
	 * @return NF\Migration\AcfSource
	 */
	private function source( array $group ): NF\Migration\AcfSource {
		return new class( $group ) extends NF\Migration\AcfSource {
			/**
			 * Constructor.
			 *
			 * @param array<string,mixed> $group Group.
			 */
			public function __construct( private array $group ) {}

			/**
			 * Always available.
			 *
			 * @return bool
			 */
			public function available(): bool {
				return true;
			}

			/**
			 * The group.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function groups(): array {
				return array( $this->group );
			}
		};
	}

	/**
	 * Count skip actions (the schema is behind, so a reached sync fires one).
	 *
	 * @return callable Returns the count.
	 */
	private function skipCounter(): callable {
		$fired = 0;
		add_action(
			'nf/pivot/slots_skipped',
			static function () use ( &$fired ): void {
				++$fired;
			}
		);
		return static function () use ( &$fired ): int {
			return $fired;
		};
	}

	/**
	 * An untrusted request never reaches the sync.
	 */
	public function test_port_finished_untrusted_does_not_sync(): void {
		update_option( NF\Install\Upgrader::OPTION, '0' );
		wp_set_current_user( 0 );
		$count = $this->skipCounter();
		( new SlotSync( $this->source( $this->group() ) ) )->onPortFinished( 'nf_sync_t30e' );
		$this->assertSame( 0, $count() );
	}

	/**
	 * A trusted request reaches the sync for the ported post type.
	 */
	public function test_port_finished_trusted_syncs(): void {
		update_option( NF\Install\Upgrader::OPTION, '0' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
		$count = $this->skipCounter();
		( new SlotSync( $this->source( $this->group() ) ) )->onPortFinished( 'nf_sync_t30e' );
		$this->assertSame( 1, $count() );
	}

	/**
	 * An empty post type syncs nothing.
	 */
	public function test_port_finished_empty_post_type_returns_early(): void {
		update_option( NF\Install\Upgrader::OPTION, '0' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'dashboard' );
		$count = $this->skipCounter();
		$sync  = new SlotSync( $this->source( $this->group() ) );
		$sync->onPortFinished( '' );
		$this->assertSame( 0, $count() );
	}
}
