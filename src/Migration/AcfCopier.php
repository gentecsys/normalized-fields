<?php
/**
 * Copies one ACF field value into NF (WP 2.6b).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Migration;

use NF\Acf\Bridge;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a value raw (`acf_get_value()`, no formatting) and writes it through the bridge, exactly as if ACF had just
 * saved it. A field is copied only when ACF holds a value for the object: its `_name` reference points at the
 * field's key (or at its source key for a cloned field).
 */
class AcfCopier {

	/**
	 * Constructor.
	 *
	 * @param Bridge|null   $bridge Bridge, which writes through the store (null only in tests with the closures set).
	 * @param \Closure|null $meta   Reads the `_name` reference: fn( int $post_id, string $name ): mixed. Defaults to ACF's.
	 * @param \Closure|null $value  Reads a raw value: fn( int $post_id, array $field ): mixed. Defaults to ACF's.
	 * @param \Closure|null $sink   Writes: fn( string $kind, mixed $value, int $post_id, array $field ): void, kind is series, group or scalar. Defaults to the bridge.
	 */
	public function __construct( private ?Bridge $bridge = null, private ?\Closure $meta = null, private ?\Closure $value = null, private ?\Closure $sink = null ) {}

	/**
	 * Copy one top-level field of one post.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $field   ACF field.
	 * @return bool True when a value was copied; false when ACF holds none.
	 */
	public function copy( int $post_id, array $field ): bool {
		$ref    = null !== $this->meta ? ( $this->meta )( $post_id, (string) $field['name'] ) : acf_get_metadata( $post_id, (string) $field['name'], true );
		$source = (string) ( $field['__key'] ?? $field['key'] );
		if ( $ref !== $field['key'] && $ref !== $source ) {
			return false;
		}
		$value = null !== $this->value ? ( $this->value )( $post_id, $field ) : acf_get_value( $post_id, $field );
		$kind  = match ( $field['type'] ?? '' ) {
			'repeater'      => 'series',
			'group', 'clone' => 'group',
			default         => 'scalar',
		};
		if ( null !== $this->sink ) {
			( $this->sink )( $kind, $value, $post_id, $field );
			return true;
		}
		$bridge = $this->bridge;
		if ( null === $bridge ) {
			return false;
		}
		if ( 'series' === $kind ) {
			$bridge->beforeSeries( $value, $post_id, $field );
			$bridge->afterSeries( $value );
		} elseif ( 'group' === $kind ) {
			$bridge->beforeGroup( $value, $post_id, $field );
		} else {
			$bridge->onUpdate( $value, $post_id, $field );
		}
		return true;
	}

	/**
	 * Write what the bridge queued (Series re-reads) once a batch is copied.
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->bridge?->flush();
	}
}
