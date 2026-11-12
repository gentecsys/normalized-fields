# WP 2.2c: Row pivot and rollups

Agent: A3 (daily build agent). Branch `wp/2.2c-row-pivot`.

## What changed
- `src/Pivot/RowPivot.php` (new): `assignSlot()` puts a single-valued Series sub-field into a row-pivot slot (`i1-4`, `n1-2`, `t1-4`, `s1-4`; strings through the dictionary); `assignRollup()` puts `min` / `max` (int, dec, date sub-fields) or `count` (any single-valued sub-field, an `i` slot) into an object-pivot slot, stored in `nf_pivot_slots.rollup`; `syncRows()` rewrites one `nf_row_pivot` row per `nf_rows` row (row id = `nf_rows.row_id`, `field_id` = the Series); `rollup()` computes the value from live rows.
- `src/Pivot/Pivot.php`: owns a `RowPivot` (`rows()`); `sync()` writes rollup slots and syncs row-pivot rows in the same transaction, `remove()` clears them, `slots()` returns `rollup` and `parent`. `rebuild()` therefore rebuilds rows and rollups.
- `tests/unit/Pivot/RowPivotTest.php`: rows and slots, NULL for missing values, shared dictionary ids, min / max / count incl. empty Series, update and remove, rebuild equals write-through, rollup count equals a live `COUNT(*)`, refused assignments.

- `src/Install/Schema.php` (A1 path, small and contract-consistent): CREATE for `nf_row_pivot` was missing, so it is added and `Upgrader::SCHEMA_VERSION` is 6. Owner to review at next gate.

## Deviations / notes
- `count` counts rows that have a value for the chosen sub-field (not all rows); pick a required sub-field for a row count.
- Multi-valued sub-fields and nested Series have no row-pivot slot yet (`m1` on `nf_row_pivot` is unused); string min / max is refused (dictionary ids are unordered).
- Row values come from `Store::getRows()`, so long-text spill is handled; a string over 191 chars gets a NULL `s` slot, as on the object pivot.
- Rollups ignore trashed or draft status; the compiler (3.1) decides how to use them.

## Follow-ups (from review)
- One Series feeding row slots in two pivot sets would collide on the `nf_row_pivot.row_id` primary key; a Series belongs to one set for now (contract question for A0).
- `rebuild()` does not remove row-pivot rows of a Series whose row slots were removed (2.2d).
- `syncRows()` / `rollup()` re-read rows per slot and insert row by row: cache per `sync()` and batch before the benchmarks (3.4/3.8). Row ids should come from `Store` with the rows.
- Rollup compare uses floats for numerics; date format is fixed-width `Y-m-d H:i:s`.
- Fixed in review: `assignSlot()` now resets `rollup` to `none`.

## Contract change requested
None.

## Verification
- `php -l` on changed files: clean.
- phpunit, phpcs, phpstan: not run locally (no vendor, no WPCS). CI is authoritative.

## Resume from
If CI fails, check `RowPivot::syncRows()` and `RowPivotTest`. Next: 2.2d (rebuild job, dictionary pruning, fuzz).
