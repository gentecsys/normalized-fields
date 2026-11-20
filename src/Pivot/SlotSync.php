<?php
/**
 * Slot sync triggers (WP 3.0e).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Pivot;

use NF\Install\Upgrader;
use NF\Migration\AcfSource;
use NF\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Runs slot assignment from trusted contexts only: a finished `wp nf upgrade` / activation upgrade, and an ACF
 * field group save by a user who can manage options in the admin. Never from a visitor, REST or cron request.
 */
final class SlotSync {

	/**
	 * Field groups saved in this request, keyed by group key.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $pending = array();

	/**
	 * Hook the triggers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'nf/upgraded', array( $this, 'onUpgraded' ) );
		add_action( 'acf/update_field_group', array( $this, 'onGroupSaved' ) );
	}

	/**
	 * Whether the current request may write slots (trusted contexts only).
	 *
	 * @return bool
	 */
	public static function trusted(): bool {
		if ( wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}
		return is_admin() && is_user_logged_in() && current_user_can( 'manage_options' );
	}

	/**
	 * After a schema upgrade: sync every field group (the upgrade itself only runs from trusted entry points).
	 *
	 * @return void
	 */
	public function onUpgraded(): void {
		$source = new AcfSource();
		if ( $source->available() ) {
			$this->syncGroups( $source->groups() );
		}
	}

	/**
	 * Remember a saved field group; sync at shutdown, after ACF has saved its fields.
	 *
	 * @param mixed $group ACF field group array.
	 * @return void
	 */
	public function onGroupSaved( $group ): void {
		if ( ! is_array( $group ) || ! self::trusted() ) {
			return;
		}
		if ( array() === $this->pending ) {
			add_action( 'shutdown', array( $this, 'flush' ) );
		}
		$this->pending[ (string) ( $group['key'] ?? count( $this->pending ) ) ] = $group;
	}

	/**
	 * Sync the groups saved in this request.
	 *
	 * @return void
	 */
	public function flush(): void {
		$groups        = $this->pending;
		$this->pending = array();
		if ( array() === $groups || ! self::trusted() || ! function_exists( 'acf_get_fields' ) ) {
			return;
		}
		$full = array();
		foreach ( $groups as $group ) {
			$group['fields'] = array_values( array_filter( (array) acf_get_fields( $group ), 'is_array' ) );
			$full[]          = $group;
		}
		$this->syncGroups( $full );
	}

	/**
	 * Assign slots for the post types the groups apply to, and queue a rebuild for sets that got new slots.
	 *
	 * @param array<int,array<string,mixed>> $groups Groups with their fields.
	 * @return array<string,string[]> Post type => slots assigned.
	 */
	public function syncGroups( array $groups ): array {
		if ( ! Upgrader::isCurrent() ) {
			update_option( Upgrader::NEEDS_RESYNC, 1, false );
			do_action( 'nf/pivot/slots_skipped', '' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			return array();
		}
		$assign = new SlotAssigner( new Pivot( Plugin::db(), Plugin::fields(), Plugin::store() ), Plugin::fields() );
		$out    = array();
		foreach ( SlotAssigner::fieldsByPostType( $groups ) as $post_type => $fields ) {
			try {
				$res = $assign->assign( (string) $post_type, $fields );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}
			if ( array() === $res['assigned'] || null === $res['pivot_id'] ) {
				continue;
			}
			$out[ (string) $post_type ] = array_values( $res['assigned'] );
			Plugin::jobs()->start( 'pivot_rebuild', array( 'pivot_id' => $res['pivot_id'] ) );
		}
		return $out;
	}
}
