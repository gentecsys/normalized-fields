# WP 2.1a: Type map per design doc + long-text spill

## What changed
- `Acf\TypeMap::storage()`: `time_picker` -> `int` (seconds since midnight), `date_time_picker` -> `date`, `text` -> always `str` (no more `maxlength` switch to `nf_text`). This is the design doc's table and matches `bench/src/NfLayout.php`.
- `TypeMap::normalize()`: an `HH:MM[:SS]` string bound for an `int` table becomes seconds.
- `Storage\Store`: a `str` value over 191 chars keeps its 191-char prefix in `nf_str` and the full value in `nf_text` (same key). Reads swap the prefix for the full value (only checked when a value is 191 chars long); `set`/`delete`/Series delete/`deleteObject` also clear `nf_text`.
- `DataTable::toDb( $value, $allowLong )`: the Store passes `true` for the spill; direct callers still get the 191-char error.

## Tests (added, NOT run here)
- `tests/unit/Acf/TypeMapTest.php`, new `StoreTest` cases (spill incl. 191-char boundary, update/delete cleanup, Series row spill); `test_validation` now uses a non-numeric int; `BridgeTest::test_type_map` expects `text` in `str`.
- No MySQL or `vendor/` in this sandbox, so PHPUnit/PHPCS/PHPStan did not run; only `php -l`. CI is the check.

## Notes / follow-ups
- Reading time/date-time values back into ACF's display form (`H:i:s`) belongs to the loader/return-format work (2.1b/A5); the Bridge only writes.
- Existing sites that mirrored `text` fields in `nf_text` or time pickers in `nf_str` need the backfill/rebuild to move them; no migration added (pre-release, G1 data is spike data).
