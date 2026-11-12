<?php
/**
 * Schema 5: DECIMAL(20,6) to DECIMAL(24,10).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Install\Migrations;

use NF\Backend\BackendInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Widens every decimal column to DECIMAL(24,10) (owner decision 2026-11-12), one ALTER per table.
 *
 * Runs before dbDelta, which would otherwise rebuild nf_pivot once for each of n1-n4 (on nf-dev, 100k pivot
 * rows: about 5.5 s per rebuild). Columns already at the new type and tables that don't exist yet are skipped,
 * so it is safe to run twice. MySQL only; the PostgreSQL backend creates its tables at the new precision.
 */
final class M005WidenDecimals implements BeforeSchemaInterface {

	/**
	 * Target column type.
	 */
	private const TYPE = 'DECIMAL(24,10)';

	/**
	 * Decimal columns per logical table, with their nullability.
	 */
	private const COLUMNS = array(
		'nf_dec'       => array( 'value' => 'NOT NULL' ),
		'nf_user_dec'  => array( 'value' => 'NOT NULL' ),
		'nf_pivot'     => array(
			'n1' => 'NULL',
			'n2' => 'NULL',
			'n3' => 'NULL',
			'n4' => 'NULL',
		),
		'nf_row_pivot' => array(
			'n1' => 'NULL',
			'n2' => 'NULL',
		),
	);

	/**
	 * Constructor.
	 *
	 * @param BackendInterface|null $db Backend; defaults to nf_db().
	 */
	public function __construct( private ?BackendInterface $db = null ) {}

	/**
	 * Version.
	 *
	 * @return int
	 */
	public function version(): int {
		return 5;
	}

	/**
	 * Widen the columns that are still narrower.
	 *
	 * @return void
	 */
	public function up(): void {
		$db = $this->db ?? nf_db();
		if ( 'mysql' !== $db->name() ) {
			return;
		}
		foreach ( self::COLUMNS as $logical => $columns ) {
			$table = $db->table( $logical );
			$rows  = $db->select(
				'SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
				array( $table )
			);
			$types = array();
			foreach ( $rows as $row ) {
				$types[ (string) $row['COLUMN_NAME'] ] = strtolower( (string) $row['COLUMN_TYPE'] );
			}
			$modify = array();
			foreach ( $columns as $column => $null ) {
				if ( isset( $types[ $column ] ) && strtolower( self::TYPE ) !== $types[ $column ] ) {
					$modify[] = 'MODIFY ' . $column . ' ' . self::TYPE . ' ' . $null;
				}
			}
			if ( array() !== $modify ) {
				// Table and column names come from the constants above, never from input.
				$db->execute( 'ALTER TABLE ' . $table . ' ' . implode( ', ', $modify ) );
			}
		}
	}
}
