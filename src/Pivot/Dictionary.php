<?php
/**
 * Dictionary: each distinct short string once per field, as a small integer.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Pivot;

use NF\Backend\BackendInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Access to nf_dict (design: "Pivot and lookup tables"). Entries are derived data and ids are never reused,
 * so a pivot s-slot stays valid until its row is rewritten. Only write paths call id(); query paths call find().
 */
final class Dictionary {

	/**
	 * Longest value the dictionary column holds, in characters.
	 */
	public const MAX_LENGTH = 191;

	/**
	 * Constructor.
	 *
	 * @param BackendInterface $db Backend.
	 */
	public function __construct( private BackendInterface $db ) {}

	/**
	 * Whether a value fits the dictionary column.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function fits( string $value ): bool {
		return mb_strlen( $value, 'UTF-8' ) <= self::MAX_LENGTH;
	}

	/**
	 * Dictionary id of a value, created when missing (write paths only).
	 *
	 * @param int    $fieldId Field id.
	 * @param string $value   Value of at most MAX_LENGTH characters.
	 * @return int
	 * @throws \InvalidArgumentException When the value is too long.
	 * @throws \RuntimeException When the insert failed.
	 */
	public function id( int $fieldId, string $value ): int {
		if ( ! self::fits( $value ) ) {
			throw new \InvalidArgumentException( 'Dictionary values are at most 191 characters.' );
		}
		$found = $this->find( $fieldId, $value );
		if ( null !== $found ) {
			return $found;
		}
		// Concurrent writers may both reach here; the unique key rejects the second insert, then we read the winner's id.
		try {
			$this->db->execute( 'INSERT INTO ' . $this->db->table( 'nf_dict' ) . ' (field_id, value) VALUES (?, ?)', array( $fieldId, $value ) );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}
		$id = $this->find( $fieldId, $value );
		if ( null === $id ) {
			throw new \RuntimeException( 'Dictionary insert failed.' );
		}
		return $id;
	}

	/**
	 * Dictionary id of a value, or null when it is not in the dictionary. Never writes.
	 *
	 * @param int    $fieldId Field id.
	 * @param string $value   Value.
	 * @return int|null
	 */
	public function find( int $fieldId, string $value ): ?int {
		$rows = $this->db->select( 'SELECT dict_id FROM ' . $this->db->table( 'nf_dict' ) . ' WHERE field_id = ? AND value = ?', array( $fieldId, $value ) );
		return $rows ? (int) $rows[0]['dict_id'] : null;
	}

	/**
	 * The value behind a dictionary id.
	 *
	 * @param int $dictId Dictionary id.
	 * @return string|null
	 */
	public function value( int $dictId ): ?string {
		$rows = $this->db->select( 'SELECT value FROM ' . $this->db->table( 'nf_dict' ) . ' WHERE dict_id = ?', array( $dictId ) );
		return $rows ? (string) $rows[0]['value'] : null;
	}

	/**
	 * Highest bit position a bitmask slot holds.
	 */
	public const MAX_BIT = 63;

	/**
	 * Bit position of a value in its field's bitmask, assigned when missing (write paths only).
	 * Bits are never reused. Returns null when the value is too long or all 64 bits of the field are taken.
	 *
	 * @param int    $fieldId Field id.
	 * @param string $value   Value.
	 * @return int|null
	 */
	public function bit( int $fieldId, string $value ): ?int {
		if ( ! self::fits( $value ) ) {
			return null;
		}
		$known = $this->findBit( $fieldId, $value );
		if ( null !== $known ) {
			return $known;
		}
		$t    = $this->db->table( 'nf_dict' );
		$used = array_map( 'intval', array_column( $this->db->select( "SELECT bit FROM {$t} WHERE field_id = ? AND bit IS NOT NULL", array( $fieldId ) ), 'bit' ) );
		if ( count( $used ) > self::MAX_BIT ) {
			return null;
		}
		$dictId = $this->id( $fieldId, $value );
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$existing = $this->findBit( $fieldId, $value );
			if ( null !== $existing ) {
				return $existing;
			}
			$used = array_map( 'intval', array_column( $this->db->select( "SELECT bit FROM {$t} WHERE field_id = ? AND bit IS NOT NULL", array( $fieldId ) ), 'bit' ) );
			$free = null;
			for ( $b = 0; $b <= self::MAX_BIT; $b++ ) {
				if ( ! in_array( $b, $used, true ) ) {
					$free = $b;
					break;
				}
			}
			if ( null === $free ) {
				return null;
			}
			// A concurrent writer may take the same bit; the unique key rejects one of us, then we look again.
			try {
				$this->db->execute( "UPDATE {$t} SET bit = ? WHERE dict_id = ? AND bit IS NULL", array( $free, $dictId ) );
			} catch ( \RuntimeException $e ) {
				unset( $e );
			}
		}
		return $this->findBit( $fieldId, $value );
	}

	/**
	 * Bit position of a value, or null when it has none. Never writes.
	 *
	 * @param int    $fieldId Field id.
	 * @param string $value   Value.
	 * @return int|null
	 */
	public function findBit( int $fieldId, string $value ): ?int {
		$rows = $this->db->select( 'SELECT bit FROM ' . $this->db->table( 'nf_dict' ) . ' WHERE field_id = ? AND value = ? AND bit IS NOT NULL', array( $fieldId, $value ) );
		return $rows ? (int) $rows[0]['bit'] : null;
	}
}
