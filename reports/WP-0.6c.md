# WP 0.6c: Differential scenarios for the spike types

## What changed
- `tests/differential/src/functions.php`: self-resetting probes `write_read_delete()` and `overwrite_read()` (delete, write, read formatted and raw, delete, read; and write A, write B, repeat write for the unchanged flag). Self-resetting, so both targets answer identically although the baseline shares one database.
- `tests/differential/src/WriteRoundTripScenario.php`: scalar (text with HTML/unicode, number, date, true/false, checkbox), relationship (real post IDs), Series `sessions` (2 rows, 1 row, empty), empty relationship, plus `get_fields`.
- `tests/differential/acf/AcfWriteBaselineTest.php`: ACF-vs-ACF baseline on 3 bench posts plus 3 real artist posts; asserts zero diffs and that the probe returns what was written.
- `tests/differential/tests/WriteRoundTripScenarioTest.php`: pure-PHP step-list test.
- `tests/differential/acf/bootstrap.php`: loads `functions.php`.

## Tests that ran
- Pure-PHP `differential-harness` suite via phpunit phar: 6 tests green; `php -l` on new files.
- NOT run locally: the SCF baseline (needs WP test lib + DB + SCF), phpcs (WPCS not installable), phpstan. CI is authoritative.

## Deviations / notes
- Write steps use composite probes (namespaced functions) rather than bare `update_field` steps, because ACF returns false on an unchanged re-write, which would diff between left and right on a shared DB.
- Closes the 0.6b follow-up: `artists` now targets real posts.

## Follow-ups
- When the NF target arrives (1.4/3.6), it needs an isolated store per target (or a reset hook) instead of the shared DB.
- SCF version still unpinned.

## Resume from
If the `differential` CI job is red, check the first diff in the failure message: likely ACF returns for Series empty writes or `delete_field` on never-set fields.

## Review fixes (A13)
- B1: probes now write/delete by field key (`AcfMeta::key()`), since Local JSON fields have no DB post to resolve by name; the probe test asserts `_sessions` reference meta and `sessions_0_room` row meta exist (typed write). Unverified until CI runs.
- N1 real checkbox choices; N3 full field list and step count asserted; N4 header note that runners must require `functions.php`.
- Open question: whether the typed-write assertion holds on SCF; check in CI.
- N2: the baseline only proves ACF determinism; it cannot catch NF write bugs until the NF target exists.
