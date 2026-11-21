# WP 3.0i: nf_query parity after rebuild; anonymous read writes nothing

Agent: A3 (daily build agent). Branch `wp/3.0i-parity-anon-get`.

## What changed
- New `tests/unit/Query/RebuildParityTest.php`:
  - `test_queries_are_identical_after_rebuild`: wipes the pivot rows of a set, rebuilds with `rebuildFromCore()`, and asserts `nf_query` ids, order and counts equal the write-through answers (slot condition, sort, between, count).
  - `test_anonymous_read_writes_nothing`: as a logged-out user, `ids()`, `count()`, `toSql()` and `explain()` leave the content of all NF tables unchanged (checksums).
- No source changes.

## Tests
Only `php -l` ran locally (no WP test env here); CI is authoritative.

## Contract change requested
None.

## Follow-ups
- The anonymous check covers the Builder (the public query entry point); REST routes are checked in 3.9.
- The "bench type" is a purpose-built test type, not the 100k bench dataset; dataset-scale parity belongs to 3.8.
