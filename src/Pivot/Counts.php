<?php
/**
 * Count rollup (nf_count): exact per-day counts per pivot set, status, dimension and value.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Pivot;

use NF\Backend\BackendInterface;

/**
 * Maintained by diffing a pivot row before and after each write, in the caller's transaction.
 *
 * Dimensions: `*` (total), every `s` slot (dictionary id), every `m` slot (one cell per set bit, `val` = bit number) and
 * the integer slots the `nf/count/int_dimensions` filter lists (booleans). Numbers, dates and relationships are not counted.
 * The day is the date of `published`; objects without one use {@see self::NO_DAY}.
 */
final class Counts {

	/**
	 * Day used when an object has no published date.
	 */
	public const NO_DAY = '1000-01-01';

	/**
	 * Constructor.
	 *
	 * @param BackendInterface $db Backend.
	 */
	public function __construct( private BackendInterface $db ) {}

	/**
	 * Slots that get a rollup dimension.
	 *
	 * @param string[] $slots All slot names of the set.
	 * @return string[]
	 */
	public function dimensions( array $slots ): array {
		$ints = (array) apply_filters( 'nf/count/int_dimensions', array() ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$out  = array();
		foreach ( $slots as $slot ) {
			$slot = (string) $slot;
			if ( '' !== $slot && ( 's' === $slot[0] || 'm' === $slot[0] || in_array( $slot, $ints, true ) ) ) {
				$out[] = $slot;
			}
		}
		return $out;
	}

	/**
	 * Cells of one pivot row: "status|dim|val|day" => 1.
	 *
	 * @param array<string,mixed> $row  Pivot row (status, published and slot columns).
	 * @param string[]            $dims Dimension slots.
	 * @return array<string,int>
	 */
	public function cells( array $row, array $dims ): array {
		$status = (int) $row['status'];
		$day    = isset( $row['published'] ) && is_string( $row['published'] ) && strlen( $row['published'] ) >= 10 ? substr( $row['published'], 0, 10 ) : self::NO_DAY;
		$cells  = array( "{$status}|*|0|{$day}" => 1 );
		foreach ( $dims as $dim ) {
			$v = $row[ $dim ] ?? null;
			if ( null === $v ) {
				continue;
			}
			if ( 'm' === $dim[0] ) {
				$mask = (string) $v;
				for ( $bit = 0; $bit < 64; $bit++ ) {
					if ( $this->bitSet( $mask, $bit ) ) {
						$cells[ "{$status}|{$dim}|{$bit}|{$day}" ] = 1;
					}
				}
				continue;
			}
			$cells[ "{$status}|{$dim}|" . (int) $v . "|{$day}" ] = 1;
		}
		return $cells;
	}

	/**
	 * Whether a bit of an unsigned decimal string mask is set (exact for the full 64 bits).
	 *
	 * @param string $mask Unsigned decimal.
	 * @param int    $bit  Bit 0-63.
	 * @return bool
	 */
	private function bitSet( string $mask, int $bit ): bool {
		if ( '' === $mask || ! ctype_digit( $mask ) ) {
			return false;
		}
		// Halve the decimal string $bit times; the parity of the result is the bit.
		$digits = array_map( 'intval', str_split( $mask ) );
		for ( $i = 0; $i < $bit; $i++ ) {
			$carry = 0;
			foreach ( $digits as $k => $dgt ) {
				$cur          = $carry * 10 + $dgt;
				$digits[ $k ] = intdiv( $cur, 2 );
				$carry        = $cur % 2;
			}
		}
		return 1 === end( $digits ) % 2;
	}

	/**
	 * Apply the difference between an old and a new pivot row (either may be null: insert or delete).
	 *
	 * @param int                      $pivotId   Pivot set id.
	 * @param array<string,mixed>|null $old       Row before, or null.
	 * @param array<string,mixed>|null $after_row Row after, or null.
	 * @param string[]                 $dims      Dimension slots.
	 * @return void
	 */
	public function apply( int $pivotId, ?array $old, ?array $after_row, array $dims ): void {
		$before = null === $old ? array() : $this->cells( $old, $dims );
		$after  = null === $after_row ? array() : $this->cells( $after_row, $dims );
		foreach ( array_diff_key( $before, $after ) as $cell => $_ ) {
			$this->add( $pivotId, $cell, -1 );
		}
		foreach ( array_diff_key( $after, $before ) as $cell => $_ ) {
			$this->add( $pivotId, $cell, 1 );
		}
	}

	/**
	 * Add to one cell, creating it when missing and deleting it at zero.
	 *
	 * @param int    $pivotId Pivot set id.
	 * @param string $cell    "status|dim|val|day".
	 * @param int    $delta   +1 or -1.
	 * @return void
	 */
	private function add( int $pivotId, string $cell, int $delta ): void {
		$parts = explode( '|', $cell );
		$t     = $this->db->table( 'nf_count' );
		$where = 'pivot_id = ? AND status = ? AND dim = ? AND val = ? AND day = ?';
		$key   = array( $pivotId, (int) $parts[0], $parts[1], (int) $parts[2], $parts[3] );
		if ( $delta < 0 ) {
			$this->db->execute( "UPDATE {$t} SET n = n - 1 WHERE {$where} AND n > 0", $key );
			$this->db->execute( "DELETE FROM {$t} WHERE {$where} AND n = 0", $key );
			return;
		}
		if ( 0 === $this->db->execute( "UPDATE {$t} SET n = n + 1 WHERE {$where}", $key ) ) {
			$d = $this->db->dialect();
			$this->db->execute( $d->upsert( $t, array( 'pivot_id', 'status', 'dim', 'val', 'day' ), array( 'n' ) ), array_merge( $key, array( 1 ) ) );
		}
	}

	/**
	 * Rebuild a set from its pivot rows: one INSERT ... SELECT per dimension.
	 *
	 * @param int      $pivotId Pivot set id.
	 * @param string[] $dims    Dimension slots.
	 * @return void
	 */
	public function rebuild( int $pivotId, array $dims ): void {
		$t = $this->db->table( 'nf_count' );
		$p = $this->db->table( 'nf_pivot' );
		$d = $this->db->dialect();
		$this->db->transaction(
			function () use ( $pivotId, $dims, $t, $p, $d ): void {
				$this->db->execute( "DELETE FROM {$t} WHERE pivot_id = ?", array( $pivotId ) );
				$day = "COALESCE(DATE(published), '" . self::NO_DAY . "')";
				$this->db->execute( "INSERT INTO {$t} (pivot_id, status, dim, val, day, n) SELECT pivot_id, status, '*', 0, {$day}, COUNT(*) FROM {$p} WHERE pivot_id = ? GROUP BY status, {$day}", array( $pivotId ) );
				foreach ( $dims as $dim ) {
					$c = $d->quoteIdentifier( $dim );
					if ( 'm' === $dim[0] ) {
						for ( $bit = 0; $bit < 64; $bit++ ) {
							$this->db->execute( "INSERT INTO {$t} (pivot_id, status, dim, val, day, n) SELECT pivot_id, status, ?, ?, {$day}, COUNT(*) FROM {$p} WHERE pivot_id = ? AND " . $d->bit( $dim, $bit ) . " GROUP BY status, {$day} HAVING COUNT(*) > 0", array( $dim, $bit, $pivotId ) );
						}
						continue;
					}
					$this->db->execute( "INSERT INTO {$t} (pivot_id, status, dim, val, day, n) SELECT pivot_id, status, ?, {$c}, {$day}, COUNT(*) FROM {$p} WHERE pivot_id = ? AND {$c} IS NOT NULL GROUP BY status, {$c}, {$day}", array( $dim, $pivotId ) );
				}
			}
		);
	}

	/**
	 * Exact count from the rollup.
	 *
	 * @param int         $pivotId Pivot set id.
	 * @param int[]       $statuses Status codes.
	 * @param string      $dim     '*' or a slot name.
	 * @param int         $val     Value, or bit number.
	 * @param string|null $from    First day (Y-m-d) or null.
	 * @param string|null $to      Last day (Y-m-d) or null.
	 * @return int
	 */
	public function count( int $pivotId, array $statuses, string $dim = '*', int $val = 0, ?string $from = null, ?string $to = null ): int {
		if ( 0 === count( $statuses ) ) {
			return 0;
		}
		$t      = $this->db->table( 'nf_count' );
		$in     = implode( ', ', array_fill( 0, max( 1, count( $statuses ) ), '?' ) );
		$params = array_merge( array( $pivotId ), array_map( 'intval', $statuses ), array( $dim, $val ) );
		$sql    = "SELECT COALESCE(SUM(n), 0) AS c FROM {$t} WHERE pivot_id = ? AND status IN ({$in}) AND dim = ? AND val = ?";
		if ( null !== $from ) {
			$sql     .= ' AND day >= ?';
			$params[] = $from;
		}
		if ( null !== $to ) {
			$sql     .= ' AND day <= ?';
			$params[] = $to;
		}
		$rows = $this->db->select( $sql, $params );
		return (int) ( $rows[0]['c'] ?? 0 );
	}
}
