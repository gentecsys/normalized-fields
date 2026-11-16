<?php
/**
 * Dual load of one object: ACF value against NF value (WP 2.6d).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Migration;

use NF\Acf\Bridge;
use NF\Acf\TypeMap;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\RowSorter;
use NF\Storage\RowSortRule;
use NF\Storage\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Reads every portable field of an object raw from ACF and from the NF store and lists the fields that differ.
 * The raw ACF value is first converted the way the port writes it (dates `Ymd` to `Y-m-d H:i:s`, times to
 * seconds, Series rows keyed by sub-field name and sorted by the field's sort rule), so only real differences
 * are reported. Only fields ACF holds a value for are checked (the same rule as the port), so a field ACF never
 * saved is not a mismatch. Read only.
 */
class PortVerifier {

	/**
	 * Constructor.
	 *
	 * @param Store|null     $store  NF store (null only in tests with the readers set).
	 * @param FieldMap|null  $fields Field map.
	 * @param \Closure|null  $acf    Raw ACF read: fn( int|string $id, array $field ): array{0:bool,1:mixed}, held flag then value. Defaults to ACF's.
	 * @param \Closure|null  $nf     NF read: fn( int|string $id, array $field ): mixed. Defaults to the store.
	 * @param RowSorter|null $sorter Series row sorter, so sorted Series compare in the order NF returns them.
	 */
	public function __construct( private ?Store $store = null, private ?FieldMap $fields = null, private ?\Closure $acf = null, private ?\Closure $nf = null, private ?RowSorter $sorter = null ) {}

	/**
	 * Mismatches for one object.
	 *
	 * @param int|string                     $id     ACF object ID.
	 * @param array<int,array<string,mixed>> $fields Top-level ACF fields.
	 * @return array<int,array{object:int|string,field:string,paths:string[]}>
	 */
	public function verify( int|string $id, array $fields ): array {
		$out = array();
		foreach ( $fields as $field ) {
			[ $held, $value ] = $this->readAcf( $id, $field );
			if ( ! $held ) {
				continue;
			}
			if ( ! is_array( $value ) && in_array( $field['type'] ?? '', array( 'repeater', 'group', 'clone' ), true ) ) {
				// A row count or an empty group from ACF carries nothing to compare.
				continue;
			}
			try {
				$paths = ValueComparer::diff( $this->toNf( $id, $field, $value ), $this->readNf( $id, $field ), (string) $field['name'] );
			} catch ( \Throwable $e ) {
				$paths = array( '(unreadable: ' . substr( $e->getMessage(), 0, 80 ) . ')' );
			}
			if ( array() !== $paths ) {
				$out[] = array(
					'object' => $id,
					'field'  => (string) $field['name'],
					'paths'  => array_slice( $paths, 0, 10 ),
				);
			}
		}
		return $out;
	}

	/**
	 * Raw ACF value, and whether ACF holds one.
	 *
	 * @param int|string          $id    Object.
	 * @param array<string,mixed> $field Field.
	 * @return array{0:bool,1:mixed}
	 */
	private function readAcf( int|string $id, array $field ): array {
		if ( null !== $this->acf ) {
			return ( $this->acf )( $id, $field );
		}
		$ref    = acf_get_metadata( $id, (string) $field['name'], true );
		$source = (string) ( $field['__key'] ?? $field['key'] );
		if ( $ref !== $field['key'] && $ref !== $source ) {
			return array( false, null );
		}
		return array( true, acf_get_value( $id, $field ) );
	}

	/**
	 * A raw ACF value in the form the port writes to NF. Unchanged when the field has no NF definition.
	 *
	 * @param int|string          $id    Object.
	 * @param array<string,mixed> $field Field.
	 * @param mixed               $value Raw ACF value.
	 * @return mixed
	 */
	private function toNf( int|string $id, array $field, mixed $value ): mixed {
		$def = $this->fields?->byKey( (string) $field['key'] );
		if ( null === $def || null === $this->fields ) {
			return $value;
		}
		$type = $field['type'] ?? '';
		if ( 'repeater' === $type ) {
			$rows = TypeMap::normalizeRows( $this->fields->children( $def->id ), is_array( $value ) ? $value : array() );
			$rule = $this->sorter?->rule( $def->key );
			if ( null !== $this->sorter && null !== $rule && RowSortRule::MANUAL !== $rule->mode ) {
				$target = Bridge::decode( $id );
				$rows   = $this->sorter->sort( $rows, $rule, $def->key, null === $target ? 0 : $target[1] );
			}
			return $rows;
		}
		if ( in_array( $type, array( 'group', 'clone' ), true ) || DataTable::None === $def->table ) {
			return TypeMap::normalizeComposite( $field, $value );
		}
		return TypeMap::normalize( $value, $def->table, $def->multi );
	}

	/**
	 * Value from NF.
	 *
	 * @param int|string          $id    Object.
	 * @param array<string,mixed> $field Field.
	 * @return mixed
	 */
	private function readNf( int|string $id, array $field ): mixed {
		if ( null !== $this->nf ) {
			return ( $this->nf )( $id, $field );
		}
		$target = Bridge::decode( $id );
		$def    = $this->fields?->byKey( (string) $field['key'] );
		if ( null === $target || null === $def || null === $this->store ) {
			return null;
		}
		return 'repeater' === ( $field['type'] ?? '' )
			? $this->store->getRows( $target[0], $target[1], $def->key )
			: $this->store->get( $target[0], $target[1], $def->key );
	}
}
