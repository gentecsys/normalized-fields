# WP 3.1d: Planner and explain()

State: in-review. Branch `wp/3.1d-planner`.

## What changed
- `Compiler::compile()` takes optional `$stats` (field name => `rows`, `distinct`; `*` = pivot set object count, from `nf_fields.row_count` / `distinct_count`).
  - Selectivity ordering: each clause carries its own parameters and an estimated share of the set (equality 1/distinct, `in` n/distinct, ranges, null, negations); with stats the clauses are ordered most selective first (stable). Without stats the caller's order is kept.
  - Driver: a slot condition at or below 5% of the set gets `FORCE INDEX` on its slot key through `DialectInterface::indexHint()` (MySQL dialect only). Otherwise, with a LIMIT and one sort key, the sort column's index (`by_published`, `by_modified`, `by_author`, or the slot key) drives and the scan stops at the LIMIT.
  - Deferred join: offset above `Compiler::DEFER_OFFSET` (1000, constructor argument) reads `(pivot_id, object_id)` from the index with the filter, order and page, then joins back to the pivot in that order.
  - `plan` gains `clause_order`, `estimated_rows`, `deferred_join`, `joins`; `driver` now names the index.
- No new classes; planner logic lives in `Compiler` (A4 paths only).

## Tests
`tests/unit/Query/PlannerTest.php`: 7 tests, 21 assertions. `CompilerTest` (8) and `MaskRowCompilerTest` (5) still pass (phpunit 9.6 phar, minimal autoload bootstrap). Not run: WP-bootstrapped suite, phpcs, phpstan; CI is authoritative.

## Deviations / follow-ups
- No contract change.
- `explain()` on the builder (the public array in the design example, incl. `count: rollup:...`) is WP 3.2; it reads `CompiledQuery::plan`. The `count` key needs the counting tier (3.4).
- The caller (3.2) must load `$stats` from the field map (`Storage` has no reader for `row_count`/`distinct_count` yet; A2 follow-up). Statistics maintenance is not in scope.
- Selectivity constants are heuristics; the benchmark WP 3.8 should check choices with `EXPLAIN`.
- No DB-backed test of the deferred join; the corpus is WP 3.3.
- Row-pivot driving for selective repeater filters (design doc) is not done; left for a later slice.
