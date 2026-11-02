# WP 0.1: Repo and plugin skeleton

## What changed
- `nf.php` bootstrap (headers: WP 7.0+, PHP 8.1+), constants, activation hooks.
- `includes/core/requirements.php` (PHP/WP checks + admin notice), `includes/core/bootstrap.php` (fires `nf_loaded`).
- `includes/install/activate.php` (refuses activation on unsupported env; stores `nf_version`), `uninstall.php` (keeps data).
- `tests/unit/core/RequirementsTest.php` (needs WP test bootstrap from WP 0.2).

## Tests / checks that ran
- `php -l` on all files: pass (PHP 8.3).
- NOT run: phpunit, phpcs, phpstan, wp-env activation on WP 7.0/7.1 (no tooling config yet; belongs to WP 0.2). Activation-on-WP check is deferred to WP 0.2 CI.

## Deviations / contract changes
None.

## Follow-ups
- WP 0.2: add composer.json, phpcs/phpstan/phpunit config, wp-env, CI; confirm activation on 7.0/7.1.
