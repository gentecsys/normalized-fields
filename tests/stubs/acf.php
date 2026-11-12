<?php
/**
 * PHPStan stubs for the ACF functions NF calls (only inside function_exists() guards at runtime).
 *
 * @package NF
 */

/**
 * Field groups.
 *
 * @param array<string,mixed> $filter Filter.
 * @return array<int,array<string,mixed>>
 */
function acf_get_field_groups( $filter = array() ) {}

/**
 * Fields of a field group.
 *
 * @param array<string,mixed>|string|int $parent Field group.
 * @return array<int,array<string,mixed>>
 */
function acf_get_fields( $parent ) {}

/**
 * Raw metadata.
 *
 * @param mixed  $post_id ACF post ID.
 * @param string $name    Name.
 * @param bool   $hidden  Read the `_name` reference instead.
 * @return mixed
 */
function acf_get_metadata( $post_id = 0, $name = '', $hidden = false ) {}

/**
 * Raw value of a field.
 *
 * @param mixed               $post_id ACF post ID.
 * @param array<string,mixed> $field   Field.
 * @return mixed
 */
function acf_get_value( $post_id, $field ) {}

/**
 * Every ACF value of an object, by name.
 *
 * @param mixed $post_id ACF post ID.
 * @return array<string,mixed>
 */
function acf_get_meta( $post_id = 0 ) {}
