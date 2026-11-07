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
		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}
