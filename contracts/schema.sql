-- NF contracts v0: logical DDL for every NF table (MySQL dialect; MariaDB 10.4+ / MySQL 5.7+).
-- Owner: A0 (drafted in WP 0.5; frozen at G0). Source: docs/design.md, "Fixed schema",
-- "Pivot and lookup tables", "Counting tier", "Multisite networks", "Remote PostgreSQL backend".
--
-- Conventions
--  * `{p}` = $wpdb->prefix (per site). `{bp}` = base prefix, for network-level user tables.
--    MySQL stores NO site_id (ADR-0011). PostgreSQL adds `site_id` as the first column of every
--    key and partitions by it (user values use site_id = 0); migrations generate that dialect.
--  * object_type: 1 post, 2 term, 3 user, 4 comment, 5 option (enum NF\Storage\ObjectType).
--  * Written for dbDelta(): two spaces before column definitions, PRIMARY KEY with two spaces,
--    one KEY per line. Charset/collation come from $wpdb->get_charset_collate().
--  * Access only through nf_db(); never join these tables to core tables (ADR-0009).
--  * No flexible content: no layout_id columns (ADR-0008).
--  * Derived tables (pivot*, dict, count, terms) can be rebuilt from typed tables + nf_rows.

-- 0. Schema version and environment stamp
CREATE TABLE {p}nf_meta (
  meta_key   VARCHAR(64) NOT NULL,
  meta_value LONGTEXT NOT NULL,
  PRIMARY KEY  (meta_key)
);

-- 1. Field map: every field (and pseudo sub-field) gets a small integer ID.
CREATE TABLE {p}nf_fields (
  field_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  field_key      VARCHAR(64) NOT NULL,
  field_name     VARCHAR(191) NOT NULL,
  parent_id      INT UNSIGNED NOT NULL DEFAULT 0,
  data_table     ENUM('int','dec','date','str','text','json','rows','none') NOT NULL,
  multi          TINYINT(1) NOT NULL DEFAULT 0,
  row_count      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  distinct_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (field_id),
  UNIQUE KEY field_key (field_key),
  KEY by_name (field_name, parent_id)
);

-- 2. Structure: one row per repeater (Series) item.
CREATE TABLE {p}nf_rows (
  row_id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  object_type   TINYINT UNSIGNED NOT NULL,
  object_id     BIGINT UNSIGNED NOT NULL,
  field_id      INT UNSIGNED NOT NULL,
  parent_row_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  row_order     SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY  (row_id),
  KEY owner (object_type, object_id, field_id, parent_row_id, row_order)
);

-- 3-8. Typed value tables: identical skeleton, only `value` differs.
CREATE TABLE {p}nf_int (
  object_type TINYINT UNSIGNED NOT NULL,
  object_id   BIGINT UNSIGNED NOT NULL,
  field_id    INT UNSIGNED NOT NULL,
  row_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  position    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  value       BIGINT NOT NULL,
  PRIMARY KEY  (object_type, object_id, field_id, row_id, position),
  KEY by_value (field_id, value, object_type, object_id)
);
CREATE TABLE {p}nf_dec (
  object_type TINYINT UNSIGNED NOT NULL,
  object_id   BIGINT UNSIGNED NOT NULL,
  field_id    INT UNSIGNED NOT NULL,
  row_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  position    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  value       DECIMAL(20,6) NOT NULL,
  PRIMARY KEY  (object_type, object_id, field_id, row_id, position),
  KEY by_value (field_id, value, object_type, object_id)
);
CREATE TABLE {p}nf_date (
  object_type TINYINT UNSIGNED NOT NULL,
  object_id   BIGINT UNSIGNED NOT NULL,
  field_id    INT UNSIGNED NOT NULL,
  row_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  position    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  value       DATETIME NOT NULL,
  PRIMARY KEY  (object_type, object_id, field_id, row_id, position),
  KEY by_value (field_id, value, object_type, object_id)
);
CREATE TABLE {p}nf_str (
  object_type TINYINT UNSIGNED NOT NULL,
  object_id   BIGINT UNSIGNED NOT NULL,
  field_id    INT UNSIGNED NOT NULL,
  row_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  position    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  value       VARCHAR(191) NOT NULL,
  PRIMARY KEY  (object_type, object_id, field_id, row_id, position),
  KEY by_value (field_id, value, object_type, object_id)
);
-- Values over 191 chars live here; a 191-char prefix stays in nf_str for equality/sorting.
-- Optional FULLTEXT(value) is added by a migration only when enabled in settings.
CREATE TABLE {p}nf_text (
  object_type TINYINT UNSIGNED NOT NULL,
  object_id   BIGINT UNSIGNED NOT NULL,
  field_id    INT UNSIGNED NOT NULL,
  row_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  position    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  value       MEDIUMTEXT NOT NULL,
  PRIMARY KEY  (object_type, object_id, field_id, row_id, position)
);
-- Third-party / unknown types. LONGTEXT holding JSON (MariaDB 10.4 and MySQL 5.7 compatible).
CREATE TABLE {p}nf_json (
  object_type TINYINT UNSIGNED NOT NULL,
  object_id   BIGINT UNSIGNED NOT NULL,
  field_id    INT UNSIGNED NOT NULL,
  row_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  position    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  value       LONGTEXT NOT NULL,
  PRIMARY KEY  (object_type, object_id, field_id, row_id, position)
);

-- 9. Pivot sets, slots, object pivot, row pivot.
CREATE TABLE {p}nf_pivot_sets (
  pivot_id    SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  object_type TINYINT UNSIGNED NOT NULL,
  subtype     VARCHAR(64) NOT NULL,
  PRIMARY KEY  (pivot_id),
  UNIQUE KEY set_key (object_type, subtype)
);
CREATE TABLE {p}nf_pivot_slots (
  pivot_id    SMALLINT UNSIGNED NOT NULL,
  repeater_id INT UNSIGNED NOT NULL DEFAULT 0,
  slot        CHAR(3) NOT NULL,
  field_id    INT UNSIGNED NOT NULL,
  rollup      ENUM('none','min','max','count') NOT NULL DEFAULT 'none',
  PRIMARY KEY  (pivot_id, repeater_id, slot)
);
-- Core columns mirror post columns so queries never join wp_posts (ADR-0009): required on PostgreSQL.
CREATE TABLE {p}nf_pivot (
  pivot_id   SMALLINT UNSIGNED NOT NULL,
  object_id  BIGINT UNSIGNED NOT NULL,
  status     TINYINT UNSIGNED NOT NULL,
  author_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  parent_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  published  DATETIME NULL,
  modified   DATETIME NULL,
  menu_order INT NOT NULL DEFAULT 0,
  i1 BIGINT NULL, i2 BIGINT NULL, i3 BIGINT NULL, i4 BIGINT NULL,
  i5 BIGINT NULL, i6 BIGINT NULL, i7 BIGINT NULL, i8 BIGINT NULL,
  n1 DECIMAL(20,6) NULL, n2 DECIMAL(20,6) NULL, n3 DECIMAL(20,6) NULL, n4 DECIMAL(20,6) NULL,
  t1 DATETIME NULL, t2 DATETIME NULL, t3 DATETIME NULL, t4 DATETIME NULL, t5 DATETIME NULL, t6 DATETIME NULL,
  s1 INT UNSIGNED NULL, s2 INT UNSIGNED NULL, s3 INT UNSIGNED NULL, s4 INT UNSIGNED NULL,
  s5 INT UNSIGNED NULL, s6 INT UNSIGNED NULL, s7 INT UNSIGNED NULL, s8 INT UNSIGNED NULL,
  m1 BIGINT UNSIGNED NOT NULL DEFAULT 0, m2 BIGINT UNSIGNED NOT NULL DEFAULT 0,
  m3 BIGINT UNSIGNED NOT NULL DEFAULT 0, m4 BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (pivot_id, object_id),
  KEY by_published (pivot_id, status, published),
  KEY by_modified (pivot_id, status, modified),
  KEY by_author (pivot_id, author_id),
  KEY i1 (pivot_id, i1), KEY i2 (pivot_id, i2), KEY i3 (pivot_id, i3), KEY i4 (pivot_id, i4),
  KEY i5 (pivot_id, i5), KEY i6 (pivot_id, i6), KEY i7 (pivot_id, i7), KEY i8 (pivot_id, i8),
  KEY n1 (pivot_id, n1), KEY n2 (pivot_id, n2), KEY n3 (pivot_id, n3), KEY n4 (pivot_id, n4),
  KEY t1 (pivot_id, t1), KEY t2 (pivot_id, t2), KEY t3 (pivot_id, t3), KEY t4 (pivot_id, t4),
  KEY t5 (pivot_id, t5), KEY t6 (pivot_id, t6),
  KEY s1 (pivot_id, s1), KEY s2 (pivot_id, s2), KEY s3 (pivot_id, s3), KEY s4 (pivot_id, s4),
  KEY s5 (pivot_id, s5), KEY s6 (pivot_id, s6), KEY s7 (pivot_id, s7), KEY s8 (pivot_id, s8)
);
CREATE TABLE {p}nf_row_pivot (
  row_id    BIGINT UNSIGNED NOT NULL,
  pivot_id  SMALLINT UNSIGNED NOT NULL,
  object_id BIGINT UNSIGNED NOT NULL,
  field_id  INT UNSIGNED NOT NULL,
  i1 BIGINT NULL, i2 BIGINT NULL, i3 BIGINT NULL, i4 BIGINT NULL,
  n1 DECIMAL(20,6) NULL, n2 DECIMAL(20,6) NULL,
  t1 DATETIME NULL, t2 DATETIME NULL, t3 DATETIME NULL, t4 DATETIME NULL,
  s1 INT UNSIGNED NULL, s2 INT UNSIGNED NULL, s3 INT UNSIGNED NULL, s4 INT UNSIGNED NULL,
  m1 BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (row_id),
  KEY by_object (pivot_id, object_id, field_id),
  KEY i1 (field_id, i1, object_id), KEY i2 (field_id, i2, object_id),
  KEY i3 (field_id, i3, object_id), KEY i4 (field_id, i4, object_id),
  KEY n1 (field_id, n1, object_id), KEY n2 (field_id, n2, object_id),
  KEY t1 (field_id, t1, object_id), KEY t2 (field_id, t2, object_id),
  KEY t3 (field_id, t3, object_id), KEY t4 (field_id, t4, object_id),
  KEY s1 (field_id, s1, object_id), KEY s2 (field_id, s2, object_id),
  KEY s3 (field_id, s3, object_id), KEY s4 (field_id, s4, object_id)
);

-- 10. Dictionary: each distinct short string once per field; bit = position in an m-slot.
CREATE TABLE {p}nf_dict (
  dict_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  field_id INT UNSIGNED NOT NULL,
  value    VARCHAR(191) NOT NULL,
  bit      TINYINT UNSIGNED NULL,
  PRIMARY KEY  (dict_id),
  UNIQUE KEY field_value (field_id, value),
  UNIQUE KEY field_bit (field_id, bit)
);

-- 11. Counting tier.
CREATE TABLE {p}nf_count (
  pivot_id SMALLINT UNSIGNED NOT NULL,
  status   TINYINT UNSIGNED NOT NULL,
  dim      CHAR(3) NOT NULL,
  val      BIGINT NOT NULL,
  day      DATE NOT NULL,
  n        INT UNSIGNED NOT NULL,
  PRIMARY KEY  (pivot_id, status, dim, val, day),
  KEY by_day (pivot_id, status, dim, day, val, n)
);
-- Fallback for the versioned count cache when no persistent object cache exists.
CREATE TABLE {p}nf_count_cache (
  cache_key  CHAR(40) NOT NULL,
  pivot_id   SMALLINT UNSIGNED NOT NULL,
  version    BIGINT UNSIGNED NOT NULL,
  n          BIGINT UNSIGNED NOT NULL,
  expires    DATETIME NOT NULL,
  PRIMARY KEY  (cache_key),
  KEY by_pivot (pivot_id, version)
);

-- 12. Archive: revision and autosave snapshots.
CREATE TABLE {p}nf_archive (
  object_type TINYINT UNSIGNED NOT NULL,
  object_id   BIGINT UNSIGNED NOT NULL,
  parent_id   BIGINT UNSIGNED NOT NULL,
  snapshot    LONGTEXT NOT NULL,
  PRIMARY KEY  (object_type, object_id),
  KEY parent (object_type, parent_id)
);

-- 13. Term mirror: PostgreSQL backend only (filterable taxonomies; no joins to core).
--     Created by PostgreSQL migrations; listed here so the logical schema is complete.
-- nf_terms (object_id BIGINT, taxonomy VARCHAR(32), term_taxonomy_id BIGINT,
--           PRIMARY KEY (taxonomy, term_taxonomy_id, object_id), KEY by_object (object_id, taxonomy))

-- 14. Outbox: PostgreSQL backend only, stored in MySQL (pending writes). See contracts/backend.md.
-- nf_outbox (outbox_id BIGINT AUTO_INCREMENT PK, op LONGTEXT, created DATETIME, attempts INT, last_error TEXT)

-- 15. Network-level user tables (ADR-0011): on the BASE prefix {bp}, one set shared by all sites.
--     Same skeletons as 1-3..8 with the prefix `{bp}nf_user_`: nf_user_fields (field map keyed by
--     ACF field_key, shared across sites), nf_user_rows, nf_user_int, nf_user_dec, nf_user_date,
--     nf_user_str, nf_user_text, nf_user_json. object_type is fixed at 3 and omitted from the keys.
--     User pivot rows live in the per-site pivot (object_type 3), derived from the network tables.
