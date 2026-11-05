# WP 0.3c: Dataset parity with the design benchmark

## What changed
- `bench/src/Dataset.php`: 10 text fields (was 9) so a post has 17 scalar fields + `sessions` = **66 `wp_postmeta` rows**, as in the design doc (0.3a had 64). Fingerprints change vs 0.3a; regenerate any existing dataset.
- `bench/src/AcfSchema.php`: ACF field group `group_nfbench_events` (keys `field_nfbench_*`) with return formats: date (`Ymd`), number, true/false, 12-choice checkbox (array of values), relationship (IDs), `sessions` Series with date/time/room sub-fields.
- `bench/acf-json/group_nfbench_events.json`: Local JSON generated from `AcfSchema::json()`; a unit test keeps them identical.
- `bench/bootstrap.php`: after WP loads, registers the `nf_bench_event` post type and adds `bench/acf-json` to `acf/settings/load_json`.
- `bench/src/AcfStore.php`: `verify()` batched (2 queries per 200 posts instead of 2 per post).
- `tests/unit/Bench/DatasetTest.php`: shape (17 fields, 66 rows), schema/key parity, JSON sync.

## Tests that ran
- `php -l` on all changed files: pass.
- PHPUnit 9.6 phar without WP bootstrap on `DatasetTest`: 8 tests, 1836 assertions, pass (fingerprint test skipped, needs WP).
- NOT run: phpcs, phpstan, `wp nf-bench` or `get_field()` against a real WP + ACF (no WP install here). CI is authoritative.

## Deviations
- Relationship `post_type` is `post`; artist IDs are reference values without real posts, so the editor UI would show none. `get_field()` with `return_format: id` still returns the IDs.

## Follow-ups
- Real-site check that `get_field()` returns the formatted values (needs wp-env with ACF; belongs with the 0.6 harness).

## Resume from
Nothing pending unless review finds issues.
