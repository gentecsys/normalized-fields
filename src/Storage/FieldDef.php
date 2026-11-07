<?php
/**
 * Field definition row.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * One row of nf_fields.
 */
final class FieldDef {

	/**
	 * Constructor.
	 *
	 * @param int       $id       field_id.
	 * @param string    $key      ACF field key.
	 * @param string    $name     Field name.
	 * @param int       $parentId Parent field_id (sub-fields), 0 at top level.
	 * @param DataTable $table    Data table.
	 * @param bool      $multi    Multi-valued.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $key,
		public readonly string $name,
		public readonly int $parentId,
		public readonly DataTable $table,
		public readonly bool $multi
	) {}
}
