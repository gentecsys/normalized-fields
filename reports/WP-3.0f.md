# WP 3.0f: Port-run slot sync trigger (slice 1)

Agent: A3 (daily build agent). Branch `wp/3.0f-port-trigger-term-user`.

## What changed
- `PortCommand::run()` fires `nf/port/finished( $post_type )` after the port job succeeds (WP-CLI only). Small edit in `src/Migration/` (A9 path), requested by the 3.0e report.
- `SlotSync::onPortFinished()` (hooked in `register()`): trusted contexts only (WP-CLI), syncs the ACF groups that apply to the ported post type; queues `pivot_rebuild` for new slots; skips with `nf/pivot/slots_skipped` while the schema is behind.
- Test: hook registered; untrusted request is a no-op.

## Tests that ran
Only `php -l`. No WP test env here; rely on CI.

## Split
Remaining items moved to new row 3.0g: term/user pivot sets (`ObjectType` in `Pivot::sync()` / `RowPivot`), nf_query parity test, anonymous-GET-writes-nothing test, "built" marker (reviewer B1 of 3.0d).

## Contract change requested
None. Edit outside A3 paths: `src/Migration/PortCommand.php` (one `do_action`); owner to confirm.

## Review round 1
- Fixed: test checks the untrusted path does not reach the sync; empty post type returns early.
- Not covered: trusted-path assertion (needs WP-CLI context); left to 3.0g parity tests.
- Follow-up: document `nf/port/finished` in contracts (A0).

## Resume from (review round 2: blocking, no nf:ready label)
- `test_port_finished_untrusted_does_not_sync` cannot fail: ACF functions are absent in the unit env, so `AcfSource::available()` is false and the method returns before the guard matters. Fix: inject `AcfSource`/groups into `SlotSync` (or stub the ACF functions), then assert untrusted gives 0 skips / no `pivot_rebuild` job and trusted (admin user + `set_current_screen( 'dashboard' )`, schema behind) gives 1 skip. Check it goes red without the guard.
- Add a test that an empty post type returns early; run phpcbf on `SlotSync.php` (alignment of `$groups`).
- Then confirm CI green, re-run the reviewer, add `nf:ready`. PR #73.
