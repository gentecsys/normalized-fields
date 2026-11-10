# WP 2.0: Schema upgrades on plugin update

## What changed
- `NF\Install\Upgrader`: compares the per-site option `nf_db_version` with `SCHEMA_VERSION` (2). When lower it runs `Schema::install()` (dbDelta, creates missing tables) and the versioned migrations, then stores the version. Wired in `Plugin::services()`; see "Upgrade context" below for when it runs.
- `NF\Install\Migrations\MigrationInterface` (version() / up()); none yet. Migrations run in version order, once per site.
- Activation now goes through `Upgrader::maybe_upgrade()`; `uninstall.php` also deletes `nf_db_version`.
- Tests: `tests/unit/Core/UpgraderTest.php` (drop table, lower version, load, table exists; migration order and run-once).

## Upgrade context (owner review, fix/2.0-upgrade-context)
The first version upgraded on every request at `plugins_loaded`, so a visitor's logged-out GET could run `CREATE`/`ALTER TABLE` and write an option (breaks D-18), and a traffic burst after an update ran dbDelta in parallel. Now:
- Runs only from trusted contexts: activation, WP-CLI (at load, plus `wp nf upgrade`), WP-Cron (this also covers auto-updates), and on `init` when the current user can `activate_plugins` (covers wp-admin, REST saves and the front end). `admin_init` alone was not used: logged-out `admin-ajax.php` fires it, and REST saves never reach it.
- An atomic lock: `add_option( 'nf_upgrade_lock' )` (INSERT on the unique `option_name`), released in `finally`; a lock older than 10 min is taken over once. The version is re-read after taking the lock.
- `Upgrader::isCurrent()`; `Bridge::flush()` skips its writes while the schema is behind (fires `nf/bridge/schema_behind`) instead of touching missing tables. ACF still holds the value; the backfill (later WP) catches up.
- Note: `wp-cron.php` is public, so any client can trigger cron and with it the one-time upgrade. That is WordPress's model; the lock and the version check keep it to one bounded run per schema version.

## Tests
- `UpgraderTest`: anonymous `init` and editor do not upgrade; admin does; a fresh lock blocks; a stale lock is taken over and released; migration order; dropped table recreated. `tear_down` deletes the lock and COMMITs, because the DDL in the drop-table test commits implicitly and the lock row would otherwise survive the test's rollback.
- `BridgeTest`: sets `nf_db_version` in `set_up`; new `test_schema_behind_skips_write`.
- nf-dev (owner's LocalWP, ACF PRO), with `wp_nf_pivot_sets` dropped and `nf_db_version=1`, checked with the raw MySQL client so NF does not load: front-end GETs, `/wp-json/` and logged-out `admin-ajax.php` leave it untouched (a temporary mu-plugin probe confirmed NF loads on those requests); `wp-cron.php` and `wp nf upgrade` recreate it; with a fresh lock `wp nf upgrade` refuses, with a stale lock it upgrades.
- `run-checks.sh` twice in a row on the same DB: 65 PHPUnit, 7 ACF PRO differential, verify 10k, smoke 0 diffs.

## Notes / follow-ups
- Multisite: each site upgrades on its own first load (options are per site). Network-activation loop over existing sites is not added; sites upgrade lazily.
- Raise `Upgrader::SCHEMA_VERSION` whenever `contracts/schema.sql` changes.
