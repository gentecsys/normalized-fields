<?php
/**
 * Default pivot sets and slot assignment.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Pivot;

use NF\Acf\TypeMap;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;

defined( 'ABSPATH' ) || exit;

/**
 * Gives every post type with NF fields a pivot set and puts its eligible top-level fields into the next free slot
 * of the matching letter, in field order, until the slots run out. Existing assignments never move. It writes only
 * when called from a trusted context (WP-CLI, capability-checked admin save); the caller decides.
 */
final class SlotAssigner {

	/**
	 * ACF types whose single value is worth a slot.
	 */
	private const SINGLE_TYPES = array( 'true_false', 'number', 'range', 'date_picker', 'date_time_picker', 'select', 'radio', 'post_object', 'user', 'image' );

	/**
	 * ACF types with several string values (bitmask slots).
	 */
	private const MASK_TYPES = array( 'checkbox', 'select' );

	/**
	 * Slots per letter that exist in nf_pivot.
	 */
	public const CAPACITY = array(
		'i' => 8,
		'n' => 4,
		't' => 6,
		's' => 8,
		'm' => 4,
	);

	/**
	 * Constructor.
	 *
	 * @param Pivot    $pivot  Pivot service.
	 * @param FieldMap $fields Field map.
	 */
	public function __construct( private Pivot $pivot, private FieldMap $fields ) {}

	/**
	 * Slot letter an ACF field would use, or null when it is not eligible.
	 *
	 * @param array<string,mixed> $field ACF field.
	 * @return string|null
	 */
	public static function letter( array $field ): ?string {
		$type = (string) ( $field['type'] ?? '' );
		if ( empty( $field['key'] ) || empty( $field['name'] ) ) {
			return null;
		}
		[ $table, $multi ] = TypeMap::storage( $field );
		if ( $multi ) {
			return DataTable::Str === $table && in_array( $type, self::MASK_TYPES, true ) ? 'm' : null;
		}
		if ( ! in_array( $type, self::SINGLE_TYPES, true ) ) {
			return null;
		}
		return match ( $table ) {
			DataTable::Int  => 'i',
			DataTable::Dec  => 'n',
			DataTable::Date => 't',
			DataTable::Str  => 's',
			default         => null,
		};
	}

	/**
	 * Post types a collection (ACF field group) is attached to by `post_type ==` rules.
	 *
	 * @param array<string,mixed> $group ACF field group.
	 * @return string[]
	 */
	public static function postTypes( array $group ): array {
		$out = array();
		foreach ( (array) ( $group['location'] ?? array() ) as $and ) {
			foreach ( (array) $and as $rule ) {
				if ( is_array( $rule ) && 'post_type' === ( $rule['param'] ?? '' ) && '==' === ( $rule['operator'] ?? '' ) && is_string( $rule['value'] ?? null ) && '' !== $rule['value'] && 'all' !== $rule['value'] ) {
					$out[ sanitize_key( $rule['value'] ) ] = true;
				}
			}
		}
		return array_keys( $out );
	}

	/**
	 * Fields per post type from ACF field groups, in group then field order.
	 *
	 * @param array<int,array<string,mixed>> $groups Groups with a `fields` array.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	public static function fieldsByPostType( array $groups ): array {
		$out = array();
		foreach ( $groups as $group ) {
			if ( empty( $group['active'] ) && array_key_exists( 'active', $group ) ) {
				continue;
			}
			foreach ( self::postTypes( $group ) as $post_type ) {
				foreach ( (array) ( $group['fields'] ?? array() ) as $field ) {
					if ( is_array( $field ) ) {
						$out[ $post_type ][] = $field;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Assign slots for one post type.
	 *
	 * @param string                         $post_type Post type.
	 * @param array<int,array<string,mixed>> $fields    Its ACF top-level fields.
	 * @param bool                           $dry_run   Only report; create and write nothing.
	 * @return array{pivot_id:int|null,assigned:array<string,string>,skipped:string[],created:bool}
	 */
	public function assign( string $post_type, array $fields, bool $dry_run = false ): array {
		if ( ! $dry_run && ! \NF\Install\Upgrader::isCurrent() ) {
			update_option( \NF\Install\Upgrader::NEEDS_RESYNC, 1, false );
			do_action( 'nf/pivot/slots_skipped', $post_type ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			return array(
				'pivot_id' => null,
				'assigned' => array(),
				'skipped'  => array(),
				'created'  => false,
			);
		}
		$set_id  = $this->pivot->findSetId( ObjectType::Post, $post_type );
		$created = false;
		$taken   = array();
		$have    = array();
		if ( null !== $set_id ) {
			foreach ( $this->pivot->slots( $set_id ) as $slot => $def ) {
				$taken[ $slot ]      = true;
				$have[ $def['key'] ] = true;
			}
		}
		$plan    = array();
		$skipped = array();
		foreach ( $fields as $field ) {
			$letter = self::letter( $field );
			$key    = (string) ( $field['key'] ?? '' );
			if ( null === $letter || isset( $have[ $key ] ) || isset( $plan[ $key ] ) ) {
				continue;
			}
			$slot = null;
			for ( $n = 1; $n <= self::CAPACITY[ $letter ]; $n++ ) {
				if ( empty( $taken[ $letter . $n ] ) ) {
					$slot = $letter . $n;
					break;
				}
			}
			if ( null === $slot ) {
				$skipped[] = $key;
				continue;
			}
			$taken[ $slot ] = true;
			$plan[ $key ]   = array( $slot, $field );
		}
		$assigned = array();
		foreach ( $plan as $key => [ $slot, $field ] ) {
			$assigned[ $key ] = $slot;
		}
		if ( $dry_run || array() === $plan ) {
			return array(
				'pivot_id' => $set_id,
				'assigned' => $assigned,
				'skipped'  => $skipped,
				'created'  => $created,
			);
		}
		if ( null === $set_id ) {
			$set_id  = $this->pivot->setId( ObjectType::Post, $post_type );
			$created = true;
		}
		foreach ( $plan as [ $slot, $field ] ) {
			// Register in the field map first (idempotent); a Series' sub-fields are not slotted.
			[ $table, $multi ] = TypeMap::storage( $field );
			$this->fields->register( (string) $field['key'], (string) $field['name'], $table, $multi );
			$this->pivot->assignSlot( $set_id, (string) $field['key'], $slot );
		}
		// Existing objects have no value in the new slots until pivot_rebuild finishes; queries skip them until then.
		$this->pivot->markPending( $set_id, array_values( $assigned ) );
		return array(
			'pivot_id' => $set_id,
			'assigned' => $assigned,
			'skipped'  => $skipped,
			'created'  => $created,
		);
	}
}
