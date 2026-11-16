# WP 2.6d: Port, verify, re-port, WP-CLI

Agent: A9 (daily build agent). Branch `wp/2.6d-port-verify`.

## What changed
- `NF\Migration\ValueComparer` (pure): normalises ACF and NF values (strings vs typed, empty equivalents, assoc key order) and returns the paths that differ, e.g. `rows[1].title`.
- `NF\Migration\PortVerifier`: dual load of one object. Reads each portable field raw from ACF and from `Store` (`getRows()` for a Series), only where ACF holds a value (same rule as the port). Read only.
- `NF\Migration\PortVerifyJob` (`port_verify`): per post type, resumable by ID, state keeps the post count, mismatch count and the first 100 mismatches.
- `NF\Migration\PortCommand`: `wp nf port scan [--format=json]`, `wp nf port run --post-type=`, `wp nf port verify --post-type=` (errors out with the mismatch list when not zero). Registered in `JobRunner::register()` next to `nf job`.
- Wired `PortVerifyJob` into `Plugin::jobs()`.
- `tests/unit/Migration/PortVerifyTest.php`.

## Tests / tooling
`php -l` clean; `ValueComparer` cases run by hand with plain PHP. phpunit, phpcs and phpstan did not run locally (`composer install` too slow/blocked); CI is authoritative.

## Re-port
`wp nf port run` is idempotent, so it is the re-port: run it again for the post type, then verify. A per-object re-port button belongs to the admin wizard (4.4).

## Deviations / follow-ups
- Verify covers posts only. Terms, users, comments and options (ObjectPortJob) need the same job over those objects: follow-up slice.
- "Zero mismatches on the bench dataset" needs ACF/SCF with the bench dataset in wp-env; not run here. Owner confirms on a real-site copy at G2.
- Edits outside A9 paths: one line each in `src/Plugin.php` and `src/Core/Jobs/JobRunner.php` (wiring), as in 2.6b/2.6c.
- Verify compares raw values; formatted-value parity is the differential suite's job.

## Contract change requested
None.

## Review follow-up (same PR)
Fixed: per-field try/catch in verify, skip Series/Group when ACF returns a non-array, `port run` success line, `docs/cron.md` has `port_verify`.
Known limits (not fixed): numeric-looking strings compare numerically (so "007" equals "7"); no real-ACF run of the Series/Group branch or date/true_false conversions; verify is posts only. These need the wp-env/real-site run before G2.

## Fix 2026-11-16: false mismatches (owner check on nf-dev)

On nf-dev with ACF PRO, `wp nf port verify --post-type=nf_bench_event` reported 20,000 mismatches in 10,000 posts although the data matched (`nf-bench verify`, differential suite). `PortVerifier` diffed ACF's raw storage form against NF's typed form: date picker `20260130` vs `2026-01-30 00:00:00`, time picker `08:30` vs `30600`, Series rows keyed by sub-field key vs by name. The unit tests used fake readers, so they missed it.

Fix: the ACF value is converted the way the port writes it before diffing (`TypeMap::normalize`, `normalizeComposite`, and the new `TypeMap::normalizeRows`, which the bridge now uses too), and a Series with an `on_save` or `on_read` rule is sorted with the same `RowSorter`. New `tests/differential/acf/NfPortVerifyDifferentialTest.php` (SCF in CI, ACF PRO on nf-dev): bridged values verify clean, a sorted Series verifies clean, a changed NF value is still reported; all three fail on the old verifier. nf-dev: 0 mismatches in 10,000 posts (21 s).
