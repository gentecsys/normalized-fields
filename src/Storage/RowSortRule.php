<?php
/**
 * Series row sort rule.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * The `nf_sort` field setting (design doc, "Sorting repeater rows").
 */
final class RowSortRule {

	public const MANUAL  = 'manual';
	public const ON_SAVE = 'on_save';
	public const ON_READ = 'on_read';

	/**
	 * Constructor.
	 *
	 * @param string $mode    manual | on_save | on_read.
	 * @param string $by      sub_field:<name> | post_date:<sub_field> | callback:<sorter>.
	 * @param string $order   asc | desc.
	 * @param string $compare locale | natural | binary.
	 * @param string $blanks first | last.
	 */
	public function __construct(
		public readonly string $mode,
		public readonly string $by,
		public readonly string $order = 'asc',
		public readonly string $compare = 'locale',
		public readonly string $blanks = 'last'
	) {}

	/**
	 * Build from the field's `nf_sort` array; unknown values fall back to the defaults.
	 *
	 * @param array<string,mixed> $setting The setting.
	 * @return self|null Null when no sort key is set.
	 */
	public static function fromArray( array $setting ): ?self {
		$by = isset( $setting['by'] ) ? (string) $setting['by'] : '';
		if ( '' === $by ) {
			return null;
		}
		$pick = static function ( string $key, array $allowed, string $fallback ) use ( $setting ): string {
			$value = isset( $setting[ $key ] ) ? (string) $setting[ $key ] : $fallback;
			return in_array( $value, $allowed, true ) ? $value : $fallback;
		};
		return new self(
			$pick( 'mode', array( self::MANUAL, self::ON_SAVE, self::ON_READ ), self::MANUAL ),
			$by,
			$pick( 'order', array( 'asc', 'desc' ), 'asc' ),
			$pick( 'compare', array( 'locale', 'natural', 'binary' ), 'locale' ),
			$pick( 'empty', array( 'first', 'last' ), 'last' )
		);
	}

	/**
	 * Copy with another mode (an editor action sorts once whatever the stored mode is).
	 *
	 * @param string $mode New mode.
	 * @return self
	 */
	public function withMode( string $mode ): self {
		return new self( $mode, $this->by, $this->order, $this->compare, $this->blanks );
	}
}
