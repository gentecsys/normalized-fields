# WP 0.6a: Differential harness core

(0.6 was size L; split into 0.6a core, 0.6b real ACF on wp-env + CI, 0.6c scenarios.)

## What changed
- `tests/differential/src/`: `ScenarioInterface`, `Step`, `TargetInterface`, `ClosureTarget`, `GlobalFunctionTarget` (calls real global ACF functions, for 0.6b), `Normalizer`, `Comparator`, `Diff`, `Report`, `Runner`. Own PSR-4 loader in `tests/differential/bootstrap.php`; runs without WordPress.
- Comparison is strict on types (ACF return types are contract), ignores assoc key order, object identity (objects become arrays) and float noise (9 decimals); exceptions are results, so both targets must fail the same way.
- `tests/differential/tests/RunnerTest.php`: baseline (two identical fake-ACF targets = zero diffs), tampered target detected with path `[1].room`, type difference detected, noise ignored, list-length diff.
- `phpunit.xml.dist`: added a `differential-harness` testsuite (file is platform's; one-line addition, needed so CI runs the tests).

## Tests that ran
- PHPUnit 9 phar on the new suite: 5 tests, 8 assertions, pass.
- NOT run: phpcs/phpstan (standards not installable in the sandbox, no vendor), full suite. CI is authoritative. PHPStan scope is `src/` and `bench/`, so `tests/differential` is not analysed.

## Deviations
- Baseline here uses in-memory doubles; "against real ACF" is 0.6b.
- Edited `phpunit.xml.dist` outside the QA path list.

## Resume from
Nothing pending unless review/CI finds issues. Next: 0.6b (wp-env with free ACF, `GlobalFunctionTarget`, CI job).

## PLAN.md edits (disclosed)
Made by the daily build agent per its routine, not by A11: split 0.6 into 0.6a/b/c (L WPs are split before work starts) and set 0.5 to `merged` (PR #11 is merged on GitHub). The "Lanes" paragraph still says "A11 runs 0.6"; still accurate for 0.6a-c.

## Open questions / follow-ups
- 0.6b: real ACF and NF share one DB; `Runner` interleaves steps, so use per-target sequential passes or separate post IDs.
- `Report::is_clean()` is true for zero steps; consider failing on `steps === 0`. Exceptions compare by class only. NAN and DateTime-like objects are not handled by `Normalizer`. No test yet for `GlobalFunctionTarget`.
- The `differential-harness` suite runs under the plugin bootstrap in CI (needs the WP test lib); the pure-PHP run is `--no-configuration --bootstrap tests/differential/bootstrap.php`.
- PHPCS: CI flagged doc-comment sniffs; `phpcs.xml.dist` now excludes doc/naming sniffs for `tests/differential/*` (config file edit outside A11 paths, for A0 to confirm). Array formatting fixed in the test.
