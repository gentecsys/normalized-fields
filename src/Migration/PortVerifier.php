<?php
/**
 * Dual load of one object: ACF value against NF value (WP 2.6d).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Migration;

use NF\Acf\Bridge;
use NF\Storage\FieldMap;
use NF\Storage\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Reads every portable field of an object raw from ACF and from the NF store and lists the fields that differ.
 * Only fields ACF holds a value for are checked (the same rule as the port), so a field ACF never saved is not
 * a mismatch. Read only.
 */
class PortVerifier {

	/**
	 * Constructor.
	 *
	 * @param Store|null    $store  NF store (null only in tests with the readers set).
	 * @param FieldMap|null $fields Field map.
	 * @param \Closure|null $acf    Raw ACF read: fn( int|string $id, array $field ): array{0:bool,1:mixed}, held flag then value. Defaults to ACF's.
	 * @param \Closure|null $nf     NF read: fn( int|string $id, array $field ): mixed. Defaults to the store.
	 */
	public function __construct( private ?Store $store = null, private ?FieldMap $fields = null, private ?\Closure $acf = null, private ?\Closure $nf = null ) {}

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
				$paths = ValueComparer::diff( $value, $this->readNf( $id, $field ), (string) $field['name'] );
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
