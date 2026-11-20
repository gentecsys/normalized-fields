# WP 3.0d: Default pivot sets and slot assignment (slice 1)

Agent: A3 (daily build agent). Branch `wp/3.0d-default-pivot-sets`.

## What changed
- `NF\Pivot\SlotAssigner`: `letter()` (eligible ACF types: true_false, number, range, date/date-time picker, single select, radio, single post_object, user, image; multi-valued checkbox / multi select get `m`), `postTypes()` (from `post_type ==` location rules), `fieldsByPostType()`, `assign()` (next free slot per letter in field order, existing assignments never move, skips when slots run out, `$dry_run` creates and writes nothing, not even the set). Post sets only.
- `NF\Pivot\PivotCommand`: `wp nf pivot sync [--dry-run]`; refuses while the schema is behind; starts and runs `pivot_rebuild` for each set that got new slots (answers the 3.0c follow-up). Registered in `JobRunner::register()` next to `nf job` / `nf port`.
- Test: `tests/unit/Pivot/SlotAssignerTest.php` (letters, location parsing, order, idempotence, later fields take later slots, dry run, slots running out).

## Tests that ran
Only `php -l`. No WordPress test environment, `vendor/` could not be installed, and the phpcs phar has no WPCS standard. Rely on CI.

## Deviations / split
3.0d is split: this slice has the assigner and the CLI. **3.0e** (new in PLAN.md) covers the other triggers (`wp nf port run`, `wp nf upgrade`, ACF field group save with capability check) and term/user sets, which need `Pivot::sync()` / `RowPivot` to take `ObjectType` first. The "anonymous GET writes nothing" test belongs with the triggers; this slice adds no hook, so no request path can write.
- The bench-type test "sync then `nf_query()` matches `wp_postmeta`" is not added here; follow-up in 3.0e.

## Contract change requested
None.

## Review follow-up
- Known window (reviewer B1): new slots are visible to queries before `pivot_rebuild` finishes. Mitigation here: `wp nf pivot sync --rebuild` rebuilds sets after an interrupted run. A planner guard or rebuild-then-publish ordering is a follow-up for 3.0e (needs a "built" marker; contract change request: none yet).
- Fixed: duplicate keys no longer leak slots; `assign()` checks `Upgrader::isCurrent()` (sets `NEEDS_RESYNC`, fires `nf/pivot/slots_skipped`); one bad field warns instead of aborting; rebuild loop capped.
- Not done: bitmask >64 values check, PivotCommand tests, nf_query parity test (3.0e).
