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
	 * Field group source (injectable for tests).
	 *
	 * @var AcfSource
	 */
	private AcfSource $source;

	/**
	 * Constructor.
	 *
	 * @param AcfSource|null $source Field group source; defaults to ACF's own API.
	 */
	public function __construct( ?AcfSource $source = null ) {
		$this->source = $source ?? new AcfSource();
	}

	/**
	 * Hook the triggers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'nf/upgraded', array( $this, 'onUpgraded' ) );
		add_action( 'nf/port/finished', array( $this, 'onPortFinished' ) );
		add_action( 'acf/update_field_group', array( $this, 'onGroupSaved' ) );
	}

	/**
	 * Whether the current request may write slots (trusted contexts only).
	 *
	 * @return bool
	 */
	public static function trusted(): bool {
		// wp-cron.php is public, so cron alone is never trusted; WP-CLI (even running cron events) is.
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
		// `nf/upgraded` fires only from the manual upgrade entry points (activation, admin button, `wp nf upgrade`).
		if ( $this->source->available() ) {
			$this->syncGroups( $this->source->groups() );
		}
	}

	/**
	 * After `wp nf port run`: assign slots for the ported post type (WP-CLI only, so trusted).
	 *
	 * @param mixed $post_type Ported post type.
	 * @return void
	 */
	public function onPortFinished( $post_type = '' ): void {
		if ( ! self::trusted() ) {
			return;
		}
		if ( ! $this->source->available() ) {
			return;
		}
		$post_type = sanitize_key( (string) $post_type );
		if ( '' === $post_type ) {
			return;
		}
		$groups = array();
		foreach ( $this->source->groups() as $group ) {
			$fields = SlotAssigner::fieldsByPostType( array( $group ) );
			if ( isset( $fields[ $post_type ] ) ) {
				$groups[] = $group;
			}
		}
		$this->syncGroups( $groups );
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
			// No NEEDS_RESYNC here: Bridge stores a structured value in it, and nf/upgraded re-runs this sync anyway.
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
