# WP 1.1: Store (scalar, repeater, relationship)

Agent: A2 (daily build agent). Branch `wp/1.1-store`.

## What changed
- `src/Backend/`: `BackendInterface` and `DialectInterface` (signatures exactly as `contracts/backend.md`), `MysqlBackend` (the only `$wpdb` user for NF tables; `?` placeholders translated to `%d/%s`; nested transactions join the outer one; DB errors become exceptions so transactions roll back; prefix read per call so `switch_blog` works), `MysqlDialect`.
- `src/Storage/`: `ObjectType` and `DataTable` enums, `FieldDef`, `FieldMap` (register / byKey / children), `Store` (`get`, `set`, `delete`, `getRows`, `setRows`, `deleteObject`). Multi-valued fields (relationship) use `position`; Series rows use `nf_rows` plus `row_id` on the typed tables.
- `src/Install/Schema.php`: creates the Phase 1 tables (`nf_fields`, `nf_rows`, `nf_int/dec/date/str/text/json`) with dbDelta from `contracts/schema.sql`. Called from `Requirements::activate()`.
- `nf_db()` in `src/functions/nf.php`, backed by `Plugin::db()`.
- Tests: `tests/unit/Storage/StoreTest.php` (scalar types, isolation, relationship order, Series round trip, atomic rollback, validation, dialect).

## Deviations / notes
- Pivot write-through, dictionary, rollups are not part of this WP (1.3 / 2.2). Store has no pivot sync yet.
- NULL bound parameters are sent as empty strings by the `$wpdb->prepare()` translation; the store never binds NULL. Needs revisiting when the pivot (nullable columns) is written (WP 1.3/2.2): the adapter should emit literal `NULL`.
- Series are one level deep (no nested `parent_row_id`); nested Series come with 2.1.
- Schema install is per-site on activation only; no versioned migration class yet (`NF\Install\Migrations\`) and no multisite network-activation loop. Follow-up for A1.
- Tests remove the WP test suite's temporary-table filters so dbDelta creates real tables, and clean their own rows.

## Contract change requested
None.

## Verification
See the section appended at the end of the run.
