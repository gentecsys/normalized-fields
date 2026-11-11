# WP 2.1b: Scalar and multi-valued field types per design table

## What changed
- `Acf\TypeMap::storage()`: `oembed` -> `text`; `checkbox` -> `str` multi; `select` with `multiple` -> `str` multi (was `json`); `page_link` -> `int` (multi when `multiple`).
- `TypeMap::normalize()`: WP_Post / WP_User / WP_Term objects collapse to their ID for `int` tables.
- PLAN: original 2.1b split. Composite types (link, google_map, group, clone) moved to new **2.1c**.

## Tests (added, NOT run here)
- `TypeMapTest` (table cases, multi-select, page_link multiple, object normalisation), `StoreTest::test_multi_str_and_text_round_trip`.
- No vendor/MySQL in this sandbox: only `php -l`. CI is the check.

## Follow-ups
- 2.1c as above. Differential suite for these types belongs to A11.
- Existing data for checkbox/multi-select/oembed written to `nf_json`/earlier tables needs the backfill/rebuild (pre-release, none expected).

## Deviations
- Differential (ACF vs NF) coverage for these types is not added here; it belongs to A11's harness. Tests were not run locally (CI only).
- Reviewer notes carried forward: no Bridge-level `update_field` test for checkbox/multi-select/page_link yet; time-picker strings in formats other than `H:i[:S]` are rejected by `toDb` (Bridge logs `nf/bridge/error`); seconds -> `H:i:s` read-back belongs to the loader/return-format work (2.1c or A5/A6).

## Resume from
CI on PR #29 (head c26de5b) failed on every job twice (initial run and one re-run) with `Temporary failure resolving 'archive.ubuntu.com'` during `apt-get` (exit 100): a GitHub runner network outage, not the code. PHPCS formatting fix was pushed earlier (the PHPCS job passed apart from that). Next: re-run the failed jobs when DNS is back; if green and the reviewer's remaining notes are fine, add the `nf:ready` label to #29.
