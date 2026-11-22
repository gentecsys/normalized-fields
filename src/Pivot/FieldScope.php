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
 * with one candidate resolves to it; with several, the candidates attached to the queried post type decide, and several attached to the same post type throw.
 */
final class FieldScope {

	/**
	 * Field keys attached to a post type, per post type (memo).
	 *
	 * @var array<string,array<string,true>>
	 */
	private static array $attached = array();

	/**
	 * Source shared by instances created without one.
	 *
	 * @var AcfSource|null
	 */
	private static ?AcfSource $shared = null;

	/**
	 * Constructor.
	 *
	 * @param AcfSource|null $source Field group source; defaults to ACF's own API.
	 */
	public function __construct( private ?AcfSource $source = null ) {}

	/**
	 * Forget memoised attachments (tests).
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$attached = array();
		self::$shared   = null;
	}

	/**
	 * Group source used by instances created without one (tests).
	 *
	 * @param AcfSource|null $source Source.
	 * @return void
	 */
	public static function useSource( ?AcfSource $source ): void {
		self::$attached = array();
		self::$shared   = $source;
	}

	/**
	 * Pick one of several rows that share a field name.
	 *
	 * @param array<int,array<string,mixed>> $rows    Candidate rows, each with `field_key`.
	 * @param ObjectType                     $type    Queried object type.
	 * @param string                         $subtype Post type or ''.
	 * @param string                         $name    Field name (for the error).
	 * @return array<string,mixed>|null Null when there are no candidates.
	 * @throws \InvalidArgumentException When several collections attached to the post type define the name.
	 */
	public function choose( array $rows, ObjectType $type, string $subtype, string $name ): ?array {
		if ( array() === $rows ) {
			return null;
		}
		if ( 1 === count( $rows ) ) {
			return $rows[0];
		}
		$keys = ObjectType::Post === $type && '' !== $subtype ? $this->attachedKeys( $subtype ) : array();
		if ( array() === $keys ) {
			// Nothing known about the type's collections (terms, users, comments, or no group source): first definition.
			return $rows[0];
		}
		$hits = array_values( array_filter( $rows, static fn( array $r ): bool => isset( $keys[ (string) $r['field_key'] ] ) ) );
		if ( count( $hits ) > 1 ) {
			throw new \InvalidArgumentException( 'Field name "' . esc_html( $name ) . '" is ambiguous: several collections on this post type define it. Use the field key.' );
		}
		// One hit is the field; none means the name is not an NF field on this post type.
		return $hits[0] ?? null;
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
		$source = $this->source ?? self::$shared ?? new AcfSource();
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
