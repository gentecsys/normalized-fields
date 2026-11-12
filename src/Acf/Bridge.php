<?php
/**
 * Bridge mode: mirror ACF writes into NF tables while ACF is active.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Acf;

use NF\Install\Upgrader;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to ACF's value hooks and writes the same data to the NF store (write-both).
 *
 * ACF keeps writing wp_postmeta, so nothing visible changes. A failure here never breaks an ACF save;
 * it is reported through the `nf/bridge/error` action.
 */
final class Bridge {

	/**
	 * Depth of the Series update in progress; sub-field writes are handled with their Series.
	 *
	 * @var int
	 */
	private int $seriesDepth = 0;

	/**
	 * Top-level Series to re-copy from ACF once its sub-fields were saved on their own.
	 *
	 * @var array<string,array{0:mixed,1:array<string,mixed>}>
	 */
	private array $pending = array();

	/**
	 * Constructor.
	 *
	 * @param Store         $store  Store.
	 * @param FieldMap      $fields Field map.
	 * @param \Closure|null $reader Reads a raw ACF meta value: fn( mixed $post_id, string $name ): mixed. Defaults to ACF's own.
	 */
	public function __construct( private Store $store, private FieldMap $fields, private ?\Closure $reader = null ) {}

	/**
	 * Add hooks. Series run first (priority 1) because ACF's own Series handler replaces the rows with a count.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'acf/save_post', array( $this, 'reset' ), 1 );
		add_action( 'acf/save_post', array( $this, 'flush' ), 99 );
		add_action( 'shutdown', array( $this, 'flush' ), 5 );
		add_filter( 'acf/update_value/type=repeater', array( $this, 'beforeSeries' ), 1, 3 );
		add_filter( 'acf/update_value/type=repeater', array( $this, 'afterSeries' ), 99, 1 );
		// Before ACF's group handler (priority 10), which saves each sub-field and passes '' on.
		add_filter( 'acf/update_value/type=group', array( $this, 'beforeGroup' ), 1, 3 );
		add_filter( 'acf/update_value/type=clone', array( $this, 'beforeGroup' ), 1, 3 );
		add_filter( 'acf/update_value', array( $this, 'onUpdate' ), 99, 3 );
		add_action( 'acf/delete_value', array( $this, 'onDelete' ), 10, 3 );
		add_action( 'nf/upgraded', array( $this, 'resyncAfterUpgrade' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::add_command( 'nf resync', array( $this, 'cliResync' ) );
		}
	}

	/**
	 * Reset the Series depth at the start of a save, in case an earlier filter threw.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->seriesDepth = 0;
	}

	/**
	 * Decode an ACF $post_id into an object type and ID.
	 *
	 * @param mixed $post_id ACF post ID: 12, 'user_3', 'term_5', 'comment_7', 'option'.
	 * @return array{0:ObjectType,1:int}|null Null when unsupported.
	 */
	public static function decode( mixed $post_id ): ?array {
		if ( is_int( $post_id ) || ( is_string( $post_id ) && ctype_digit( $post_id ) ) ) {
			return array( ObjectType::Post, (int) $post_id );
		}
		if ( ! is_string( $post_id ) ) {
			return null;
		}
		if ( 'option' === $post_id || 'options' === $post_id ) {
			return array( ObjectType::Option, 0 );
		}
		if ( preg_match( '/^(user|term|comment)_(\d+)$/', $post_id, $m ) ) {
			$type = array(
				'user'    => ObjectType::User,
				'term'    => ObjectType::Term,
				'comment' => ObjectType::Comment,
			)[ $m[1] ];
			return array( $type, (int) $m[2] );
		}
		return null;
	}

	/**
	 * Filter: a Series is about to be saved. Writes its rows and lets ACF continue unchanged.
	 *
	 * @param mixed               $value   Raw rows.
	 * @param mixed               $post_id ACF post ID.
	 * @param array<string,mixed> $field   Series field.
	 * @return mixed
	 */
	public function beforeSeries( mixed $value, mixed $post_id, array $field ): mixed {
		$field  = self::scoped( $field );
		$nested = $this->seriesDepth > 0;
		++$this->seriesDepth;
		if ( ! $nested && $this->schemaBehind( $post_id, $field ) ) {
			return $value;
		}
		$target = self::decode( $post_id );
		if ( $nested || null === $target || empty( $field['key'] ) ) {
			return $value;
		}
		if ( $this->isSubField( $field ) ) {
			$this->queue( $post_id, $field );
			return $value;
		}
		if ( ! is_array( $value ) ) {
			// add_row()/delete_row() pass a row count: re-copy the rows from ACF instead of writing none.
			$this->queueSeries( $post_id, $field );
			return $value;
		}
		try {
			$def  = $this->ensure( $field );
			$rows = $this->normalizeRows( $def, $value );
			$this->store->setRows( $target[0], $target[1], (string) $field['key'], $rows );
		} catch ( \Throwable $e ) {
			do_action( 'nf/bridge/error', $e, $field, $post_id ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		}
		return $value;
	}

	/**
	 * Filter: mirror a top-level Group (with nested Groups) from the array ACF is about to save.
	 *
	 * ACF's own group handler runs at priority 10: it saves each sub-field and returns '', so by
	 * onUpdate (priority 99) the value is gone, and the sub-field saves are skipped as sub-fields.
	 * Groups inside a Series are stored with the Series rows.
	 *
	 * @param mixed               $value   Group value, keyed by sub-field key or name.
	 * @param mixed               $post_id ACF post ID.
	 * @param array<string,mixed> $field   Group field.
	 * @return mixed Unchanged value.
	 */
	public function beforeGroup( mixed $value, mixed $post_id, array $field ): mixed {
		$field = self::scoped( $field );
		if ( 0 === $this->seriesDepth && $this->schemaBehind( $post_id, $field ) ) {
			return $value;
		}
		if ( $this->seriesDepth > 0 || empty( $field['key'] ) || ( 'clone' === ( $field['type'] ?? '' ) && ! TypeMap::isGroupClone( $field ) ) || $this->isSubField( $field ) ) {
			return $value;
		}
		$target = self::decode( $post_id );
		if ( null === $target ) {
			return $value;
		}
		try {
			$def = $this->ensure( $field );
			$this->store->set( $target[0], $target[1], $def->key, TypeMap::normalizeComposite( $field, $value ) );
		} catch ( \Throwable $e ) {
			do_action( 'nf/bridge/error', $e, $field, $post_id ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		}
		return $value;
	}

	/**
	 * Filter: the Series save finished.
	 *
	 * @param mixed $value ACF's return value.
	 * @return mixed
	 */
	public function afterSeries( mixed $value ): mixed {
		$this->seriesDepth = max( 0, $this->seriesDepth - 1 );
		return $value;
	}

	/**
	 * Filter: a top-level field value is being saved. Mirrors it and returns the value untouched.
	 *
	 * @param mixed               $value   Value.
	 * @param mixed               $post_id ACF post ID.
	 * @param array<string,mixed> $field   Field.
	 * @return mixed
	 */
	public function onUpdate( mixed $value, mixed $post_id, array $field ): mixed {
		$field = self::scoped( $field );
		if ( 0 === $this->seriesDepth && $this->schemaBehind( $post_id, $field ) ) {
			return $value;
		}
		if ( $this->seriesDepth > 0 || in_array( $field['type'] ?? '', array( 'repeater', 'group', 'clone' ), true ) || empty( $field['key'] ) ) {
			// Series, Groups and group-display clones were mirrored at priority 1; a seamless clone's fields save themselves.
			return $value;
		}
		if ( $this->isSubField( $field ) ) {
			$this->queue( $post_id, $field );
			return $value;
		}
		$target = self::decode( $post_id );
		if ( null === $target ) {
			return $value;
		}
		try {
			$def    = $this->ensure( $field );
			$normal = DataTable::None === $def->table ? TypeMap::normalizeComposite( $field, $value ) : TypeMap::normalize( $value, $def->table, $def->multi );
			$this->store->set( $target[0], $target[1], $def->key, $normal );
		} catch ( \Throwable $e ) {
			do_action( 'nf/bridge/error', $e, $field, $post_id ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		}
		return $value;
	}

	/**
	 * Action: ACF deleted a value.
	 *
	 * @param mixed  $post_id ACF post ID.
	 * @param string $name    Field name.
	 * @param mixed  $field   Field.
	 * @return void
	 */
	public function onDelete( mixed $post_id, string $name, mixed $field = null ): void {
		$target = self::decode( $post_id );
		if ( null === $target || ! is_array( $field ) || empty( $field['key'] ) || $this->seriesDepth > 0 ) {
			return;
		}
		$field = self::scoped( $field );
		if ( $this->schemaBehind( $post_id, $field ) ) {
			return;
		}
		if ( $this->isSubField( $field ) ) {
			$this->queue( $post_id, $field );
			return;
		}
		try {
			$def = $this->fields->byKey( (string) $field['key'] );
			if ( null !== $def && 0 === $def->parentId ) {
				$this->store->delete( $target[0], $target[1], $def->key );
			}
		} catch ( \Throwable $e ) {
			do_action( 'nf/bridge/error', $e, $field, $post_id ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		}
	}

	/**
	 * Re-copy every queued top-level Series from ACF into NF. Runs after ACF wrote its meta (late
	 * `acf/save_post`, or `shutdown` for calls outside a save). One re-sync per Series per request.
	 * A logged-out GET or HEAD never writes (D-18); WP-CLI and WP-Cron are server-side and do.
	 *
	 * @return void
	 */
	public function flush(): void {
		// Cleared first: a failed or skipped re-sync is not retried within the request.
		$queue         = $this->pending;
		$this->pending = array();
		if ( array() === $queue ) {
			return;
		}
		if ( $this->isAnonymousRead() ) {
			return;
		}
		// Tables may be missing until the upgrade runs (admin button or wp nf upgrade); never create them here.
		if ( ! Upgrader::isCurrent() ) {
			foreach ( $queue as [ $post_id ] ) {
				$this->markResync( $post_id );
			}
			do_action( 'nf/bridge/schema_behind', $queue ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			return;
		}
		foreach ( $queue as [ $post_id, $series ] ) {
			$target = self::decode( $post_id );
			if ( null === $target ) {
				continue;
			}
			try {
				$def  = $this->ensure( $series );
				$name = '' !== $def->name ? $def->name : (string) ( $series['name'] ?? '' );
				$rows = $this->readRows( $post_id, $name, $def );
				$this->store->setRows( $target[0], $target[1], $def->key, $rows );
			} catch ( \Throwable $e ) {
				do_action( 'nf/bridge/error', $e, $series, $post_id ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			}
		}
	}

	/**
	 * Whether NF's schema is behind this code. Then the write is skipped (no DDL, no NF table write),
	 * a re-sync is recorded and `nf/bridge/schema_behind` fires.
	 *
	 * @param mixed               $post_id ACF post ID.
	 * @param array<string,mixed> $field   Field.
	 * @return bool
	 */
	private function schemaBehind( mixed $post_id, array $field ): bool {
		if ( Upgrader::isCurrent() ) {
			return false;
		}
		$this->markResync( $post_id );
		do_action( 'nf/bridge/schema_behind', array( array( $post_id, $field ) ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		return true;
	}

	/**
	 * Most objects listed for a re-sync; past it the list stops and a full re-copy is asked for.
	 */
	public const RESYNC_LIST_MAX = 5000;

	/**
	 * Objects re-synced per call when the upgrade ran from wp-admin; `wp nf resync` does the rest.
	 */
	public const RESYNC_ADMIN_BATCH = 200;

	/**
	 * Record that ACF saved values for an object that NF did not copy, so the object is re-synced after the upgrade.
	 *
	 * @param mixed $post_id ACF post ID.
	 * @return void
	 */
	private function markResync( mixed $post_id ): void {
		$id   = is_scalar( $post_id ) ? (string) $post_id : '';
		$flag = get_option( Upgrader::NEEDS_RESYNC );
		if ( false === $flag ) {
			$flag = array(
				'since'    => time(),
				'objects'  => array(),
				'overflow' => false,
			);
		} elseif ( ! is_array( $flag ) ) {
			return; // Already asking for a full re-copy.
		}
		if ( '' === $id || null === self::decode( $post_id ) ) {
			return;
		}
		$objects = (array) ( $flag['objects'] ?? array() );
		if ( in_array( $id, $objects, true ) || ! empty( $flag['overflow'] ) ) {
			return;
		}
		if ( count( $objects ) >= self::RESYNC_LIST_MAX ) {
			$flag['overflow'] = true;
		} else {
			$objects[]       = $id;
			$flag['objects'] = $objects;
		}
		update_option( Upgrader::NEEDS_RESYNC, $flag, false );
	}

	/**
	 * Action `nf/upgraded`: re-copy, from ACF, the objects whose writes were skipped while the schema was behind.
	 *
	 * Only while ACF is active: a site that has moved to NF no longer writes through ACF, so there is nothing to
	 * copy, and the flag is dropped. From wp-admin a batch of RESYNC_ADMIN_BATCH objects runs; the notice asks
	 * for `wp nf resync` when more remain.
	 *
	 * @return void
	 */
	public function resyncAfterUpgrade(): void {
		$this->resync( ( defined( 'WP_CLI' ) && WP_CLI ) ? 0 : self::RESYNC_ADMIN_BATCH );
	}

	/**
	 * Re-sync the objects NF skipped while its schema was behind, from ACF.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Also needed when NF could not list every object; re-copies every post that has ACF values.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nf resync
	 *
	 * @param string[]             $args       Positional arguments.
	 * @param array<string,string> $assoc_args Options.
	 * @return void
	 */
	public function cliResync( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );
		if ( ! Upgrader::isCurrent() ) {
			\WP_CLI::error( 'The NF schema is behind; run wp nf upgrade first.' );
		}
		if ( ! empty( $assoc_args['all'] ) ) {
			$this->listAllPosts();
		}
		$done = $this->resync( 0 );
		\WP_CLI::success( sprintf( 'Re-synced %d object(s) from ACF.', $done ) );
	}

	/**
	 * Re-sync listed objects.
	 *
	 * @param int $limit Most objects to handle; 0 for all.
	 * @return int Objects re-synced.
	 */
	public function resync( int $limit ): int {
		$flag = get_option( Upgrader::NEEDS_RESYNC );
		if ( false === $flag || ! Upgrader::isCurrent() ) {
			return 0;
		}
		if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_value' ) ) {
			// ACF is gone (the site moved to NF): nothing left to copy from.
			delete_option( Upgrader::NEEDS_RESYNC );
			return 0;
		}
		$objects = is_array( $flag ) ? array_values( (array) ( $flag['objects'] ?? array() ) ) : array();
		$batch   = $limit > 0 ? array_slice( $objects, 0, $limit ) : $objects;
		$fields  = $this->acfFields();
		$covered = $this->coveredKeys( $fields );
		$kept    = array();
		foreach ( $batch as $id ) {
			if ( ! $this->resyncObject( ctype_digit( (string) $id ) ? (int) $id : (string) $id, $fields, $covered ) ) {
				// Some of its values belong to fields this request doesn't load; keep it for wp nf resync.
				$kept[] = $id;
			}
		}
		$this->flush();
		$rest = array_merge( $kept, array_slice( $objects, count( $batch ) ) );
		if ( array() === $rest && is_array( $flag ) && empty( $flag['overflow'] ) ) {
			delete_option( Upgrader::NEEDS_RESYNC );
		} else {
			$flag            = is_array( $flag ) ? $flag : array(
				'since'    => time(),
				'overflow' => true,
			);
			$flag['objects'] = $rest;
			update_option( Upgrader::NEEDS_RESYNC, $flag, false );
		}
		return count( $batch ) - count( $kept );
	}

	/**
	 * Every field key (and cloned source key) among the fields and their sub-fields.
	 *
	 * @param array<int,array<string,mixed>> $fields Fields.
	 * @return array<string,true>
	 */
	private function coveredKeys( array $fields ): array {
		$out = array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			foreach ( array( 'key', '__key', '_clone' ) as $k ) {
				if ( ! empty( $field[ $k ] ) && is_string( $field[ $k ] ) ) {
					$out[ $field[ $k ] ] = true;
				}
			}
			if ( ! empty( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				$out += $this->coveredKeys( $field['sub_fields'] );
			}
		}
		return $out;
	}

	/**
	 * List every post with ACF values for a re-sync (`wp nf resync --all`), and clear the overflow mark.
	 *
	 * @return void
	 */
	private function listAllPosts(): void {
		global $wpdb;
		$ids  = $wpdb->get_col( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_%' AND meta_value LIKE 'field\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$flag = array(
			'since'    => time(),
			'objects'  => array_map( 'strval', $ids ),
			'overflow' => false,
		);
		update_option( Upgrader::NEEDS_RESYNC, $flag, false );
	}

	/**
	 * Top-level ACF fields of every field group, as ACF resolves them (seamless clones expanded).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function acfFields(): array {
		$out = array();
		foreach ( (array) acf_get_field_groups() as $group ) {
			foreach ( (array) acf_get_fields( $group ) as $field ) {
				if ( is_array( $field ) && ! empty( $field['key'] ) && ! empty( $field['name'] ) ) {
					$out[] = $field;
				}
			}
		}
		return $out;
	}

	/**
	 * Copy one object's ACF values into NF again, as if each were saved now.
	 *
	 * A field is copied when ACF holds a value for it on this object (its `_name` reference points at the
	 * field's key, or its source key for a cloned field). A field NF knows but ACF has no value for is deleted.
	 *
	 * @param int|string                     $acf_id  ACF post ID.
	 * @param array<int,array<string,mixed>> $fields  Top-level ACF fields.
	 * @param array<string,true>             $covered Keys of those fields and their sub-fields.
	 * @return bool False when the object has ACF values for fields this request does not load.
	 */
	private function resyncObject( int|string $acf_id, array $fields, array $covered ): bool {
		$target = self::decode( $acf_id );
		if ( null === $target ) {
			return true;
		}
		foreach ( $fields as $field ) {
			$ref    = acf_get_metadata( $acf_id, (string) $field['name'], true );
			$source = (string) ( $field['__key'] ?? $field['key'] );
			if ( $ref !== $field['key'] && $ref !== $source ) {
				$known = $this->fields->byKey( (string) $field['key'] );
				if ( null !== $known && 0 === $known->parentId && ! $this->ownedByOther( $field, $fields ) ) {
					try {
						$this->store->delete( $target[0], $target[1], $known->key );
					} catch ( \Throwable $e ) {
						do_action( 'nf/bridge/error', $e, $field, $acf_id ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
					}
				}
				continue;
			}
			$value = acf_get_value( $acf_id, $field );
			switch ( $field['type'] ?? '' ) {
				case 'repeater':
					$this->beforeSeries( $value, $acf_id, $field );
					$this->afterSeries( $value );
					break;
				case 'group':
				case 'clone':
					$this->beforeGroup( $value, $acf_id, $field );
					break;
				default:
					$this->onUpdate( $value, $acf_id, $field );
			}
		}
		// Every value ACF holds for the object must belong to a field seen above.
		foreach ( array_keys( (array) acf_get_meta( $acf_id ) ) as $name ) {
			$ref = acf_get_metadata( $acf_id, (string) $name, true );
			if ( is_string( $ref ) && str_starts_with( $ref, 'field_' ) && ! isset( $covered[ $ref ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether another field with the same name holds this object's value (two groups can reuse a name).
	 *
	 * @param array<string,mixed>            $field  Field without a value here.
	 * @param array<int,array<string,mixed>> $fields All top-level fields.
	 * @return bool
	 */
	private function ownedByOther( array $field, array $fields ): bool {
		foreach ( $fields as $other ) {
			if ( $other['name'] === $field['name'] && $other['key'] !== $field['key'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether this request is a visitor's logged-out GET or HEAD, which must never write (D-18).
	 *
	 * WP-CLI sets REQUEST_METHOD to GET with no user, and WP-Cron runs as a logged-out GET when a
	 * system cron calls wp-cron.php (as on Pantheon). Both run code the site owner scheduled, not
	 * visitor input, so they are not anonymous reads.
	 *
	 * @return bool
	 */
	private function isAnonymousRead(): bool {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || wp_doing_cron() || is_user_logged_in() ) {
			return false;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		return in_array( $method, array( 'GET', 'HEAD' ), true );
	}

	/**
	 * Queue the top-level Series that owns a sub-field for a re-sync.
	 *
	 * @param mixed               $post_id ACF post ID.
	 * @param array<string,mixed> $field   Sub-field.
	 * @return void
	 */
	private function queue( mixed $post_id, array $field ): void {
		$this->queueSeries( $post_id, $this->topSeries( $field ) );
	}

	/**
	 * Queue a Series (only a repeater, never a Group or other parent) for a re-sync.
	 *
	 * @param mixed                    $post_id ACF post ID.
	 * @param array<string,mixed>|null $top     Top-level Series.
	 * @return void
	 */
	private function queueSeries( mixed $post_id, ?array $top ): void {
		if ( null === $top || empty( $top['key'] ) || 'repeater' !== ( $top['type'] ?? '' ) ) {
			return;
		}
		$id                                       = is_scalar( $post_id ) ? (string) $post_id : '';
		$this->pending[ $id . '|' . $top['key'] ] = array( $post_id, $top );
	}

	/**
	 * Walk `parent` up to the top-level Series.
	 *
	 * @param array<string,mixed> $field Sub-field.
	 * @return array<string,mixed>|null
	 */
	private function topSeries( array $field ): ?array {
		$current = $field;
		for ( $i = 0; $i < 10; $i++ ) {
			$parent = $this->loadParent( $current['parent'] ?? 0 );
			if ( null === $parent ) {
				return $i > 0 ? $current : null;
			}
			if ( ! $this->isSubField( $parent ) ) {
				return $parent;
			}
			$current = $parent;
		}
		return null;
	}

	/**
	 * Load a parent field by key or ACF field post ID.
	 *
	 * @param mixed $ref Parent reference.
	 * @return array<string,mixed>|null
	 */
	private function loadParent( mixed $ref ): ?array {
		if ( ! is_string( $ref ) && ! is_int( $ref ) ) {
			return null;
		}
		if ( function_exists( 'acf_get_field' ) ) {
			$loaded = acf_get_field( $ref );
			if ( is_array( $loaded ) && ! empty( $loaded['key'] ) ) {
				return $loaded;
			}
		}
		if ( is_string( $ref ) && str_starts_with( $ref, 'field_' ) ) {
			$known = $this->fields->byKey( $ref );
			if ( null !== $known && DataTable::Rows === $known->table ) {
				return array(
					'key'  => $known->key,
					'name' => $known->name,
					'type' => 'repeater',
				);
			}
		}
		return null;
	}

	/**
	 * Read a Series' raw rows from ACF's meta.
	 *
	 * @param mixed                $post_id ACF post ID.
	 * @param string               $name    Series name.
	 * @param \NF\Storage\FieldDef $def     Series definition.
	 * @return array<int,array<string,mixed>>
	 * @throws \RuntimeException When ACF holds no row count.
	 */
	private function readRows( mixed $post_id, string $name, \NF\Storage\FieldDef $def ): array {
		$read = $this->reader ?? static fn( $id, string $meta ) => function_exists( 'acf_get_metadata' ) ? acf_get_metadata( $id, $meta ) : null;
		$raw  = $read( $post_id, $name );
		if ( ! is_numeric( $raw ) ) {
			// Reader unavailable or no stored count: never replace NF rows with nothing.
			throw new \RuntimeException( 'ACF row count unavailable for ' . esc_html( $name ) );
		}
		$count = (int) $raw;
		$subs  = $this->fields->children( $def->id );
		$rows  = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$row = array();
			foreach ( $subs as $sub ) {
				$row[ $sub->name ] = $read( $post_id, $name . '_' . $i . '_' . $sub->name );
			}
			$rows[] = $row;
		}
		return $this->normalizeRows( $def, $rows );
	}

	/**
	 * Normalise raw Series rows (keyed by sub-field key or name) for the store.
	 *
	 * @param \NF\Storage\FieldDef $def  Series definition.
	 * @param array<mixed>         $value Raw rows.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalizeRows( \NF\Storage\FieldDef $def, array $value ): array {
		$subs = $this->fields->children( $def->id );
		$rows = array();
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out = array();
			foreach ( $subs as $sub ) {
				if ( array_key_exists( $sub->key, $row ) ) {
					$raw = $row[ $sub->key ];
				} elseif ( array_key_exists( $sub->name, $row ) ) {
					$raw = $row[ $sub->name ];
				} else {
					continue;
				}
				$out[ $sub->name ] = TypeMap::normalize( $raw, $sub->table, $sub->multi );
			}
			$rows[] = $out;
		}
		return $rows;
	}

	/**
	 * A field as NF identifies it: a field saved through a clone (`_clone` set) gets its clone-scoped key.
	 *
	 * @param array<string,mixed> $field ACF field.
	 * @return array<string,mixed>
	 */
	private static function scoped( array $field ): array {
		$clone = $field['_clone'] ?? '';
		return is_string( $clone ) && '' !== $clone ? TypeMap::scope( $field, $clone ) : $field;
	}

	/**
	 * Whether a field belongs to a Series. Sub-fields are not mirrored as top-level values; when saved
	 * on their own (update_sub_field(), update_field( 'outer_0_name' )) their top-level Series is queued
	 * and re-copied from ACF by flush().
	 *
	 * @param array<string,mixed> $field ACF field.
	 * @return bool
	 */
	private function isSubField( array $field ): bool {
		$parent = $field['parent'] ?? 0;
		if ( ! empty( $field['_clone'] ) ) {
			// `parent` of a cloned field is its source group; the owner is the clone field itself.
			$owner = $this->loadParent( $field['_clone'] );
			if ( null !== $owner && 'clone' === ( $owner['type'] ?? '' ) ) {
				// A group clone's fields are mirrored with the clone (beforeGroup); a seamless clone's fields
				// live at the clone's own level, so they are top-level unless the clone itself is a sub-field.
				return TypeMap::isGroupClone( $owner ) || $this->isSubField( $owner );
			}
		}
		if ( is_string( $parent ) && str_starts_with( $parent, 'field_' ) ) {
			return true;
		}
		if ( is_numeric( $parent ) && (int) $parent > 0 && 'acf-field' === get_post_type( (int) $parent ) ) {
			return true;
		}
		$known = $this->fields->byKey( (string) $field['key'] );
		return null !== $known && 0 !== $known->parentId;
	}

	/**
	 * Register a field (and a Series' sub-fields) in the field map.
	 *
	 * @param array<string,mixed> $field ACF field.
	 * @return \NF\Storage\FieldDef
	 */
	private function ensure( array $field ): \NF\Storage\FieldDef {
		[ $table, $multi ] = TypeMap::storage( $field );
		$def               = $this->fields->register( (string) $field['key'], (string) ( $field['name'] ?? '' ), $table, $multi );
		if ( DataTable::Rows === $table ) {
			foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub ) {
				if ( ! is_array( $sub ) || empty( $sub['key'] ) || 'repeater' === ( $sub['type'] ?? '' ) ) {
					continue;
				}
				[ $sub_table, $sub_multi ] = TypeMap::storage( $sub );
				if ( DataTable::None === $sub_table ) {
					// Link, Google Map and Group inside a Series are kept as JSON until Series rows recurse into children.
					$sub_table = DataTable::Json;
				}
				$this->fields->register( (string) $sub['key'], (string) ( $sub['name'] ?? '' ), $sub_table, $sub_multi, $def->id );
			}
		}
		$this->registerParts( $def, $field );
		foreach ( TypeMap::seamlessFields( $field ) as $cloned ) {
			$this->ensure( $cloned );
		}
		return $def;
	}

	/**
	 * Register the children of a group, link or Google Map field (recursively for nested groups).
	 *
	 * @param \NF\Storage\FieldDef $owner Parent definition.
	 * @param array<string,mixed>  $field ACF field.
	 * @return void
	 */
	private function registerParts( \NF\Storage\FieldDef $owner, array $field ): void {
		if ( DataTable::None !== $owner->table ) {
			return;
		}
		foreach ( TypeMap::composite( $field ) ?? array() as $part ) {
			$child = $this->fields->register( $part['key'], $part['name'], $part['table'], $part['multi'], $owner->id );
			if ( is_array( $part['field'] ) ) {
				$this->registerParts( $child, $part['field'] );
			}
		}
	}
}
