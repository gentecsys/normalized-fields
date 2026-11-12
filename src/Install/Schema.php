<?php
/**
 * Schema creation for the storage tables.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the Phase 1 tables from contracts/schema.sql (field map, rows, typed value tables) with dbDelta.
 * Pivot, dictionary and count tables are added by the pivot WPs. dbDelta is the sanctioned DDL path for MySQL.
 */
final class Schema {

	/**
	 * Create or update the tables for the current site.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;
		$pk      = 'object_type TINYINT UNSIGNED NOT NULL,
  object_id BIGINT UNSIGNED NOT NULL,
  field_id INT UNSIGNED NOT NULL,
  row_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,';
		$pkey    = 'PRIMARY KEY  (object_type, object_id, field_id, row_id, position)';

		$sql   = array();
		$sql[] = "CREATE TABLE {$p}nf_fields (
  field_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  field_key VARCHAR(64) NOT NULL,
  field_name VARCHAR(191) NOT NULL,
  parent_id INT UNSIGNED NOT NULL DEFAULT 0,
  data_table ENUM('int','dec','date','str','text','json','rows','none') NOT NULL,
  multi TINYINT(1) NOT NULL DEFAULT 0,
  row_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  distinct_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (field_id),
  UNIQUE KEY field_key (field_key),
  KEY by_name (field_name, parent_id)
) {$charset};";
		$sql[] = "CREATE TABLE {$p}nf_rows (
  row_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  object_type TINYINT UNSIGNED NOT NULL,
  object_id BIGINT UNSIGNED NOT NULL,
  field_id INT UNSIGNED NOT NULL,
  parent_row_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  row_order SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY  (row_id),
  KEY owner (object_type, object_id, field_id, parent_row_id, row_order)
) {$charset};";
		$typed = array(
			'int'  => array( 'BIGINT NOT NULL', true ),
			'dec'  => array( 'DECIMAL(20,6) NOT NULL', true ),
			'date' => array( 'DATETIME NOT NULL', true ),
			'str'  => array( 'VARCHAR(191) NOT NULL', true ),
			'text' => array( 'MEDIUMTEXT NOT NULL', false ),
			'json' => array( 'LONGTEXT NOT NULL', false ),
		);
		foreach ( $typed as $name => list( $col, $indexed ) ) {
			$key   = $indexed ? ",\n  KEY by_value (field_id, value, object_type, object_id)" : '';
			$sql[] = "CREATE TABLE {$p}nf_{$name} (
  {$pk}
  value {$col},
  {$pkey}{$key}
) {$charset};";
		}
		$sql[] = "CREATE TABLE {$p}nf_pivot_sets (
  pivot_id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  object_type TINYINT UNSIGNED NOT NULL,
  subtype VARCHAR(64) NOT NULL,
  write_version BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (pivot_id),
  UNIQUE KEY set_key (object_type, subtype)
) {$charset};";
		$sql[] = "CREATE TABLE {$p}nf_pivot_slots (
  pivot_id SMALLINT UNSIGNED NOT NULL,
  repeater_id INT UNSIGNED NOT NULL DEFAULT 0,
  slot CHAR(3) NOT NULL,
  field_id INT UNSIGNED NOT NULL,
  rollup ENUM('none','min','max','count') NOT NULL DEFAULT 'none',
  PRIMARY KEY  (pivot_id, repeater_id, slot)
) {$charset};";
		$sql[] = self::pivotTable( $p, $charset );
		$sql[] = "CREATE TABLE {$p}nf_dict (
  dict_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  field_id INT UNSIGNED NOT NULL,
  value VARCHAR(191) NOT NULL,
  bit TINYINT UNSIGNED NULL,
  PRIMARY KEY  (dict_id),
  UNIQUE KEY field_value (field_id, value),
  UNIQUE KEY field_bit (field_id, bit)
) {$charset};";
		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * DDL for nf_pivot (contracts/schema.sql section 9): core columns plus 26 filter slots and 4 bitmask slots.
	 *
	 * @param string $p       Table prefix.
	 * @param string $charset Charset clause.
	 * @return string
	 */
	private static function pivotTable( string $p, string $charset ): string {
		$cols = '';
		$keys = '';
		$defs = array(
			'i' => array( 8, 'BIGINT NULL' ),
			'n' => array( 4, 'DECIMAL(20,6) NULL' ),
			't' => array( 6, 'DATETIME NULL' ),
			's' => array( 8, 'INT UNSIGNED NULL' ),
			'm' => array( 4, 'BIGINT UNSIGNED NOT NULL DEFAULT 0' ),
		);
		foreach ( $defs as $letter => list( $count, $type ) ) {
			for ( $n = 1; $n <= $count; $n++ ) {
				$cols .= "  {$letter}{$n} {$type},\n";
				if ( 'm' !== $letter ) {
					$keys .= ",\n  KEY {$letter}{$n} (pivot_id, {$letter}{$n})";
				}
			}
		}
		return "CREATE TABLE {$p}nf_pivot (
  pivot_id SMALLINT UNSIGNED NOT NULL,
  object_id BIGINT UNSIGNED NOT NULL,
  status TINYINT UNSIGNED NOT NULL,
  author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  published DATETIME NULL,
  modified DATETIME NULL,
  menu_order INT NOT NULL DEFAULT 0,
{$cols}  PRIMARY KEY  (pivot_id, object_id),
  KEY by_published (pivot_id, status, published),
  KEY by_modified (pivot_id, status, modified),
  KEY by_author (pivot_id, author_id){$keys}
) {$charset};";
	}
}
