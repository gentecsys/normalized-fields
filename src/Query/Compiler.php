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
use NF\Storage\FieldDef;
use NF\Storage\ObjectType;

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
	 * @param QuerySpec                                        $spec    Query.
	 * @param int                                              $pivotId Pivot set of the spec's type.
	 * @param array<string,array{name:string,table:DataTable}> $slots   Pivot::slots() of that set.
	 * @param array<string,FieldDef>                           $fields  Top-level fields by name, for conditions the pivot cannot answer (typed-table probes).
	 * @return CompiledQuery
	 * @throws UnsupportedQueryException When the pivot cannot answer the query alone.
	 */
	public function compile( QuerySpec $spec, int $pivotId, array $slots, array $fields = array() ): CompiledQuery {
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
		$probes     = array();
		foreach ( $spec->conditions as $c ) {
			$conditions[] = $c->field;
			if ( ! isset( self::CORE[ $c->field ] ) && ( ! isset( $byName[ $c->field ] ) || DataTable::Str === $byName[ $c->field ][1] ) && isset( $fields[ $c->field ] ) ) {
				$where[]  = $this->probe( $c, $fields[ $c->field ], $spec->type, $params );
				$probes[] = $c->field;
				continue;
			}
			[ $column, $kind ] = $this->resolve( $c->field, $byName );
			$sql               = $this->predicate( $d->quoteIdentifier( $column ), $c, $kind, $params );
			if ( null !== $sql ) {
				$where[] = $sql;
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
				'mode'       => array() === $probes ? 'pivot' : 'pivot+probe',
				'probes'     => $probes,
				'pivot_id'   => $pivotId,
				'conditions' => $conditions,
				'order'      => $names,
				'driver'     => 'pivot index range (pivot_id, status, ...)',
			)
		);
	}

	/**
	 * Predicate on one value expression, binding values in order.
	 *
	 * @param string            $expr   Quoted column.
	 * @param Condition         $c      Condition (null / not_null are handled by the caller for probes).
	 * @param string            $kind   int|dec|date|str.
	 * @param array<int,scalar> $params Parameters, appended to.
	 * @return string|null SQL, or null when the condition restricts nothing.
	 * @throws UnsupportedQueryException When the value does not fit.
	 */
	private function predicate( string $expr, Condition $c, string $kind, array &$params ): ?string {
		switch ( $c->op ) {
			case 'null':
				return "{$expr} IS NULL";
			case 'not_null':
				return "{$expr} IS NOT NULL";
			case 'between':
				$pair = is_array( $c->value ) ? array_values( $c->value ) : array();
				if ( 2 !== count( $pair ) ) {
					throw new UnsupportedQueryException( 'between needs two values.' );
				}
				$params[] = $this->value( $kind, $pair[0] );
				$params[] = $this->value( $kind, $pair[1] );
				return "{$expr} BETWEEN ? AND ?";
			case 'in':
			case 'not_in':
				$list = is_array( $c->value ) ? array_values( $c->value ) : array();
				if ( count( $list ) > self::MAX_LIST ) {
					throw new UnsupportedQueryException( 'List too long.' );
				}
				if ( array() === $list ) {
					// Empty IN matches nothing; empty NOT IN matches everything.
					return 'in' === $c->op ? '1 = 0' : null;
				}
				foreach ( $list as $v ) {
					$params[] = $this->value( $kind, $v );
				}
				return $expr . ( 'in' === $c->op ? ' IN (' : ' NOT IN (' ) . implode( ', ', array_fill( 0, count( $list ), '?' ) ) . ')';
			default:
				if ( is_array( $c->value ) || null === $c->value ) {
					throw new UnsupportedQueryException( 'Comparison needs a scalar value.' );
				}
				$params[] = $this->value( $kind, $c->value );
				return "{$expr} {$c->op} ?";
		}
	}

	/**
	 * Semi-join probe of a field's typed table, by primary key, for a field with no pivot slot.
	 *
	 * Single-valued: the object must hold a row that satisfies the predicate (so `!=` needs the field to exist,
	 * as WP_Query does). Multi-valued: `any` semantics for = in < <= > >= between; `!=` and `not_in` mean
	 * "no value matches" (NOT IN). `null` means the field has no row; `not_null` that it has one.
	 *
	 * @param Condition         $c      Condition.
	 * @param FieldDef          $f      Field.
	 * @param ObjectType        $type   Object type.
	 * @param array<int,scalar> $params Parameters, appended to.
	 * @return string
	 * @throws UnsupportedQueryException When the field's table cannot be probed.
	 */
	private function probe( Condition $c, FieldDef $f, ObjectType $type, array &$params ): string {
		$d    = $this->db->dialect();
		$kind = match ( $f->table ) {
			DataTable::Int  => 'int',
			DataTable::Dec  => 'dec',
			DataTable::Date => 'date',
			DataTable::Str  => 'str',
			default         => throw new UnsupportedQueryException( 'Field ' . esc_html( $f->name ) . ' cannot be filtered.' ),
		};
		$oid  = $d->quoteIdentifier( 'object_id' );
		$val  = $d->quoteIdentifier( 'value' );
		$base = $d->quoteIdentifier( 'object_type' ) . ' = ? AND ' . $d->quoteIdentifier( 'field_id' ) . ' = ?';
		$tbl  = $this->db->table( 'nf_' . $f->table->value );

		$negate = false;
		if ( 'null' === $c->op || 'not_null' === $c->op ) {
			$negate = 'null' === $c->op;
			$params = array_merge( $params, array( $type->value, $f->id ) );
			return "{$oid} " . ( $negate ? 'NOT IN' : 'IN' ) . " (SELECT {$oid} FROM {$tbl} WHERE {$base})";
		}

		$cond = $c;
		if ( $f->multi && ( '!=' === $c->op || 'not_in' === $c->op ) ) {
			// None of the values may match: negate the positive form.
			$negate = true;
			$cond   = new Condition( $c->field, '!=' === $c->op ? '=' : 'in', $c->value );
		}
		$inner = array( $type->value, $f->id );
		$sql   = $this->predicate( $val, $cond, $kind, $inner );
		if ( null === $sql ) {
			// Empty NOT IN: restricts nothing.
			return '1 = 1';
		}
		if ( 'str' === $kind && in_array( $cond->op, array( '=', '!=' ), true ) && is_scalar( $cond->value ) && strlen( (string) $cond->value ) > 191 ) {
			throw new UnsupportedQueryException( 'String longer than the 191-character index prefix.' );
		}
		$params = array_merge( $params, $inner );
		return "{$oid} " . ( $negate ? 'NOT IN' : 'IN' ) . " (SELECT {$oid} FROM {$tbl} WHERE {$base} AND {$sql})";
	}

	/**
	 * Column and value kind for a field name.
	 *
	 * @param string                                    $field  Field or core column name.
	 * @param array<string,array{0:string,1:DataTable}> $byName Slotted fields.
	 * @return array{0:string,1:string} Column, kind (int|dec|date).
	 * @throws UnsupportedQueryException When the field is not in the pivot.
	 */
	private function resolve( string $field, array $byName ): array {
		if ( isset( self::CORE[ $field ] ) ) {
			return self::CORE[ $field ];
		}
		if ( ! isset( $byName[ $field ] ) ) {
			throw new UnsupportedQueryException( 'Field ' . esc_html( $field ) . ' has no pivot slot.' );
		}
		[ $slot, $table ] = $byName[ $field ];
		$kind             = match ( $table ) {
			DataTable::Int  => 'int',
			DataTable::Dec  => 'dec',
			DataTable::Date => 'date',
			default         => throw new UnsupportedQueryException( 'Field ' . esc_html( $field ) . ' is not a numeric or date slot.' ),
		};
		return array( $slot, $kind );
	}

	/**
	 * Bound value in the column's type, converted explicitly (never relying on implicit casts).
	 *
	 * @param string $kind  int|dec|date|str.
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
			case 'str':
				return (string) $value;
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
