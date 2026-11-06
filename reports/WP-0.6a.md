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
