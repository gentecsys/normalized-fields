<?php
/**
 * Resolves a field name to one field within the collections attached to an object type.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Pivot;

use NF\Migration\AcfSource;
use NF\Storage\ObjectType;

defined( 'ABSPATH' ) || exit;

/**
 * Two collections may name a field the same on different post types (`price` on products and on rooms). A name
 * with one candidate resolves to it; with several, the candidates attached to the queried post type decide, and a name
 * that stays ambiguous throws.
 */
final class FieldScope {

	/**
	 * Field keys attached to a post type, per post type (memo).
	 *
	 * @var array<string,array<string,true>>
	 */
	private static array $attached = array();

	/**
	 * Constructor.
	 *
	 * @param AcfSource|null $source Field group source; defaults to ACF's own API.
	 */
	public function __construct( private ?AcfSource $source = null ) {}

	/**
	 * Forget memoised attachments (tests, group saves).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$attached = array();
	}

	/**
	 * Pick one of several rows that share a field name.
	 *
	 * @param array<int,array<string,mixed>> $rows    Candidate rows, each with `field_key`.
	 * @param ObjectType                     $type    Queried object type.
	 * @param string                         $subtype Post type or ''.
	 * @param string                         $name    Field name (for the error).
	 * @return array<string,mixed>|null Null when there are no candidates.
	 * @throws \InvalidArgumentException When the name stays ambiguous.
	 */
	public function choose( array $rows, ObjectType $type, string $subtype, string $name ): ?array {
		if ( array() === $rows ) {
			return null;
		}
		if ( 1 === count( $rows ) ) {
			return $rows[0];
		}
		$keys = ObjectType::Post === $type && '' !== $subtype ? $this->attachedKeys( $subtype ) : array();
		$hits = array_values( array_filter( $rows, static fn( array $r ): bool => isset( $keys[ (string) $r['field_key'] ] ) ) );
		if ( 1 === count( $hits ) ) {
			return $hits[0];
		}
		throw new \InvalidArgumentException( sprintf( 'Field name "%s" is ambiguous: several collections define it. Use the field key.', esc_html( $name ) ) );
	}

	/**
	 * Top-level field keys of the collections attached to a post type.
	 *
	 * @param string $postType Post type.
	 * @return array<string,true>
	 */
	private function attachedKeys( string $postType ): array {
		if ( isset( self::$attached[ $postType ] ) ) {
			return self::$attached[ $postType ];
		}
		$source = $this->source ?? new AcfSource();
		$out    = array();
		foreach ( SlotAssigner::fieldsByPostType( $source->groups() )[ $postType ] ?? array() as $field ) {
			if ( ! empty( $field['key'] ) ) {
				$out[ (string) $field['key'] ] = true;
			}
		}
		self::$attached[ $postType ] = $out;
		return $out;
	}
}
