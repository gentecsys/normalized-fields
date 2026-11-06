# WP 0.6b: Differential harness against real ACF (SCF) in CI

## What changed
- `bin/install-scf.sh`: downloads Secure Custom Fields (free ACF fork, includes the repeater) from wordpress.org.
- `tests/differential/acf/bootstrap.php`: WordPress test suite + SCF + bench fixtures (post type, Local JSON from `bench/acf-json`).
- `tests/differential/acf/AcfBaselineTest.php`: writes a 40-post bench dataset with `AcfStore`, checks real ACF reads it (incl. 5-row `sessions` Series), then runs `BenchReadScenario` (`get_field` formatted and raw, `get_fields`, all 17 fields + `sessions`) through `GlobalFunctionTarget` on both targets; asserts zero diffs. Right target flushes the object cache on reset so it re-reads from the DB.
- `tests/differential/src/BenchReadScenario.php`: the scenario.
- `phpunit-acf.xml.dist` and a new CI job `differential` (WP 7.0, PHP 8.3, MySQL 8.0): pure-PHP harness tests, then the SCF baseline.

## Tests that ran
- `php -l` on the new files; scenario step generation smoke test (10 steps for 2 posts x 2 fields).
- NOT run locally: the SCF baseline (needs WordPress test lib + DB + SCF download), phpcs, phpstan. CI is authoritative.

## Deviations
- Uses the existing WP PHPUnit test-lib setup instead of wp-env (same environment as the CI matrix, much cheaper). `.wp-env.json` untouched. 40 posts, not the full 10k/100k, to keep CI fast; the generator is the same code.
- Files outside A11 paths: `.github/workflows/ci.yml`, `bin/install-scf.sh`, `phpunit-acf.xml.dist` (platform-owned, needed for the CI job). For A0 to confirm.

## Resume from
If CI is red on the `differential` job: check that SCF is auto-loadable under the test lib (`acf/settings/load_json` filter registered after `require`, post type registered on `init` priority 0) and fix in `tests/differential/acf/bootstrap.php`.

## Follow-ups
- Only ACF-vs-ACF; the NF target joins in 1.4/3.6. Writes (`update_field`) are 0.6c.
- SCF is `latest-stable`, so a SCF release could change output; pin a version if it flakes.
