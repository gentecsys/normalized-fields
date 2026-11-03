# WP 0.2: Dev environment and CI

## What changed
- `composer.json` (phpunit, polyfills, WPCS, PHPCompatibilityWP, phpstan + wordpress extension), `phpcs.xml.dist`, `phpstan.neon.dist` (level 6), `phpunit.xml.dist`.
- `tests/unit/core/bootstrap.php`: loads the WP test suite and `nf.php`.
- `bin/install-wp-tests.sh`: installs WP 7.0 / 7.1 / nightly test suite against a DB.
- `.wp-env.json` + `package.json` (`@wordpress/env`) for local dev.
- `.github/workflows/ci.yml`: lint job (PHPCS, PHPStan) and a test matrix: PHP 8.1-8.4 x WP 7.0/7.1/nightly x DB. Each PHP version runs on two DBs, so all four DBs run on every WP version and 24 jobs total (not the full 48 product).

## Tests / checks that ran
- JSON/YAML validity of all configs, `php -l`, `bash -n`: pass.
- NOT run: composer install (GitHub dist downloads blocked, 403), phpcs, phpstan, phpunit, wp-env (skipped, CI is authoritative). The first CI run is the real check; expect to fix WPCS findings in the WP 0.1 files, `composer.lock` is not generated (CI resolves).

## Deviations
None. Uses the WP test suite install script rather than wp-env for CI so MySQL/MariaDB versions can be matrixed.

## Open questions
- WP 7.1 tarball may not exist yet at wordpress.org; CI job would fail at download. Confirm availability on first run.
- Full 48-job product vs the 24-job pairing: owner to confirm.

## Follow-ups
- Commit `composer.lock` once generated.
- Fix any phpcs/phpstan findings on first CI run.
