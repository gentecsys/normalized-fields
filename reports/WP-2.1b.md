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
