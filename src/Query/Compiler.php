<?php
/**
 * Query compiler, pivot tier (WP 3.1a).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Query;

use NF\Backend\BackendInterface;
use NF\Pivot\Dictionary;
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
	 * Offset above which the page is read by a deferred join (design doc, "Deep pages stay cheap").
	 */
	public const DEFER_OFFSET = 1000;

	/**
	 * A condition this selective (share of the pivot set) may drive the scan through its slot index.
	 */
	private const DRIVER_SHARE = 0.05;

	/**
	 * Index per core sort column.
	 */
	private const CORE_INDEX = array(
		'published' => 'by_published',
		'modified'  => 'by_modified',
		'author_id' => 'by_author',
	);

	/**
	 * Constructor.
	 *
	 * @param BackendInterface $db         Backend.
	 * @param Dictionary|null  $dictionary Dictionary, for bitmask bits and row-slot strings (read only).
	 */
	public function __construct( private BackendInterface $db, private ?Dictionary $dictionary = null, private int $deferOffset = self::DEFER_OFFSET ) {}

	/**
	 * Compile.
	 *
	 * @param QuerySpec                                                                                 $spec     Query.
	 * @param int                                                                                       $pivotId  Pivot set of the spec's type.
	 * @param array<string,array{name:string,table:DataTable,id?:int}>                                  $slots    Pivot::slots() of that set (`id` is needed for bitmask slots).
	 * @param array<string,FieldDef>                                                                    $fields   Top-level fields by name, for conditions the pivot cannot answer (typed-table probes).
	 * @param array<string,array{id:int,slots:array<string,array{name:string,table:DataTable,id:int}>}> $rowSlots Row-pivot slots per Series name (series field id and its slots).
	 * @param array<string,array{rows:int,distinct:int}>                                                $stats    Field statistics by field name (nf_fields row_count, distinct_count); `*` holds the pivot set's object count.
	 * @return CompiledQuery
	 * @throws UnsupportedQueryException When the pivot cannot answer the query alone.
	 */
	public function compile( QuerySpec $spec, int $pivotId, array $slots, array $fields = array(), array $rowSlots = array(), array $stats = array() ): CompiledQuery {
		$d = $this->db->dialect();

		$byName = array();
		$masked = array();
		foreach ( $slots as $slot => $f ) {
			$byName[ $f['name'] ] = array( $slot, $f['table'] );
			if ( str_starts_with( $slot, 'm' ) ) {
				$masked[ $f['name'] ] = array( $slot, (int) ( $f['id'] ?? 0 ) );
				unset( $byName[ $f['name'] ] );
			}
		}

		$statuses = array() === $spec->statuses ? array( \NF\Storage\Status::Publish ) : $spec->statuses;
		$base     = $d->quoteIdentifier( 'pivot_id' ) . ' = ? AND ' . $d->quoteIdentifier( 'status' ) . ' IN (' . implode( ', ', array_fill( 0, count( $statuses ), '?' ) ) . ')';
		$baseP    = array( $pivotId );
		foreach ( $statuses as $st ) {
			$baseP[] = $st->value;
		}

		// Each clause carries its own parameters and an estimated share of the set, so they can be reordered.
		$clauses    = array();
		$conditions = array();
		$probes     = array();
		foreach ( $spec->conditions as $c ) {
			$conditions[] = $c->field;
			$p            = array();
			if ( ! isset( self::CORE[ $c->field ] ) && ( ! isset( $byName[ $c->field ] ) || DataTable::Str === $byName[ $c->field ][1] ) && isset( $fields[ $c->field ] ) ) {
				$sql      = $this->probe( $c, $fields[ $c->field ], $spec->type, $p );
				$probes[] = $c->field;
				$col      = null;
			} else {
				[ $column, $kind ] = $this->resolve( $c->field, $byName );
				$sql               = $this->predicate( $d->quoteIdentifier( $column ), $c, $kind, $p );
				$col               = $column;
			}
			if ( null !== $sql ) {
				$clauses[] = array( $sql, $p, $this->share( $c->op, $c->value, $stats[ $c->field ] ?? null ), $col, $c->field );
			}
		}

		$from = $this->db->table( 'nf_pivot' );
		foreach ( $spec->masks as $m ) {
			$conditions[] = $m->field;
			$p            = array();
			$sql          = $this->mask( $m, $masked, $fields, $spec->type, $p );
			if ( null !== $sql ) {
				$clauses[] = array( $sql, $p, 0.2, null, $m->field );
			}
			if ( ! isset( $masked[ $m->field ] ) ) {
				$probes[] = $m->field;
			}
		}
		foreach ( $spec->rows as $r ) {
			$conditions[] = $r->series;
			$p            = array();
			$clauses[]    = array( $this->rowExists( $r, $rowSlots, $pivotId, $from, $p ), $p, 0.2, null, $r->series );
		}

		// With statistics: most selective clause first; ties keep the caller's order. Without: caller's order.
		$indexed = array_keys( $clauses );
		if ( array() !== $stats ) {
			usort( $indexed, static fn( int $a, int $b ): int => array( $clauses[ $a ][2], $a ) <=> array( $clauses[ $b ][2], $b ) );
		}
		$where  = array( $base );
		$params = $baseP;
		$share  = 1.0;
		foreach ( $indexed as $i ) {
			$where[] = $clauses[ $i ][0];
			$params  = array_merge( $params, $clauses[ $i ][1] );
			$share  *= $clauses[ $i ][2];
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

		$whereS = implode( ' AND ', $where );
		$order  = implode( ', ', $parts );

		// Driver: a selective slot condition, else the sort column's index (the scan stops at the LIMIT).
		$driver = 'pivot index range (pivot_id, status, ...)';
		$hint   = '';
		$total  = isset( $stats['*'] ) ? max( 0, $stats['*']['rows'] ) : null;
		foreach ( $indexed as $i ) {
			[ , , $frac, $col, $name ] = $clauses[ $i ];
			if ( null !== $col && $frac <= self::DRIVER_SHARE && 1 === preg_match( '/^[intsn]\d$/', $col ) ) {
				$hint   = $d->indexHint( $from, $col );
				$driver = $col . ' (' . $name . ')';
				break;
			}
		}
		if ( '' === $hint && $spec->limit > 0 && 1 === count( $orders ) ) {
			[ $sortCol ] = $this->resolve( $orders[0][0], $byName );
			$index       = self::CORE_INDEX[ $sortCol ] ?? ( 1 === preg_match( '/^[intsn]\d$/', $sortCol ) ? $sortCol : null );
			if ( null !== $index && $share > self::DRIVER_SHARE ) {
				$hint   = $d->indexHint( $from, $index );
				$driver = $index . ' (sort: ' . $orders[0][0] . ')';
			}
		}
		$fromH = trim( $from . ' ' . $hint );

		$deferred = $spec->limit > 0 && $spec->offset > $this->deferOffset;
		$oid      = $d->quoteIdentifier( 'object_id' );
		$pid      = $d->quoteIdentifier( 'pivot_id' );
		if ( $deferred ) {
			// Read the page's keys from the index alone, then fetch in the same order.
			$inner = "SELECT {$pid} AS kp, {$oid} AS ko FROM {$fromH} WHERE {$whereS} ORDER BY {$order} " . $d->limit( $spec->limit, $spec->offset );
			$sql   = "SELECT {$oid} FROM {$from} INNER JOIN ({$inner}) AS x ON {$pid} = x.kp AND {$oid} = x.ko ORDER BY {$order}";
		} else {
			$sql = "SELECT {$oid} FROM {$fromH} WHERE {$whereS} ORDER BY {$order}";
			if ( $spec->limit > 0 ) {
				$sql .= ' ' . $d->limit( $spec->limit, $spec->offset );
			}
		}

		return new CompiledQuery(
			$sql,
			$params,
			"SELECT COUNT(*) AS c FROM {$from} WHERE {$whereS}",
			$params,
			array(
				'mode'           => array() === $probes ? 'pivot' : 'pivot+probe',
				'rows'           => array_map( static fn( RowCondition $r ): string => $r->series, $spec->rows ),
				'probes'         => $probes,
				'pivot_id'       => $pivotId,
				'conditions'     => $conditions,
				'order'          => $names,
				'driver'         => $driver,
				'clause_order'   => array_map( static fn( int $i ): string => $clauses[ $i ][4], $indexed ),
				'estimated_rows' => null === $total ? null : (int) round( $total * $share ),
				'deferred_join'  => $deferred,
				'joins'          => $deferred ? 1 : 0,
			)
		);
	}

	/**
	 * Estimated share of the pivot set a condition keeps (0-1), from the field's statistics.
	 *
	 * @param string                                  $op    Operator.
	 * @param mixed                                   $value Value.
	 * @param array{rows:int,distinct:int}|null       $stat  Statistics or null.
	 * @return float
	 */
	private function share( string $op, mixed $value, ?array $stat ): float {
		$distinct = null !== $stat && $stat['distinct'] > 0 ? (float) $stat['distinct'] : 10.0;
		$count    = is_array( $value ) ? max( 1, count( $value ) ) : 1;
		return match ( $op ) {
			'='        => min( 1.0, 1.0 / $distinct ),
			'in'       => min( 1.0, $count / $distinct ),
			'between'  => 0.25,
			'<', '<=', '>', '>=' => 0.33,
			'null'     => 0.1,
			'not_in', '!=', 'not_null' => 0.9,
			default    => 0.5,
		};
	}

	/**
	 * Any-of / all-of / none-of on a multi-valued field: one bitwise test on a bitmask slot, else typed-table probes.
	 *
	 * @param MaskCondition                       $m      Condition.
	 * @param array<string,array{0:string,1:int}> $masked Bitmask slots by field name: slot and field id.
	 * @param array<string,FieldDef>              $fields Fields by name.
	 * @param ObjectType                          $type   Object type.
	 * @param array<int,scalar>                   $params Parameters, appended to.
	 * @return string|null SQL, or null when the condition restricts nothing.
	 * @throws UnsupportedQueryException When the field cannot be tested.
	 */
	private function mask( MaskCondition $m, array $masked, array $fields, ObjectType $type, array &$params ): ?string {
		$values = array_values( array_unique( array_map( 'strval', $m->values ) ) );
		if ( count( $values ) > self::MAX_LIST ) {
			throw new UnsupportedQueryException( 'List too long.' );
		}
		if ( array() === $values ) {
			// Any of nothing matches nothing; all of / none of nothing restrict nothing.
			return 'any' === $m->mode ? '1 = 0' : null;
		}
		if ( isset( $masked[ $m->field ] ) ) {
			[ $slot, $fieldId ] = $masked[ $m->field ];
			if ( null === $this->dictionary || $fieldId <= 0 ) {
				throw new UnsupportedQueryException( 'Bitmask conditions need the dictionary and the field id.' );
			}
			$bits = array();
			foreach ( $values as $v ) {
				$bit = $this->dictionary->findBit( $fieldId, $v );
				if ( null === $bit ) {
					if ( 'all' === $m->mode ) {
						return '1 = 0'; // A value that has no bit is on no object.
					}
					continue;
				}
				$bits[ $bit ] = true;
			}
			if ( array() === $bits ) {
				return 'any' === $m->mode ? '1 = 0' : null;
			}
			$mask = 0;
			foreach ( array_keys( $bits ) as $bit ) {
				$mask |= 1 << $bit;
			}
			if ( isset( $bits[ Dictionary::MAX_BIT ] ) && 'all' === $m->mode ) {
				throw new UnsupportedQueryException( 'An all-of test on bit 63 is not supported.' );
			}
			return $this->db->dialect()->mask( $slot, $mask, $m->mode );
		}
		if ( ! isset( $fields[ $m->field ] ) || ! $fields[ $m->field ]->multi ) {
			throw new UnsupportedQueryException( 'Field ' . esc_html( $m->field ) . ' is not a multi-valued field.' );
		}
		$f = $fields[ $m->field ];
		if ( 'all' === $m->mode ) {
			$parts = array();
			foreach ( $values as $v ) {
				$parts[] = $this->probe( new Condition( $m->field, '=', $v ), $f, $type, $params );
			}
			return implode( ' AND ', $parts );
		}
		return $this->probe( new Condition( $m->field, 'any' === $m->mode ? 'in' : 'not_in', $values ), $f, $type, $params );
	}

	/**
	 * `EXISTS` on nf_row_pivot for one Series: every condition holds on the same row.
	 *
	 * @param RowCondition                                                                              $r        Condition.
	 * @param array<string,array{id:int,slots:array<string,array{name:string,table:DataTable,id:int}>}> $rowSlots Row slots per Series name.
	 * @param int                                                                                       $pivotId  Pivot set.
	 * @param string                                                                                    $outer    Outer (object pivot) table name.
	 * @param array<int,scalar>                                                                         $params   Parameters, appended to.
	 * @return string
	 * @throws UnsupportedQueryException When a sub-field has no row slot.
	 */
	private function rowExists( RowCondition $r, array $rowSlots, int $pivotId, string $outer, array &$params ): string {
		if ( ! isset( $rowSlots[ $r->series ] ) ) {
			throw new UnsupportedQueryException( 'Series ' . esc_html( $r->series ) . ' has no row-pivot slots.' );
		}
		$d      = $this->db->dialect();
		$series = $rowSlots[ $r->series ];
		$byName = array();
		foreach ( $series['slots'] as $slot => $f ) {
			$byName[ $f['name'] ] = array( $slot, $f['table'], $f['id'] );
		}
		$oid   = $d->quoteIdentifier( 'object_id' );
		$inner = array( $pivotId, $series['id'] );
		$parts = array( $d->quoteIdentifier( 'pivot_id' ) . ' = ?', $d->quoteIdentifier( 'field_id' ) . ' = ?', "{$oid} = {$outer}.{$oid}" );
		foreach ( $r->conditions as $c ) {
			if ( ! isset( $byName[ $c->field ] ) ) {
				throw new UnsupportedQueryException( 'Sub-field ' . esc_html( $c->field ) . ' has no row slot.' );
			}
			[ $slot, $table, $fieldId ] = $byName[ $c->field ];
			$col                        = $d->quoteIdentifier( $slot );
			$kind                       = match ( $table ) {
				DataTable::Int  => 'int',
				DataTable::Dec  => 'dec',
				DataTable::Date => 'date',
				DataTable::Str  => 'dict',
				default         => throw new UnsupportedQueryException( 'Sub-field ' . esc_html( $c->field ) . ' cannot be filtered.' ),
			};
			$sql = 'dict' === $kind ? $this->dictPredicate( $col, $c, $fieldId, $inner ) : $this->predicate( $col, $c, $kind, $inner );
			if ( null !== $sql ) {
				$parts[] = $sql;
			}
		}
		$params = array_merge( $params, $inner );
		return 'EXISTS (SELECT 1 FROM ' . $this->db->table( 'nf_row_pivot' ) . ' WHERE ' . implode( ' AND ', $parts ) . ')';
	}

	/**
	 * Equality-style predicate on a dictionary slot: values become dictionary ids (read only).
	 *
	 * @param string            $col     Quoted column.
	 * @param Condition         $c       Condition.
	 * @param int               $fieldId Field id (dictionary scope).
	 * @param array<int,scalar> $params  Parameters, appended to.
	 * @return string|null
	 * @throws UnsupportedQueryException When the operator is not an equality test.
	 */
	private function dictPredicate( string $col, Condition $c, int $fieldId, array &$params ): ?string {
		if ( ! in_array( $c->op, array( '=', '!=', 'in', 'not_in', 'null', 'not_null' ), true ) ) {
			throw new UnsupportedQueryException( 'Only equality is supported on string sub-fields.' );
		}
		if ( 'null' === $c->op || 'not_null' === $c->op ) {
			return $this->predicate( $col, $c, 'int', $params );
		}
		if ( null === $this->dictionary ) {
			throw new UnsupportedQueryException( 'String conditions need the dictionary.' );
		}
		$list = is_array( $c->value ) ? array_values( $c->value ) : array( $c->value );
		if ( count( $list ) > self::MAX_LIST ) {
			throw new UnsupportedQueryException( 'List too long.' );
		}
		$ids = array();
		foreach ( $list as $v ) {
			if ( ! is_scalar( $v ) ) {
				throw new UnsupportedQueryException( 'Value must be scalar.' );
			}
			$id = Dictionary::fits( (string) $v ) ? $this->dictionary->find( $fieldId, (string) $v ) : null;
			if ( null !== $id ) {
				$ids[] = $id;
			}
		}
		$positive = in_array( $c->op, array( '=', 'in' ), true );
		if ( array() === $ids ) {
			// Unknown values match no row; "not" them and every row that has a value matches.
			return $positive ? '1 = 0' : "{$col} IS NOT NULL";
		}
		return $this->predicate( $col, new Condition( $c->field, $positive ? 'in' : 'not_in', $ids ), 'int', $params );
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
		if ( 0 !== $f->parentId ) {
			throw new UnsupportedQueryException( 'Sub-fields are queried with whereRow (WP 3.1c).' );
		}
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
		if ( 'str' === $kind ) {
			if ( ! in_array( $cond->op, array( '=', '!=', 'in', 'not_in' ), true ) ) {
				throw new UnsupportedQueryException( 'Only equality is supported on string fields.' );
			}
			foreach ( is_array( $cond->value ) ? $cond->value : array( $cond->value ) as $v ) {
				if ( is_scalar( $v ) && mb_strlen( (string) $v ) > 191 ) {
					throw new UnsupportedQueryException( 'String longer than the 191-character index prefix.' );
				}
			}
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
