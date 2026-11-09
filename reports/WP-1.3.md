# WP 1.3: Minimal pivot + two translated queries

Agent: A3 + A4 (daily build agent). Branch `wp/1.3-pivot-queries`.

## What changed
- `src/Install/Schema.php`: creates `nf_pivot_sets`, `nf_pivot_slots` and `nf_pivot` (core columns + i1-8, n1-4, t1-6, s1-8, m1-4 with the 26 slot indexes), exactly as `contracts/schema.sql` section 9. `nf_row_pivot` is not created (Phase 2).
- `src/Storage/Status.php`: `NF\Storage\Status` enum (publish=1 ... auto-draft=8) named in the contract.
- `src/Pivot/Pivot.php`: `setId()` / read-only `findSetId()`, `assignSlot()` (slot letter must match the field's data table: int->i, dec->n, date->t), `slots()`, `sync()` (write-through: one upsert from the typed tables plus `write_version` bump, one transaction), `remove()`, `rebuild()` (recreates slot columns from typed tables, keeps core columns), `writeVersion()`.
- `src/Query/SpikeQuery.php`: translates a `WP_Query` argument array to SQL on `nf_pivot` and returns `ids`, `found_posts`, `max_num_pages`; returns null for anything outside its subset (any unknown argument key, OR relations, unslotted fields, other orderbys, unmapped statuses, no pivot set), so the caller leaves it to core.
  - Query 1 (archive): AND meta clauses on slotted single-valued fields (`= != > >= < <=`; DATE clauses need `type => DATE` and a `Ymd` value), `orderby` `meta_value` / `meta_value_num` / `date` / `ID`.
  - Query 2 (reverse relationship): `LIKE '"123"'` on a multi-valued int field becomes a semi-join into `nf_int` on `(field_id, value)`, then pivot rows by primary key.
  - Paging as core: `posts_per_page`, `paged`, `offset` (overrides `paged`), `-1` / `nopaging` (no LIMIT, no count query), `no_found_rows`; `max_num_pages = ceil(found / ppp)`.
  - Reads only. It never creates a pivot set, so an anonymous read cannot write.
- Tests: `tests/unit/Query/SpikeQueryTest.php` runs the same arguments through `WP_Query` on `wp_postmeta` (ACF format) and through NF and compares IDs, order, `found_posts`, `max_num_pages` for `paged` 1 / 2 / last / past last, `offset` with and without `paged`, `-1`, `nopaging`, `no_found_rows`, a decimal filter, the default order, and the reverse relationship. It also tests that unsupported arguments return null, that a query does not write, that rebuild equals write-through row for row, and that slot/type mismatches are refused.

## Deviations / notes
- Ownership: this WP edits `src/Install/Schema.php` (A1) and adds `src/Storage/Status.php` (A2), as 1.1 did for the schema; A1 should take over the DDL with the versioned migration.
- No hook wiring yet: nothing calls `Pivot::sync()` from `transition_post_status` / Bridge saves, and `SpikeQuery` is not attached to `WP_Query` (short-circuit mode is WP 3.3). 1.5 (benchmark) and 1.4 call these classes directly. Follow-up: bench `NfStore` writer should sync the pivot for the 10k/100k datasets.
- Round 2 (CI + reviewer): past-the-last-page and empty pages return found 0 / max 0 as core does; `-1`/`nopaging` return found = count, max 0; untyped numeric comparisons and `orderby meta_value` on int/dec slots return null (core compares strings); `sync()` goes through `dialect->upsert()` (NULL slots cleared with a literal UPDATE because the adapter never binds NULL); date order uses `nullsOrder()`; `fields` other than `ids` returns null; tests wipe posts and NF rows in set_up/tear_down (DDL commits the suite's transaction).
- Follow-ups from review (non-blocking): count and ID page are not read at the same `write_version` (3.1/3.3); default `post_status` ignores `private` for capable users (3.3); decimal/date binds are strings, no dialect cast; `rebuild()` is N+1 and bumps the version per row (chunk before 1.5/100k); versioned migration and uninstall of the pivot tables (A1); no test for `!=`, ties or logged-in users.
- (superseded) For no-limit queries (`-1`, `nopaging`) `found_posts` and `max_num_pages` follow my reading of core (`found = count`, `max = 1` when non-empty). The differential test is the check; if CI shows a difference, fix the translator, not the test.
- The pivot is object-pivot only: no row pivot, dictionary, bitmasks or rollups (WP 2.2). Slot values are bound without NULLs (the adapter sends NULL as empty string): `sync()` writes the literal `NULL`.
- `DISTINCT`/count: Query 2's semi-join cannot duplicate pivot rows, so no `DISTINCT` is needed.

## Contract change requested
None.

## Verification
- `php -l` on every new and changed file: clean.
- phpunit, WPCS phpcs, phpstan: **not run locally** (composer dist downloads blocked; phpstan needs `vendor/` extensions; WPCS not available as a phar). CI is authoritative.

## Resume from
If CI shows a difference in the differential test, adjust `SpikeQuery::run()` / `translate()`.
