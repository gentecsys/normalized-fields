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
}
