<?php
/**
 * MySQL / MariaDB dialect.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Backend;

defined( 'ABSPATH' ) || exit;

/**
 * MySQL SQL text. Phase 1 covers what the store needs; the query compiler (A4) extends the rest.
 */
final class MysqlDialect implements DialectInterface {

	/**
	 * Ident check: names come from the contract, but fail closed anyway.
	 *
	 * @param string $name Identifier.
	 * @return string
	 * @throws \InvalidArgumentException When an argument is invalid.
	 */
	public function quoteIdentifier( string $name ): string {
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $name ) ) {
			throw new \InvalidArgumentException( 'Invalid SQL identifier.' );
		}
		return '`' . $name . '`';
	}

	/**
	 * Upsert.
	 *
	 * @param string   $table        Physical table.
	 * @param string[] $keyColumns   Key columns.
	 * @param string[] $valueColumns Value columns.
	 * @return string
	 */
	public function upsert( string $table, array $keyColumns, array $valueColumns ): string {
		$all    = array_merge( $keyColumns, $valueColumns );
		$cols   = implode( ', ', array_map( array( $this, 'quoteIdentifier' ), $all ) );
		$marks  = implode( ', ', array_fill( 0, count( $all ), '?' ) );
		$update = implode(
			', ',
			array_map(
				fn( string $c ): string => $this->quoteIdentifier( $c ) . ' = VALUES(' . $this->quoteIdentifier( $c ) . ')',
				$valueColumns
			)
		);
		return 'INSERT INTO ' . $this->quoteIdentifier( $table ) . " ({$cols}) VALUES ({$marks}) ON DUPLICATE KEY UPDATE {$update}";
	}

	/**
	 * Limit clause.
	 *
	 * @param int $limit  Limit.
	 * @param int $offset Offset.
	 * @return string
	 */
	public function limit( int $limit, int $offset ): string {
		return 'LIMIT ' . max( 0, $limit ) . ' OFFSET ' . max( 0, $offset );
	}

	/**
	 * Explicit null placement (MySQL has no NULLS FIRST/LAST).
	 *
	 * @param string $column Column.
	 * @param string $dir    ASC or DESC.
	 * @param string $nulls  FIRST or LAST.
	 * @return string
	 */
	public function nullsOrder( string $column, string $dir, string $nulls ): string {
		$col = $this->quoteIdentifier( $column );
		$dir = 'DESC' === strtoupper( $dir ) ? 'DESC' : 'ASC';
		$nl  = 'FIRST' === strtoupper( $nulls ) ? 'DESC' : 'ASC';
		return "{$col} IS NULL {$nl}, {$col} {$dir}";
	}

	/**
	 * Cast.
	 *
	 * @param string $expr     Expression.
	 * @param string $dataType int|dec|date|str.
	 * @return string
	 * @throws \InvalidArgumentException When an argument is invalid.
	 */
	public function cast( string $expr, string $dataType ): string {
		return match ( $dataType ) {
			'int'   => "CAST({$expr} AS SIGNED)",
			'dec'   => "CAST({$expr} AS DECIMAL(24,10))",
			'date'  => "CAST({$expr} AS DATETIME)",
			'str'   => "CAST({$expr} AS CHAR)",
			default => throw new \InvalidArgumentException( 'Unknown data type.' ),
		};
	}

	/**
	 * Index hint.
	 *
	 * @param string $table Table.
	 * @param string $index Index.
	 * @return string
	 */
	public function indexHint( string $table, string $index ): string {
		return 'FORCE INDEX (' . $this->quoteIdentifier( $index ) . ')';
	}

	/**
	 * Single-bit test.
	 *
	 * @param string $column Column.
	 * @param int    $bit    Bit 0-63.
	 * @return string
	 */
	public function bit( string $column, int $bit ): string {
		return '(' . $this->quoteIdentifier( $column ) . ' & ' . ( 1 << $bit ) . ') <> 0';
	}

	/**
	 * Mask test.
	 *
	 * @param string $column Column.
	 * @param int    $mask   Mask.
	 * @param string $mode   any|all|none.
	 * @return string
	 * @throws \InvalidArgumentException When an argument is invalid.
	 */
	public function mask( string $column, int $mask, string $mode ): string {
		$col = $this->quoteIdentifier( $column );
		return match ( $mode ) {
			'any'   => "({$col} & {$mask}) <> 0",
			'all'   => "({$col} & {$mask}) = {$mask}",
			'none'  => "({$col} & {$mask}) = 0",
			default => throw new \InvalidArgumentException( 'Unknown mask mode.' ),
		};
	}

	/**
	 * Statement timeout optimizer hint (MySQL; MariaDB ignores the hint comment).
	 *
	 * @param int $ms Milliseconds.
	 * @return string
	 */
	public function statementTimeout( int $ms ): string {
		return '/*+ MAX_EXECUTION_TIME(' . max( 1, $ms ) . ') */';
	}
}
