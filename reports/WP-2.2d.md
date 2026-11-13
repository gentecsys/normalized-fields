# WP 2.2d: Rebuild job and fuzz

Agent: A3 (daily build agent). Branch `wp/2.2d-rebuild-fuzz`.

## What changed
- `src/Pivot/Pivot.php`: `rebuild()` now runs in chunks of object ids (`REBUILD_CHUNK` = 500, optional progress callback); new `rebuildChunk( pivotId, afterObjectId, limit )` returns `count` and `last` so a job or WP-CLI command can resume; `pruneRowPivot()` drops `nf_row_pivot` rows of Series with no row slot left (called at the end of `rebuild()`, closes the 2.2c follow-up); `pruneDictionary()` deletes `nf_dict` entries that no `s` slot (object or row pivot) refers to and that carry no bitmask bit.
- `tests/unit/Pivot/RebuildTest.php`: chunk coverage and resume, seeded fuzz (25 objects, random writes, rewrites, removals: a rebuild leaves `nf_pivot` and `nf_row_pivot` byte for byte equal, count rollups equal a live `COUNT(*)`), row-pivot pruning, dictionary pruning (referenced and bit entries kept, ids not reused), collation behaviour.

## Collation of `nf_dict` (documented behaviour)
Lookups follow the `value` column collation. On the WordPress default (case-insensitive, e.g. `utf8mb4_unicode_520_ci`) `Oslo` and `oslo` are one entry and the first spelling is kept; this matches `=` on `wp_postmeta`. The test reads the real collation from `information_schema` and asserts either behaviour. PostgreSQL (Phase 5) must pick a matching collation.

## Deviations / notes
- Pruning is not safe against concurrent writes (a value written between the scan and the delete loses its entry until the next rebuild). Documented on the method; run after a rebuild.
- WP-CLI command (`wp nf pivot rebuild`) is not added: `src/Pivot/` is the only path this agent owns. `Pivot::rebuild( $id, $chunk, $progress )` is ready to call. Follow-up for A1 (with 2.5 background jobs): a CLI command and an Action Scheduler job that stores `last` from `rebuildChunk()`.
- Rebuild still re-reads rows per slot (2.2c follow-up on batching) — left for benchmarks.

## Contract change requested
None.

## Verification
- `php -l` on changed files: clean.
- phpunit, phpstan, WPCS: not run locally (composer dist downloads blocked; no WP test env). CI is authoritative.

## Resume from
If CI fails, check `RebuildTest` assertions on `pruneDictionary()` counts (global table, other fixtures) and `Pivot::rebuildChunk()`.

## Review follow-ups
- Fixed (blocking): the fuzz test now corrupts `nf_pivot` and `nf_row_pivot` (values, NULL string slots, deleted rows) before a chunked rebuild and compares with the write-through snapshot; the max rollup is compared with a live `MAX`.
- CLI and background-job callers must check `Upgrader::isCurrent()` before calling rebuild or prune (A1, WP 2.5).
- `pruneDictionary()` holds ids in memory and covers all sets; a set-scoped, batched variant is needed before the 100k benchmarks.
- Collation note belongs in `docs/design.md` (orchestrator).
