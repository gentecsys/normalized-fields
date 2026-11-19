# WP 3.0c: Pivot rebuild from the typed tables

Agent: A3 (daily build agent). Branch `wp/3.0c-pivot-rebuild`.

## What changed
- `Pivot::rebuildFromCore()` / `rebuildFromCoreChunk()`: keyset over core IDs (`$wpdb`, chunked, resumable) for the set's type and subtype (posts by `post_type`, terms by taxonomy, users all). Every core object gets its base row (status, author, parent, dates, menu_order) through `sync()`, so slots and row pivot are refilled from the typed tables. No join between core and NF tables.
- Orphans (object deleted or of another type) are removed per chunk within the covered id range; the last chunk also covers ids above the last core id. `remove()` also drops row-pivot rows.
- `PivotRebuildJob` now uses `rebuildFromCoreChunk()` (`done` flag instead of `count < chunk`).
- `rebuild()` / `rebuildChunk()` (walk existing pivot rows) are kept unchanged.
- Test: `RebuildTest::test_rebuild_from_core_fills_missing_and_prunes_orphans` (missing rows filled, draft status mapped, other-type and nonexistent rows pruned, second rebuild identical). `PivotRebuildJobTest` still holds (2+2+1).

## Tests that ran
Nothing ran locally: no WordPress test environment here and `vendor/` could not be installed; PHPStan phar needs the szepeviktor extension from `vendor/`. `php -l` passed. Rely on CI.

## Notes
- Post dates use local `post_date` / `post_modified`; users use `user_registered`; terms have no dates; unknown post statuses map to 100 (the contract's 100+ range; the filter mapping is not wired here).
- Users are rebuilt as all users (network-wide table set); no role subtype.

## Follow-ups
- 3.0d should queue `pivot_rebuild` when it assigns a new slot.
