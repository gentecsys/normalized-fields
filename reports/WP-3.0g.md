# WP 3.0g: Slot visibility marker (slice 1)

Agent: A3 (daily build agent). Branch `wp/3.0g-slot-visibility`.

## What changed
- `Pivot::pendingSlots()`, `markPending()`, `clearPending()`: pending slot list in `nf_meta` key `pivot_pending_<id>` (no schema change).
- `SlotAssigner` marks newly assigned slots pending; `PivotRebuildJob` (on done) and `Pivot::rebuildFromCore()` clear it.
- `QueryContext` drops pending slots, so queries use the probe fallback until the rebuild finishes (reviewer B1 of 3.0d). Write-through still fills pending slots.

## Tests
Review fix: rebuilds clear only the slots pending when they started (snapshot), so slots assigned mid-rebuild stay hidden. Only `php -l` ran locally (no WP test env). Added `SlotAssignerTest::test_new_slots_pending_until_rebuild` and `QueryContextTest::test_pending_slot_hidden_from_queries`. CI is authoritative.

## Split
Term/user pivot sets, nf_query parity test and anonymous-GET-writes-nothing test moved to new row 3.0h.

## Contract change requested
None. Note for A0: `nf_meta` key `pivot_pending_<id>` is new; document in contracts.
- Reviewer non-blocking, left for follow-up: N2 (mark before assign), N3 (extra nf_meta SELECT per resolve), N5/N6 (docs, contracts, DECISIONS entry for pivot_pending_<id>).
