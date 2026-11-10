# WP 2.0: Schema upgrades on plugin update

## What changed
- `NF\Install\Upgrader`: compares the per-site option `nf_db_version` with `SCHEMA_VERSION` (2). When lower it runs `Schema::install()` (dbDelta, creates missing tables) and the versioned migrations, then stores the version. Hooked on `plugins_loaded` (priority 5) and `admin_init`; wired in `Plugin::services()`.
- `NF\Install\Migrations\MigrationInterface` (version() / up()); none yet. Migrations run in version order, once per site.
- Activation now goes through `Upgrader::maybe_upgrade()`; `uninstall.php` also deletes `nf_db_version`.
- Tests: `tests/unit/Core/UpgraderTest.php` (drop table, lower version, load, table exists; migration order and run-once).

## Tests
Not run locally: composer dist downloads were blocked, so no phpunit/phpcs/phpstan here (`php -l` only). CI is the check.

## Notes / follow-ups
- Multisite: each site upgrades on its own first load (options are per site). Network-activation loop over existing sites is not added; sites upgrade lazily.
- Raise `Upgrader::SCHEMA_VERSION` whenever `contracts/schema.sql` changes.
