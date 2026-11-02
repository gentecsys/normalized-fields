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

## Reviewer (A13) result
- Blocking: "Activates cleanly on WP 7.0/7.1" unverified (no tooling until WP 0.2).
- Fixed: pre-release WP versions (nightly) no longer rejected; test made timing-independent.
- Follow-ups: negative-path tests, textdomain loading, multisite uninstall, WPCS test file naming.
- PLAN.md state edit was made by A1 on its branch; A0 to confirm.

## Resume from
State `in-review`. The merge was blocked in the sandbox (auto-mode denied a merge without a clean re-review). Next run: re-run reviewer once on the updated branch, then decide whether to merge with the activation check formally deferred to WP 0.2 CI, or wait for WP 0.2. Branch and PR #1 are up to date.
