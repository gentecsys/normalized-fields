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

## Owner run (2026-11-16, Claude on the owner's LocalWP)

Site: a LocalWP clone of a real client site: WordPress 7.1.4, ACF PRO 6.6.1, 21 field groups (192 fields, 6 repeaters), all reported portable by `wp nf port scan`. The original site was only read (database export).

First run: 18 of 20 post types clean. Findings, all fixed on `main`:
- `wp nf port verify` compared raw ACF values with typed NF values (false mismatches on every date, time and repeater field): fixed in `927c590`.
- The site's importer stores arrays in ACF text fields (lists such as websites and phone numbers, plus empty structures in other fields): NF dropped them. Owner decision: keep them as is (DECISIONS 2026-11-16); 19 fields, 29,751 values now in `nf_json`.
- A third-party plugin on the site is missing one of its own files on the original site too, so any post query in WP-CLI fatals; deactivated on the clone. The port CLI printed an empty error for the failed job; it now shows the job's exception.

Second run: zero mismatches in all 20 post types, 7,470 posts, 127,684 values ported (largest: 3,633 posts, 101,292 values, port 24 s, verify 10 s). Verify covers posts only (terms, users, options: see `reports/WP-2.6d.md`).
