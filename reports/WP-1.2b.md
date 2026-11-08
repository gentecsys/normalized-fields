# WP 1.2b: Bridge, re-sync a Series after a lone sub-field save

Requested by the owner, 2026-11-08. Do this before 1.3.

## Problem
PR #17 (WP 1.2) was merged with the reviewer's blocker open. The owner's follow-up commit on
`fix/1.2-subfield-guard` makes `Bridge::onUpdate()` and `Bridge::beforeSeries()` skip sub-fields saved
outside a Series save (`isSubField()`), so nothing wrong is written. But NF is now stale: checked on
nf-dev against ACF PRO 6.8.11,

    update_field( 'sessions', [ rowA, rowA ], $id );               // NF rows: room[1] = 'Room A'
    update_sub_field( [ 'sessions', 2, 'room' ], 'Room B', $id );  // ACF: 'Room B', NF still 'Room A'

The same applies to `add_row()`, `update_row()`, `delete_row()` and `delete_sub_field()`.

## Wanted
When a sub-field (or a nested Series) is saved or deleted outside a full Series save, re-copy the whole
top-level Series from ACF into NF once the ACF write has happened:
- Collect `(object, top-level Series key)` pairs in `onUpdate` / `onDelete` / `beforeSeries` when
  `isSubField()` is true; walk `parent` up to the top-level Series.
- Flush after ACF has written meta (for example on `acf/save_post` at a late priority and on `shutdown`
  for calls made outside a save), reading the raw rows with `get_field( $key, $post_id, false )` and
  writing them with `Store::setRows()` through the same normalisation as `beforeSeries`.
- One re-sync per Series per request, however many sub-fields changed.
- Failures go to `nf/bridge/error`, never break the ACF save.
- Logged-out GET/HEAD never trigger a write (owner rule, see CLAUDE.md "Anti-bot (D-18)").

## Tests
Sub-field update, `add_row()`, `delete_row()`, two sub-fields in one request (one re-sync), failure
reported not thrown. The owner re-runs the ACF PRO check on nf-dev after merge.
