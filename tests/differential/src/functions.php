<?php
/**
 * Composite probes for differential scenarios. Each probe resets its own state first (delete_field), so it gives the same
 * answer on every target even though both targets share one database in the ACF-vs-ACF baseline.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

/**
 * Delete, write, then read a field back; finally delete it and read again.
 *
 * @param string $field   Field name.
 * @param int    $post_id Post ID.
 * @param mixed  $value   Value to write.
 * @return array<string,mixed>
 */
function write_read_delete( string $field, int $post_id, mixed $value ): array {
	delete_field( $field, $post_id );
	$result                = array();
	$result['updated']     = (bool) update_field( $field, $value, $post_id );
	$result['formatted']   = get_field( $field, $post_id );
	$result['raw']         = get_field( $field, $post_id, false );
	$result['deleted']     = (bool) delete_field( $field, $post_id );
	$result['after_empty'] = get_field( $field, $post_id );
	return $result;
}

/**
 * Overwrite an existing value: write A, then B, then read.
 *
 * @param string $field   Field name.
 * @param int    $post_id Post ID.
 * @param mixed  $first   First value.
 * @param mixed  $second  Second value.
 * @return array<string,mixed>
 */
function overwrite_read( string $field, int $post_id, mixed $first, mixed $second ): array {
	delete_field( $field, $post_id );
	update_field( $field, $first, $post_id );
	$result              = array();
	$result['updated']   = (bool) update_field( $field, $second, $post_id );
	$result['unchanged'] = (bool) update_field( $field, $second, $post_id );
	$result['formatted'] = get_field( $field, $post_id );
	$result['raw']       = get_field( $field, $post_id, false );
	return $result;
}
