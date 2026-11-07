# WP 0.3b: Dataset generator, NF store writer

Agent: A12 (daily build agent). Branch `wp/0.3b-nf-store-writer`.

## What changed
- `bench/src/NfLayout.php`: pure mapping from dataset values to NF typed tables (dates as `DATETIME`, categories as `str` multi, artists as `int` multi, text fields as `str`, Series sub-fields `date`/`time`/`room`) and back.
- `bench/src/NfStore.php` (`StoreWriter`): registers the fields with `FieldMap` using the ACF keys (`field_nfbench_*`), writes `wp_posts` with `$wpdb` (core table) and all values and `nf_rows` through `nf_db()` in bulk (100 posts per transaction), and `verify()` reads back in batches (one query per table per chunk) and compares with the dataset record.
- `wp nf-bench generate|verify --store=nf` added; `bench/bootstrap.php` loads the new classes.
- `tests/unit/Bench/NfLayoutTest.php`: round trip for every field of 20 records, date form, multi-value order.

## Tests that ran
- `php -l` on new files: pass.
- PHPUnit 10 phar, `NfLayoutTest` without WP: 3 tests, 984 assertions, pass.
- NOT run: phpcs, phpstan, and `wp nf-bench generate --store=nf` / `verify` against a real database (no WP + MySQL here). CI is authoritative; the bulk insert path and `verify()` are unverified against MySQL.

## Deviations / notes
- Both stores use the same IDs and `Dataset` records, so the shared fingerprint is the logical dataset's; `verify` for the NF store compares against it record by record.
- Done-when "10k, 100k" generation not exercised here (needs a real site); owner or WP 1.5 benchmark run will confirm.
- `nf_dec` is unused: price and capacity are integers in the dataset.

## Follow-ups
- Run `generate --store=nf --posts=10000` then `verify` in wp-env (add to CI bench job in 1.5 / 3.8).
- Pivot tables are not written (WP 1.3).

## Contract change requested
None.
