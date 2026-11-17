<?php
/**
 * Query compiler, pivot tier (WP 3.1a).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Query;

use NF\Backend\BackendInterface;
use NF\Storage\DataTable;

defined( 'ABSPATH' ) || exit;

/**
 * Compiles a QuerySpec to one statement on nf_pivot. Handles conditions and sorting on slotted single-valued
 * fields and the mirrored core columns. Anything else throws UnsupportedQueryException (typed-table probes
 * are WP 3.1b, rows and bitmasks 3.1c). SQL comes only from the dialect; values are always bound.
 */
final class Compiler {

	/**
	 * Core columns mirrored on the pivot: query name => column and kind.
	 */
	private const CORE = array(
		'date'       => array( 'published', 'date' ),
		'modified'   => array( 'modified', 'date' ),
		'author'     => array( 'author_id', 'int' ),
		'parent'     => array( 'parent_id', 'int' ),
		'menu_order' => array( 'menu_order', 'int' ),
		'ID'         => array( 'object_id', 'int' ),
	);

	private const MAX_LIST = 1000;

	/**
	 * Constructor.
	 *
	 * @param BackendInterface $db Backend.
	 */
	public function __construct( private BackendInterface $db ) {}

	/**
	 * Compile.
	 *
	 * @param QuerySpec                                                    $spec    Query.
	 * @param int                                                          $pivotId Pivot set of the spec's type.
	 * @param array<string,array{name:string,table:DataTable}>             $slots   Pivot::slots() of that set.
	 * @return CompiledQuery
	 * @throws UnsupportedQueryException When the pivot cannot answer the query alone.
	 */
	public function compile( QuerySpec $spec, int $pivotId, array $slots ): CompiledQuery {
		$d = $this->db->dialect();

		$byName = array();
		foreach ( $slots as $slot => $f ) {
			$byName[ $f['name'] ] = array( $slot, $f['table'] );
		}

		$statuses = array() === $spec->statuses ? array( \NF\Storage\Status::Publish ) : $spec->statuses;
		$where    = array( $d->quoteIdentifier( 'pivot_id' ) . ' = ?', $d->quoteIdentifier( 'status' ) . ' IN (' . implode( ', ', array_fill( 0, count( $statuses ), '?' ) ) . ')' );
		$params   = array( $pivotId );
		foreach ( $statuses as $st ) {
			$params[] = $st->value;
		}

		$conditions = array();
		foreach ( $spec->conditions as $c ) {
			[ $column, $kind ] = $this->resolve( $c->field, $byName );
			$expr              = $d->quoteIdentifier( $column );
			$conditions[]      = $c->field;
			switch ( $c->op ) {
				case 'null':
					$where[] = "{$expr} IS NULL";
					break;
				case 'not_null':
					$where[] = "{$expr} IS NOT NULL";
					break;
				case 'between':
					$pair = is_array( $c->value ) ? array_values( $c->value ) : array();
					if ( 2 !== count( $pair ) ) {
						throw new UnsupportedQueryException( 'between needs two values.' );
					}
					$where[]  = "{$expr} BETWEEN ? AND ?";
					$params[] = $this->value( $kind, $pair[0] );
					$params[] = $this->value( $kind, $pair[1] );
					break;
				case 'in':
				case 'not_in':
					$list = is_array( $c->value ) ? array_values( $c->value ) : array();
					if ( count( $list ) > self::MAX_LIST ) {
						throw new UnsupportedQueryException( 'List too long.' );
					}
					if ( array() === $list ) {
						// Empty IN matches nothing; empty NOT IN matches everything.
						if ( 'in' === $c->op ) {
							$where[] = '1 = 0';
						}
						break;
					}
					$where[] = $expr . ( 'in' === $c->op ? ' IN (' : ' NOT IN (' ) . implode( ', ', array_fill( 0, count( $list ), '?' ) ) . ')';
					foreach ( $list as $v ) {
						$params[] = $this->value( $kind, $v );
					}
					break;
				default:
					if ( is_array( $c->value ) || null === $c->value ) {
						throw new UnsupportedQueryException( 'Comparison needs a scalar value.' );
					}
					$where[]  = "{$expr} {$c->op} ?";
					$params[] = $this->value( $kind, $c->value );
			}
		}

		if ( $spec->offset > 0 && $spec->limit <= 0 ) {
			throw new UnsupportedQueryException( 'An offset needs a limit.' );
		}
		$orders = array() === $spec->orders ? array( array( 'date', 'DESC' ) ) : $spec->orders;
		$parts  = array();
		$last   = 'ASC';
		$names  = array();
		foreach ( $orders as [ $field, $dir ] ) {
			[ $column ] = $this->resolve( $field, $byName );
			$dir        = 'DESC' === strtoupper( $dir ) ? 'DESC' : 'ASC';
			$last       = $dir;
			$names[]    = $field;
			$parts[]    = 'object_id' === $column ? $d->quoteIdentifier( $column ) . " {$dir}" : $d->nullsOrder( $column, $dir, 'ASC' === $dir ? 'FIRST' : 'LAST' );
		}
		// Stable tie-break, as core does with ID.
		if ( ! in_array( 'ID', $names, true ) ) {
			$parts[] = $d->quoteIdentifier( 'object_id' ) . " {$last}";
		}

		$from   = $this->db->table( 'nf_pivot' );
		$whereS = implode( ' AND ', $where );
		$sql    = 'SELECT ' . $d->quoteIdentifier( 'object_id' ) . " FROM {$from} WHERE {$whereS} ORDER BY " . implode( ', ', $parts );
		if ( $spec->limit > 0 ) {
			$sql .= ' ' . $d->limit( $spec->limit, $spec->offset );
		}

		return new CompiledQuery(
			$sql,
			$params,
			"SELECT COUNT(*) AS c FROM {$from} WHERE {$whereS}",
			$params,
			array(
				'mode'       => 'pivot',
				'pivot_id'   => $pivotId,
				'conditions' => $conditions,
				'order'      => $names,
				'driver'     => 'pivot index range (pivot_id, status, ...)',
			)
		);
	}

	/**
	 * Column and value kind for a field name.
	 *
	 * @param string                                           $field  Field or core column name.
	 * @param array<string,array{0:string,1:DataTable}>        $byName Slotted fields.
	 * @return array{0:string,1:string} Column, kind (int|dec|date).
	 * @throws UnsupportedQueryException When the field is not in the pivot.
	 */
	private function resolve( string $field, array $byName ): array {
		if ( isset( self::CORE[ $field ] ) ) {
			return self::CORE[ $field ];
		}
		if ( ! isset( $byName[ $field ] ) ) {
			throw new UnsupportedQueryException( "Field {$field} has no pivot slot." );
		}
		[ $slot, $table ] = $byName[ $field ];
		$kind             = match ( $table ) {
			DataTable::Int  => 'int',
			DataTable::Dec  => 'dec',
			DataTable::Date => 'date',
			default         => throw new UnsupportedQueryException( "Field {$field} is not a numeric or date slot." ),
		};
		return array( $slot, $kind );
	}

	/**
	 * Bound value in the column's type, converted explicitly (never relying on implicit casts).
	 *
	 * @param string $kind  int|dec|date.
	 * @param mixed  $value Raw value.
	 * @return int|string
	 * @throws UnsupportedQueryException When the value does not fit the type.
	 */
	private function value( string $kind, mixed $value ): int|string {
		if ( is_bool( $value ) ) {
			$value = (int) $value;
		}
		if ( ! is_scalar( $value ) ) {
			throw new UnsupportedQueryException( 'Value must be scalar.' );
		}
		$raw = trim( (string) $value );
		switch ( $kind ) {
			case 'int':
				if ( 1 !== preg_match( '/^-?\d+$/', $raw ) ) {
					throw new UnsupportedQueryException( 'Not an integer.' );
				}
				if ( strlen( ltrim( $raw, '-' ) ) > 18 ) {
					throw new UnsupportedQueryException( 'Integer out of range.' );
				}
				return (int) $raw;
			case 'dec':
				if ( 1 !== preg_match( '/^-?\d+(\.\d+)?$/', $raw ) ) {
					throw new UnsupportedQueryException( 'Not a number.' );
				}
				return $raw;
			default:
				// Date: Y-m-d, Y-m-d H:i[:s], or ACF's Ymd.
				if ( 1 === preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $raw, $m ) ) {
					return "{$m[1]}-{$m[2]}-{$m[3]} 00:00:00";
				}
				if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
					return $raw . ' 00:00:00';
				}
				if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $raw ) ) {
					$raw = str_replace( 'T', ' ', $raw );
					return strlen( $raw ) === 16 ? $raw . ':00' : $raw;
				}
				throw new UnsupportedQueryException( 'Not a date.' );
		}
	}
}
