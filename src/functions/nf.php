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

if ( ! function_exists( 'nf_register_row_sorter' ) ) {
	/**
	 * Register a custom Series row sorter, usable as `callback:<name>` in a field's `nf_sort.by`.
	 *
	 * @param string              $name Sorter name.
	 * @param array<string,mixed> $args `label` and `compare` (callable( row, row ): int).
	 * @return bool
	 */
	function nf_register_row_sorter( string $name, array $args ): bool {
		return \NF\Plugin::sorter()->register( $name, $args );
	}
}

if ( ! function_exists( 'nf_query' ) ) {
	/**
	 * Start a query on a post type (`event`), taxonomy (`term:genre`) or `user` (D-15).
	 *
	 * @param string $target Target.
	 * @return \NF\Query\Builder
	 */
	function nf_query( string $target ): \NF\Query\Builder {
		return new \NF\Query\Builder( $target, \NF\Plugin::queryContext() );
	}
}
