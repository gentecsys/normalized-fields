<?php
/**
 * Facet counts: value => number of matching objects, for dictionary and bitmask slots.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Query;

use NF\Backend\BackendInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Unfiltered facets read the `nf_count` rollup (one grouped read); filtered ones group the matching pivot rows live.
 * Both give the same counts. Values are decoded through `nf_dict`; nothing here writes to a table.
 */
final class Facets {

	/**
	 * Ids per live GROUP BY statement.
	 */
	private const CHUNK = 1000;

	/**
	 * Constructor.
	 *
	 * @param BackendInterface $db Backend.
	 */
	public function __construct( private BackendInterface $db ) {}

	/**
	 * Counts per value from the rollup.
	 *
	 * @param int    $pivotId  Pivot set id.
	 * @param int[]  $statuses Status codes.
	 * @param string $slot     Slot name (`s…` or `m…`).
	 * @return array<int,int> Dictionary id (or bit number) => count, for counts above zero.
	 */
	public function fromRollup( int $pivotId, array $statuses, string $slot ): array {
		if ( array() === $statuses ) {
			return array();
		}
		$in   = implode( ', ', array_fill( 0, count( $statuses ), '?' ) );
		$rows = $this->db->select(
			'SELECT val, SUM(n) AS c FROM ' . $this->db->table( 'nf_count' ) . " WHERE pivot_id = ? AND status IN ({$in}) AND dim = ? GROUP BY val",
			array_merge( array( $pivotId ), array_map( 'intval', $statuses ), array( $slot ) )
		);
		$out  = array();
		foreach ( $rows as $r ) {
			if ( (int) $r['c'] > 0 ) {
				$out[ (int) $r['val'] ] = (int) $r['c'];
			}
		}
		return $out;
	}

	/**
	 * Counts per value by grouping the pivot rows of the given objects.
	 *
	 * @param int    $pivotId Pivot set id.
	 * @param int[]  $ids     Matching object ids.
	 * @param string $slot    Slot name (`s…` or `m…`).
	 * @return array<int,int> Dictionary id (or bit number) => count.
	 */
	public function fromIds( int $pivotId, array $ids, string $slot ): array {
		$col = $this->db->dialect()->quoteIdentifier( $slot );
		$out = array();
		foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
			$in   = implode( ', ', array_fill( 0, count( $chunk ), '?' ) );
			$rows = $this->db->select(
				"SELECT {$col} AS v, COUNT(*) AS c FROM " . $this->db->table( 'nf_pivot' ) . " WHERE pivot_id = ? AND object_id IN ({$in}) AND {$col} IS NOT NULL GROUP BY {$col}",
				array_merge( array( $pivotId ), $chunk )
			);
			foreach ( $rows as $r ) {
				if ( 'm' === $slot[0] ) {
					foreach ( $this->bits( (string) $r['v'] ) as $bit ) {
						$out[ $bit ] = ( $out[ $bit ] ?? 0 ) + (int) $r['c'];
					}
					continue;
				}
				$out[ (int) $r['v'] ] = ( $out[ (int) $r['v'] ] ?? 0 ) + (int) $r['c'];
			}
		}
		return $out;
	}

	/**
	 * Turn counts keyed by dictionary id or bit into labelled value rows, largest count first.
	 *
	 * @param int            $fieldId Field id of the slot.
	 * @param string         $slot    Slot name.
	 * @param array<int,int> $counts  From {@see self::fromRollup()} or {@see self::fromIds()}.
	 * @return array<int,array{value:string,count:int}>
	 */
	public function decode( int $fieldId, string $slot, array $counts ): array {
		if ( array() === $counts ) {
			return array();
		}
		$t   = $this->db->table( 'nf_dict' );
		$in  = implode( ', ', array_fill( 0, count( $counts ), '?' ) );
		$col = 'm' === $slot[0] ? 'bit' : 'dict_id';
		$map = array();
		foreach ( $this->db->select( "SELECT {$col} AS k, value FROM {$t} WHERE field_id = ? AND {$col} IN ({$in})", array_merge( array( $fieldId ), array_keys( $counts ) ) ) as $r ) {
			$map[ (int) $r['k'] ] = (string) $r['value'];
		}
		$out = array();
		foreach ( $counts as $key => $n ) {
			if ( isset( $map[ $key ] ) ) {
				$out[] = array(
					'value' => $map[ $key ],
					'count' => $n,
				);
			}
		}
		usort(
			$out,
			static fn( array $a, array $b ): int => array( $b['count'], $a['value'] ) <=> array( $a['count'], $b['value'] )
		);
		return $out;
	}

	/**
	 * Set bits of an unsigned 64-bit decimal string.
	 *
	 * @param string $mask Unsigned decimal.
	 * @return int[]
	 */
	private function bits( string $mask ): array {
		if ( '' === $mask || ! ctype_digit( $mask ) ) {
			return array();
		}
		$digits = array_map( 'intval', str_split( $mask ) );
		$out    = array();
		for ( $bit = 0; $bit < 64; $bit++ ) {
			if ( array() === array_filter( $digits ) ) {
				break;
			}
			if ( 1 === end( $digits ) % 2 ) {
				$out[] = $bit;
			}
			$carry = 0;
			foreach ( $digits as $k => $dgt ) {
				$cur          = $carry * 10 + $dgt;
				$digits[ $k ] = intdiv( $cur, 2 );
				$carry        = $cur % 2;
			}
		}
		return $out;
	}
}
