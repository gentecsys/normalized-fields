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
