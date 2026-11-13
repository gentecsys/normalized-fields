<?php
/**
 * Layer 1 compatibility: answer the core meta API for NF-managed keys.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Acf;

use NF\Backend\BackendInterface;
use NF\Install\Upgrader;
use NF\Storage\DataTable;
use NF\Storage\ObjectType;
use NF\Storage\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Routes get/add/update/delete_{type}_metadata for NF-managed field names to the NF store.
 *
 * Only active in standalone mode (ACF absent), where NF owns the values; with ACF active the Bridge
 * mirrors ACF's own postmeta writes instead. Top-level scalar and multi-valued fields are covered.
 * Series and Group raw meta (`rows_0_name`, `group_sub`) is not intercepted yet: it falls through to core.
 *
 * While the schema is behind, writes and deletes of NF-managed names are refused (owner, 2026-11-13): they
 * must not land in wp_postmeta, where NF would never see them. Reads fall through to core until the upgrade.
 */
final class MetaInterceptor {

	/**
	 * Meta types handled, mapped to NF object types.
	 *
	 * @var array<string,ObjectType>
	 */
	private const TYPES = array(
		'post'    => ObjectType::Post,
		'term'    => ObjectType::Term,
		'user'    => ObjectType::User,
		'comment' => ObjectType::Comment,
	);

	/**
	 * Top-level fields by name for the current table prefix (null = not a managed name).
	 *
	 * @var array<string,array{key:string,table:DataTable,multi:bool}|null>
	 */
	private array $names = array();

	/**
	 * Managed-name lookups made while the schema is behind.
	 *
	 * @var array<string,bool>
	 */
	private array $managed = array();

	/**
	 * Re-entrancy guard for the full-array read.
	 *
	 * @var bool
	 */
	private bool $reading = false;

	/**
	 * Constructor.
	 *
	 * @param Store            $store  Store.
	 * @param BackendInterface $db     Backend.
	 * @param \Closure|null    $active Whether interception is on: fn(): bool. Defaults to "ACF is not loaded".
	 */
	public function __construct( private Store $store, private BackendInterface $db, private ?\Closure $active = null ) {}

	/**
	 * Add the four filters for every supported meta type.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( array_keys( self::TYPES ) as $type ) {
			add_filter( "get_{$type}_metadata", array( $this, 'get' ), 10, 5 );
			add_filter( "add_{$type}_metadata", array( $this, 'add' ), 10, 5 );
			add_filter( "update_{$type}_metadata", array( $this, 'update' ), 10, 5 );
			add_filter( "delete_{$type}_metadata", array( $this, 'delete' ), 10, 5 );
		}
	}

	/**
	 * Whether interception is on.
	 *
	 * @return bool
	 */
	private function isActive(): bool {
		return null !== $this->active ? (bool) ( $this->active )() : ! defined( 'ACF_VERSION' );
	}

	/**
	 * Resolve a meta key: a managed field name, or an ACF reference row (`_name`).
	 *
	 * @param string $key Meta key.
	 * @return array{0:array{key:string,table:DataTable,multi:bool},1:bool}|null Field and "is reference".
	 */
	private function resolve( string $key ): ?array {
		if ( '' === $key ) {
			return null;
		}
		$ref  = '_' === $key[0];
		$name = $ref ? substr( $key, 1 ) : $key;
		$def  = $this->field( $name );
		if ( null === $def || ( ! $ref && DataTable::Rows === $def['table'] ) || DataTable::None === $def['table'] ) {
			return null;
		}
		return array( $def, $ref );
	}

	/**
	 * Look up a top-level field by name.
	 *
	 * @param string $name Field name.
	 * @return array{key:string,table:DataTable,multi:bool}|null
	 */
	private function field( string $name ): ?array {
		$memo = $this->db->table( 'nf_fields' ) . '|' . $name;
		if ( array_key_exists( $memo, $this->names ) ) {
			return $this->names[ $memo ];
		}
		$rows = $this->db->select(
			'SELECT field_key, data_table, multi FROM ' . $this->db->table( 'nf_fields' ) . ' WHERE field_name = ? AND parent_id = 0 ORDER BY field_id LIMIT 1',
			array( $name )
		);
		$def  = null;
		if ( array() !== $rows ) {
			$def = array(
				'key'   => (string) $rows[0]['field_key'],
				'table' => DataTable::from( (string) $rows[0]['data_table'] ),
				'multi' => 1 === (int) $rows[0]['multi'],
			);
		}
		$this->names[ $memo ] = $def;
		return $def;
	}

	/**
	 * Raw (meta API) shape of a stored value: scalars as strings, lists of strings.
	 *
	 * @param mixed $value Store value.
	 * @return mixed
	 */
	private static function raw( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return array_map( array( self::class, 'raw' ), $value );
		}
		if ( null === $value ) {
			return '';
		}
		return is_bool( $value ) ? ( $value ? '1' : '0' ) : ( is_scalar( $value ) ? (string) $value : $value );
	}

	/**
	 * Filter get_{type}_metadata.
	 *
	 * @param mixed  $check     Short-circuit value.
	 * @param int    $object_id Object ID.
	 * @param string $meta_key  Key ('' for all).
	 * @param bool   $single    Single value.
	 * @param string $meta_type Meta type.
	 * @return mixed
	 */
	public function get( mixed $check, $object_id, $meta_key, $single, $meta_type = '' ): mixed {
		$type = self::TYPES[ $meta_type ] ?? null;
		if ( null !== $check || null === $type || $this->reading || ! $this->isActive() || ! Upgrader::isCurrent() ) {
			return $check;
		}
		$id = (int) $object_id;
		if ( '' === (string) $meta_key ) {
			return $this->all( $type, $id, $meta_type );
		}
		$hit = $this->resolve( (string) $meta_key );
		if ( null === $hit ) {
			return $check;
		}
		[ $def, $ref ] = $hit;
		if ( $ref && DataTable::Rows === $def['table'] ) {
			return $check; // Series reference rows are not intercepted yet (WP 2.4b).
		}
		if ( $ref ) {
			$stored = $this->store->get( $type, $id, $def['key'] );
			$value  = ( null === $stored || array() === $stored ) ? '' : $def['key'];
		} else {
			$value = self::raw( $this->store->get( $type, $id, $def['key'] ) );
			if ( $def['multi'] ) {
				$value = is_array( $value ) ? $value : array();
				if ( array() === $value ) {
					$value = '';
				}
			}
		}
		// Core takes element 0 of the array for single reads.
		return ( $single || '' !== $value ) ? array( $value ) : array();
	}

	/**
	 * Full-array read: core's rows plus NF's fields in raw format, so copy and duplicate tools carry them.
	 *
	 * @param ObjectType $type      Object type.
	 * @param int        $id        Object ID.
	 * @param string     $meta_type Meta type.
	 * @return array<string,array<int,mixed>>
	 */
	private function all( ObjectType $type, int $id, string $meta_type ): array {
		$this->reading = true;
		try {
			$core = get_metadata( $meta_type, $id );
		} finally {
			$this->reading = false;
		}
		$core = is_array( $core ) ? $core : array();
		$rows = $this->db->select(
			'SELECT field_key, field_name, data_table, multi FROM ' . $this->db->table( 'nf_fields' ) . ' WHERE parent_id = 0 ORDER BY field_id'
		);
		foreach ( $rows as $row ) {
			$table = DataTable::from( (string) $row['data_table'] );
			if ( DataTable::Rows === $table || DataTable::None === $table ) {
				continue;
			}
			$value = self::raw( $this->store->get( $type, $id, (string) $row['field_key'] ) );
			if ( '' === $value || array() === $value ) {
				continue;
			}
			$core[ (string) $row['field_name'] ]       = array( $value );
			$core[ '_' . (string) $row['field_name'] ] = array( (string) $row['field_key'] );
		}
		return $core;
	}

	/**
	 * Filter add_{type}_metadata. Adding twice with $unique, or to a scalar field that has a value, is refused like core.
	 *
	 * @param mixed  $check      Short-circuit value.
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Key.
	 * @param mixed  $meta_value Value.
	 * @param bool   $unique     Unique.
	 * @return mixed
	 */
	public function add( mixed $check, $object_id, $meta_key, $meta_value, $unique = false ): mixed {
		return $this->write( $check, (int) $object_id, (string) $meta_key, $meta_value, true === $unique, true );
	}

	/**
	 * Filter update_{type}_metadata.
	 *
	 * @param mixed  $check      Short-circuit value.
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Key.
	 * @param mixed  $meta_value Value.
	 * @param mixed  $prev_value Previous value (ignored).
	 * @return mixed
	 */
	public function update( mixed $check, $object_id, $meta_key, $meta_value, $prev_value = '' ): mixed {
		unset( $prev_value );
		return $this->write( $check, (int) $object_id, (string) $meta_key, $meta_value, false, false );
	}

	/**
	 * Shared write path.
	 *
	 * @param mixed  $check  Short-circuit value.
	 * @param int    $id     Object ID.
	 * @param string $key    Meta key.
	 * @param mixed  $value  Value.
	 * @param bool   $unique Unique flag of add.
	 * @param bool   $isAdd  Called from add.
	 * @return mixed
	 */
	private function write( mixed $check, int $id, string $key, mixed $value, bool $unique, bool $isAdd ): mixed {
		$type = $this->typeOfFilter();
		if ( null !== $check || null === $type || ! $this->isActive() ) {
			return $check;
		}
		if ( ! Upgrader::isCurrent() ) {
			return $this->refuseWhileBehind( $check, $key );
		}
		$hit = $this->resolve( $key );
		if ( null === $hit ) {
			return $check;
		}
		[ $def, $ref ] = $hit;
		if ( $ref ) {
			return true; // Field keys live in nf_fields; copied reference rows are discarded.
		}
		if ( $isAdd && ( $unique || ! $def['multi'] ) && null !== $this->store->get( $type, $id, $def['key'] ) ) {
			return false; // A scalar field holds one value; $unique refuses a second one.
		}
		$value = maybe_unserialize( $value );
		if ( $def['multi'] && ! is_array( $value ) ) {
			$value = '' === $value || null === $value ? array() : array( $value );
		}
		try {
			$this->store->set( $type, $id, $def['key'], '' === $value ? null : $value );
		} catch ( \Throwable $e ) {
			do_action( 'nf/meta/error', $e, $key, $id ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			return false;
		}
		return true;
	}

	/**
	 * Filter delete_{type}_metadata.
	 *
	 * @param mixed  $check      Short-circuit value.
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Key.
	 * @param mixed  $meta_value Value (ignored).
	 * @param bool   $delete_all Delete for all objects (not supported here).
	 * @return mixed
	 */
	public function delete( mixed $check, $object_id, $meta_key, $meta_value = '', $delete_all = false ): mixed {
		unset( $meta_value );
		$type = $this->typeOfFilter();
		if ( null !== $check || null === $type || $delete_all || ! $this->isActive() ) {
			return $check;
		}
		if ( ! Upgrader::isCurrent() ) {
			return $this->refuseWhileBehind( $check, (string) $meta_key );
		}
		$hit = $this->resolve( (string) $meta_key );
		if ( null === $hit ) {
			return $check;
		}
		[ $def, $ref ] = $hit;
		if ( $ref ) {
			return true;
		}
		$this->store->delete( $type, (int) $object_id, $def['key'] );
		return true;
	}

	/**
	 * Schema behind: refuse a write or delete of an NF-managed name (or its `_name` reference row) until the
	 * upgrade runs. Uses only nf_fields columns present since schema 1. With no schema installed NF manages
	 * nothing, and a lookup error lets the write through (reported on nf/meta/error) rather than block all meta.
	 *
	 * @param mixed  $check Short-circuit value.
	 * @param string $key   Meta key.
	 * @return mixed False to refuse, else $check.
	 */
	private function refuseWhileBehind( mixed $check, string $key ): mixed {
		$name = ltrim( $key, '_' );
		if ( '' === $name || (int) get_option( Upgrader::OPTION, 0 ) < 1 ) {
			return $check;
		}
		$memo = 'behind|' . $this->db->table( 'nf_fields' ) . '|' . $name;
		if ( ! array_key_exists( $memo, $this->managed ) ) {
			try {
				$this->managed[ $memo ] = array() !== $this->db->select(
					'SELECT field_id FROM ' . $this->db->table( 'nf_fields' ) . ' WHERE field_name = ? AND parent_id = 0 LIMIT 1',
					array( $name )
				);
			} catch ( \Throwable $e ) {
				do_action( 'nf/meta/error', $e, $key, 0 ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
				return $check;
			}
		}
		return $this->managed[ $memo ] ? false : $check;
	}

	/**
	 * Object type of the filter currently running (core filters carry the type only in the hook name).
	 *
	 * @return ObjectType|null
	 */
	private function typeOfFilter(): ?ObjectType {
		$hook = current_filter();
		if ( 1 === preg_match( '/^(?:add|update|delete)_(post|term|user|comment)_metadata$/', $hook, $m ) ) {
			return self::TYPES[ $m[1] ];
		}
		return null;
	}
}
