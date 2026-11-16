# Gate G2: storage MVP

Written 2026-11-16 by the daily build agent. The build is paused until the owner approves G2 or requests changes.

## What was built (Phase 2, all WPs merged)
- 2.0: manual schema upgrades (activation, admin button, `wp nf upgrade`) with NF writes blocked while behind.
- 2.1a-d: type map per design table, long-text spill, scalar, multi-valued, composite (link, Google Map, group) and clone storage.
- 2.2a-d: dictionary, string and bitmask slots, row pivot and rollups, chunked rebuild job with fuzz tests.
- 2.3: archive and revisions (preview and restore).
- 2.4, 2.5, 2.5b: see their reports in `reports/`.
- 2.6a-d: ACF to NF port (scan, port objects, definitions, verify) with `wp nf port scan|run|verify`.
- 2.7: Series row sorting (three modes, `nf_register_row_sorter`).

## Test and CI results
CI on main is green (28/28 checks, one skipped) at the last merge (PR #57). Unit tests per WP are in each report.

## Gate criterion (owner)
Verify reports zero mismatches on a staging copy of a real site. This needs the owner's LocalWP or staging site with ACF PRO; it was not run in the cloud sandbox. Run `wp nf port scan`, `wp nf port run`, then `wp nf port verify`.

## Open risks
- Bench-dataset zero-mismatch run and the posts-only limit of verify are deferred (see `reports/WP-2.6d.md`).
- A Series feeding row slots in two pivot sets would collide on `nf_row_pivot.row_id` (contract question).
- `rebuild()` does not remove row-pivot rows of a Series whose row slots were removed.
- `syncRows()`/`rollup()` insert row by row; batch before the benchmarks (3.4/3.8).
- Clone behaviour with ACF PRO was checked on nf-dev only; differential fixtures belong to A11.
- Differential coverage for the new field types is not yet in the A11 harness.
- Tooling (phpunit, phpcs, phpstan) ran in CI only.

## Decisions for the owner
- Approve G2 by adding `- G2: approved YYYY-MM-DD` under "Gate approvals" in `DECISIONS.md`, or add `- G2: changes requested ...`.
- Review any D-xx entries still marked "pending owner confirmation" and the "(made by daily agent)" contract changes in `DECISIONS.md`.
