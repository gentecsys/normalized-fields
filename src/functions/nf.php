<?php
/**
 * NF's own public procedural API (contracts/api.md). Thin wrappers only; no logic here.
 * Functions are added by the WPs that own them, each guarded by function_exists().
 *
 * @package NF
 */

declare(strict_types=1);

// No ABSPATH guard: Composer CLI tools load this file outside WordPress, and it only declares guarded functions.

if ( ! function_exists( 'nf_db' ) ) {
	/**
	 * The backend adapter for NF tables (contracts/backend.md).
	 *
	 * @return \NF\Backend\BackendInterface
	 */
	function nf_db(): \NF\Backend\BackendInterface {
		return \NF\Plugin::db();
	}
}
