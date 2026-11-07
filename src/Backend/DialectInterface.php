<?php
/**
 * SQL dialect contract (contracts/backend.md, ADR-0009).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Backend;

defined( 'ABSPATH' ) || exit;

/**
 * Builds SQL text. Identifiers come from the schema contract, values are always bound.
 */
interface DialectInterface {

	/**
	 * Quote an identifier.
	 *
	 * @param string $name Identifier.
	 * @return string
	 */
	public function quoteIdentifier( string $name ): string;

	/**
	 * Single-row upsert with one ? per column (keys first, then values).
	 *
	 * @param string   $table        Physical table.
	 * @param string[] $keyColumns   Key columns.
	 * @param string[] $valueColumns Columns updated on conflict.
	 * @return string
	 */
	public function upsert( string $table, array $keyColumns, array $valueColumns ): string;

	/**
	 * LIMIT/OFFSET clause.
	 *
	 * @param int $limit  Limit.
	 * @param int $offset Offset.
	 * @return string
	 */
	public function limit( int $limit, int $offset ): string;

	/**
	 * ORDER BY item with explicit null placement.
	 *
	 * @param string $column Column.
	 * @param string $dir    ASC or DESC.
	 * @param string $nulls  FIRST or LAST.
	 * @return string
	 */
	public function nullsOrder( string $column, string $dir, string $nulls ): string;

	/**
	 * Explicit cast by declared data type.
	 *
	 * @param string $expr     Expression.
	 * @param string $dataType int|dec|date|str.
	 * @return string
	 */
	public function cast( string $expr, string $dataType ): string;

	/**
	 * Index hint, empty outside MySQL.
	 *
	 * @param string $table Table.
	 * @param string $index Index.
	 * @return string
	 */
	public function indexHint( string $table, string $index ): string;

	/**
	 * Single-bit test.
	 *
	 * @param string $column Column.
	 * @param int    $bit    Bit number.
	 * @return string
	 */
	public function bit( string $column, int $bit ): string;

	/**
	 * Bitmask test.
	 *
	 * @param string $column Column.
	 * @param int    $mask   Mask.
	 * @param string $mode   any|all|none.
	 * @return string
	 */
	public function mask( string $column, int $mask, string $mode ): string;

	/**
	 * Statement timeout hint, empty when unsupported.
	 *
	 * @param int $ms Milliseconds.
	 * @return string
	 */
	public function statementTimeout( int $ms ): string;
}
