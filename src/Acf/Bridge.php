<?php
/**
 * Bridge mode: mirror ACF writes into NF tables while ACF is active.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Acf;

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
		add_filter( 'acf/update_value', array( $this, 'onUpdate' ), 99, 3 );
		add_action( 'acf/delete_value', array( $this, 'onDelete' ), 10, 3 );
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
		$nested = $this->seriesDepth > 0;
		++$this->seriesDepth;
		$target = self::decode( $post_id );
		if ( $nested || null === $target || empty( $field['key'] ) ) {
			return $value;
		}
		if ( $this->isSubField( $field ) ) {
			$this->queue( $post_id, $field );
			return $value;
		}
		try {
			$def  = $this->ensure( $field );
			$rows = $this->normalizeRows( $def, is_array( $value ) ? $value : array() );
			$this->store->setRows( $target[0], $target[1], (string) $field['key'], $rows );
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
		if ( $this->seriesDepth > 0 || 'repeater' === ( $field['type'] ?? '' ) || empty( $field['key'] ) ) {
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
			$def = $this->ensure( $field );
			$this->store->set( $target[0], $target[1], $def->key, TypeMap::normalize( $value, $def->table, $def->multi ) );
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
	 * A logged-out GET or HEAD never writes (D-18).
	 *
	 * @return void
	 */
	public function flush(): void {
		$queue         = $this->pending;
		$this->pending = array();
		if ( array() === $queue ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( ! is_user_logged_in() && in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
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
	 * Queue the top-level Series that owns a sub-field for a re-sync.
	 *
	 * @param mixed               $post_id ACF post ID.
	 * @param array<string,mixed> $field   Sub-field.
	 * @return void
	 */
	private function queue( mixed $post_id, array $field ): void {
		$top = $this->topSeries( $field );
		if ( null === $top || empty( $top['key'] ) ) {
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
	 * @param mixed $parent Parent reference.
	 * @return array<string,mixed>|null
	 */
	private function loadParent( mixed $parent ): ?array {
		if ( ! is_string( $parent ) && ! is_int( $parent ) ) {
			return null;
		}
		if ( function_exists( 'acf_get_field' ) ) {
			$loaded = acf_get_field( $parent );
			if ( is_array( $loaded ) && ! empty( $loaded['key'] ) ) {
				return $loaded;
			}
		}
		if ( is_string( $parent ) && str_starts_with( $parent, 'field_' ) ) {
			$known = $this->fields->byKey( $parent );
			if ( null !== $known ) {
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
	 */
	private function readRows( mixed $post_id, string $name, \NF\Storage\FieldDef $def ): array {
		$read  = $this->reader ?? static fn( $id, string $meta ) => function_exists( 'acf_get_metadata' ) ? acf_get_metadata( $id, $meta ) : null;
		$count = (int) $read( $post_id, $name );
		$rows  = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$row = array();
			foreach ( $this->fields->children( $def->id ) as $sub ) {
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
	 * Whether a field belongs to a Series. Sub-fields are not mirrored as top-level values; when saved
	 * on their own (update_sub_field(), update_field( 'outer_0_name' )) their top-level Series is queued
	 * and re-copied from ACF by flush().
	 *
	 * @param array<string,mixed> $field ACF field.
	 * @return bool
	 */
	private function isSubField( array $field ): bool {
		$parent = $field['parent'] ?? 0;
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
				$this->fields->register( (string) $sub['key'], (string) ( $sub['name'] ?? '' ), $sub_table, $sub_multi, $def->id );
			}
		}
		return $def;
	}
}
