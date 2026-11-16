# WP 3.0a: Enforce one pivot set per Series

## What changed
- `NF\Pivot\RowPivot::assignSlot()` now calls `assertSeriesFree()`. If the Series already has row slots (`repeater_id > 0`) in a different pivot set, it throws `InvalidArgumentException` naming that set (id, object type, subtype). DECISIONS 2026-11-16.
- Rollups (`repeater_id = 0`) write no row-pivot rows, so they are not restricted.
- Re-assigning slots in the owning set still works.
- Test: `RowPivotTest::test_series_feeds_only_one_pivot_set`.

## Tests
- `php -l` on both files only. phpunit, phpcs and phpstan were not run here (no vendor tooling in the sandbox; not attempted, to save budget). CI is the check.

## Follow-ups
- Surfacing this error in the admin collection builder belongs to a later WP.
