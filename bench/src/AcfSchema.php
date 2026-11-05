<?php
/**
 * ACF field group definition for the benchmark dataset.
 *
 * @package NF\Bench
 */

declare(strict_types=1);

namespace NF\Bench;

/**
 * Builds the ACF field group (keys `field_nfbench_*`) matching Dataset::FIELDS.
 * The committed `bench/acf-json/group_nfbench_events.json` is generated from it
 * and a unit test keeps the two identical.
 */
final class AcfSchema {

	public const GROUP_KEY = 'group_nfbench_events';

	/**
	 * Full ACF field group array (ACF JSON format).
	 *
	 * @return array<string,mixed>
	 */
	public static function group(): array {
		$fields = array();
		foreach ( Dataset::FIELDS as $name => $type ) {
			$fields[] = self::field( $name, $type );
		}
		$fields[] = array(
			'key'          => AcfMeta::key( 'sessions' ),
			'label'        => 'Sessions',
			'name'         => 'sessions',
			'type'         => 'repeater',
			'layout'       => 'table',
			'min'          => 0,
			'max'          => 0,
			'button_label' => 'Add row',
			'sub_fields'   => array(
				self::sub_field( 'date', 'date_picker' ),
				self::sub_field( 'time', 'time_picker' ),
				self::sub_field( 'room', 'text' ),
			),
		);

		return array(
			'key'                   => self::GROUP_KEY,
			'title'                 => 'NF Bench Event',
			'fields'                => $fields,
			'location'              => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => Dataset::POST_TYPE,
					),
				),
			),
			'menu_order'            => 0,
			'position'              => 'normal',
			'style'                 => 'default',
			'label_placement'       => 'top',
			'instruction_placement' => 'label',
			'active'                => true,
		);
	}

	/**
	 * Top-level field.
	 *
	 * @param string $name Field name.
	 * @param string $type ACF field type.
	 * @return array<string,mixed>
	 */
	private static function field( string $name, string $type ): array {
		return array_merge(
			array(
				'key'   => AcfMeta::key( $name ),
				'label' => ucfirst( str_replace( '_', ' ', $name ) ),
				'name'  => $name,
				'type'  => $type,
			),
			self::settings( $name, $type )
		);
	}

	/**
	 * Repeater sub field (key `field_nfbench_sessions_<name>`).
	 *
	 * @param string $name Sub field name.
	 * @param string $type ACF field type.
	 * @return array<string,mixed>
	 */
	private static function sub_field( string $name, string $type ): array {
		return array_merge(
			array(
				'key'   => AcfMeta::key( 'sessions_' . $name ),
				'label' => ucfirst( $name ),
				'name'  => $name,
				'type'  => $type,
			),
			self::settings( $name, $type )
		);
	}

	/**
	 * Type-specific settings; return formats make get_field() return formatted values.
	 *
	 * @param string $name Field name.
	 * @param string $type ACF field type.
	 * @return array<string,mixed>
	 */
	private static function settings( string $name, string $type ): array {
		switch ( $type ) {
			case 'date_picker':
				return array(
					'display_format' => 'd/m/Y',
					'return_format'  => 'Ymd',
					'first_day'      => 1,
				);
			case 'time_picker':
				return array(
					'display_format' => 'H:i',
					'return_format'  => 'H:i',
				);
			case 'number':
				return array(
					'min'  => 0,
					'step' => 1,
				);
			case 'true_false':
				return array(
					'default_value' => 0,
					'ui'            => 1,
				);
			case 'checkbox':
				$choices = array();
				foreach ( Dataset::CATEGORIES as $slug ) {
					$choices[ $slug ] = ucfirst( $slug );
				}
				return array(
					'choices'       => $choices,
					'layout'        => 'vertical',
					'return_format' => 'value',
					'allow_custom'  => 0,
					'toggle'        => 0,
				);
			case 'relationship':
				return array(
					'post_type'     => array( 'post' ),
					'filters'       => array( 'search' ),
					'return_format' => 'id',
					'min'           => 1,
					'max'           => 4,
				);
			default:
				return 'text' === $type ? array( 'maxlength' => 255 ) : array();
		}
	}

	/**
	 * Encode as the ACF JSON file contents.
	 */
	public static function json(): string {
		return json_encode( self::group(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	}
}
