# WP 3.0e: Slot sync triggers (slice 1)

Agent: A3 (daily build agent). Branch `wp/3.0e-slot-sync-triggers`.

## What changed
- `NF\Pivot\SlotSync` (registered in `Plugin::services()`, the only edit outside `src/Pivot/`): hooks `nf/upgraded` (all groups, after the manual upgrade) and `acf/update_field_group` (deferred to `shutdown`, so ACF has saved the fields). `trusted()` allows WP-CLI, or a logged-in user with `manage_options` on an admin screen; never a visitor, REST, front end or cron-only request. New slots queue a `pivot_rebuild` job (WP-Cron). Behind schema: skip, set `NEEDS_RESYNC`, fire `nf/pivot/slots_skipped`.
- Test: `tests/unit/Pivot/SlotSyncTest.php` (anonymous, front-end admin, admin screen, untrusted save hooks nothing, schema behind).

## Tests that ran
Only `php -l`. No WP test env here; rely on CI.

## Deviations / follow-ups (3.0e is not finished)
- **Not done: `wp nf port run` trigger.** Needs a completion action in `src/Migration/` (A9). Request: fire `do_action( 'nf/port/finished' )` there, then SlotSync hooks it. Remainder moved to new row 3.0f: port trigger, term/user sets (needs `Pivot::sync()` / `RowPivot` to take `ObjectType`), bench-type nf_query parity test, reviewer B1 "built" marker.
- Plugin.php one-line wiring is outside A3 paths; owner to confirm. Review round 1 fixes: no NEEDS_RESYNC clobbering, more tests.
- Contract change requested: none.
