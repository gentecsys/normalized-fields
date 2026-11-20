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
