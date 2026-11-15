# WP 2.7: Repeater (Series) row sorting

State: in-review. Branch `wp/2.7-repeater-sorting`.

## What changed
- `NF\Storage\RowSortRule`: the `nf_sort` setting (mode manual / on_save / on_read, by, order, compare, empty); `fromArray()` falls back to defaults on bad values.
- `NF\Storage\RowSorter`: per-field rules, registry of custom sorters, `sort()` for `sub_field:<name>`, `post_date:<sub_field>` (one `SELECT ID, post_date FROM wp_posts WHERE ID IN (...)`, core table via `$wpdb`) and `callback:<name>`. Stable ties, empty values first/last regardless of direction, locale (intl `Collator`, falls back to `strnatcasecmp`), natural, binary; numeric values compare as numbers, dates compare as ISO strings. Filter `nf/repeater/sort_rows` runs after every sort.
- `Store`: optional `RowSorter` constructor argument. `setRows()` sorts first for `on_save`; `getRows()` sorts for `on_read` (stored order untouched); new `sortRows()` rewrites `row_order` in one `UPDATE ... CASE` statement (manual / editor action) and fires `nf/repeater/sorted` only when the order changed.
- `Plugin::sorter()` and `nf_register_row_sorter()` (thin wrapper in `src/functions/nf.php`).
- Tests: `tests/unit/Storage/RowSorterTest.php` (rule parsing, sub-field, empty, date, numeric, post_date, callback, filter, and all three modes against the DB).

## What ran
- `php -l` on all changed files: OK.
- phpunit, PHPCS (WPCS) and PHPStan could not run here (no vendor; phars lack WPCS / the WordPress PHPStan extension). CI is the check.

## Deviations / open items
- Feeding `nf_sort` from the field JSON into `RowSorter::setRule()` belongs to the registration code (A5, WP 3.6); no ACF-side wiring here.
- Not done (later WPs): `get_field( ..., [ 'sort' => ... ] )` per-call sort and Timber option (3.6/3.7), the `post_updated` re-sort job for `on_save` + `post_date` (needs the background-job hookup, follow-up), admin toolbar/builder (4.2/4.3), packed (JSON) storage, per-object caching of `on_read` results.
- **Follow-up for A0:** `nf_register_row_sorter()` is not yet listed in `contracts/api.md`; `Store::sortRows()` and `RowSorter` should be noted in the storage contract.
- The Store does not check `Upgrader::isCurrent()`; callers (Bridge) do, as for `setRows()`.
