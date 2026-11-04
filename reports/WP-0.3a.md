# WP 0.3a: Dataset generator (model, ACF store, WP-CLI)

WP 0.3 was split: `contracts/schema.sql` is still a stub (WP 0.5) and the NF store does not exist (WP 1.1), so the NF writer is **0.3b** (depends on 1.1). 0.3a delivers everything that can be built and verified today.

## What changed
- `bench/src/Dataset.php`: deterministic event catalogue (seed, posts, start ID), order-independent per-record hashing, 16 top-level fields (12-value category checkbox, 1-4 artist relationship, 9 text fields) and a 5 x 3 `sessions` repeater, as in the design doc benchmark. `fingerprint()` = sha256 of the logical records; both stores must reproduce it.
- `bench/src/AcfMeta.php`: record to ACF `wp_postmeta` rows (value row plus `_name` key row, serialized checkbox/relationship, `sessions_N_sub`).
- `bench/src/StoreWriter.php` (interface for ACF and later NF), `bench/src/AcfStore.php` (chunked writes to core tables, `existing/clear/write/verify`).
- `bench/src/DatasetCommand.php` + `bench/bootstrap.php`: `wp --require=bench/bootstrap.php nf-bench generate|verify|fingerprint --posts=10000 --seed=1 --start-id=1000000 [--force]`. Not loaded by the plugin.
- `tests/unit/Bench/DatasetTest.php`.
- `phpcs.xml.dist`: added `bench` to the scanned paths and excluded it from the PSR-4 file-name rule.

## Tests that ran
- `php -l` on all new files: pass.
- PHPUnit 9.6 phar (no WP bootstrap) on `DatasetTest`: 5 tests, 1811 assertions, pass.
- NOT run: phpcs (WPCS download blocked, 403), phpstan, `wp nf-bench` against a real database (no WP install in the sandbox), 100k generation timing. CI is authoritative for phpcs/phpstan and the unit test on the matrix.

## Deviations
- 64 `wp_postmeta` rows per post, not 66 as in the design doc's benchmark (16 fields x 2 + repeater count x 2 + 15 sub-fields x 2). The extra two in the doc are presumably WP housekeeping meta. Ratios are the same.
- Artist IDs are 1..500 reference values, not real posts (fine for relationship filter benchmarks; the NF store must not require existence).
- Edited `phpcs.xml.dist` (A1-owned) minimally so `bench/` is linted. Tests live in `tests/unit/Bench/` so the existing PHPUnit config runs them.

## Follow-ups
- 0.3b: NF writer once 1.1 lands; `nf-bench verify --store=nf` must compare against `Dataset::fingerprint()`/records.
- Real-database run of `generate` at 10k/100k to record timing (needs wp-env or CI job); add to a bench CI job in 3.8.
- `wp_posts` rows use `post_type = nf_bench_event` and explicit IDs; the NF store must use the same IDs.

## Resume from
Nothing pending unless review finds issues.

## Status
CI green, reviewer clean. Merge via REST was denied by the auto-mode classifier; owner (or next run) to merge PR #8, then set 0.3a to merged in PLAN.md.
