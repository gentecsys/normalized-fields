# WP 3.1c: Compiler, rows and bitmasks

State: in-review. Branch `wp/3.1c-compiler-rows`.

## What changed
- `MaskCondition` (any|all|none) and `RowCondition` (same-row conditions on one Series) added; `QuerySpec` takes `masks` and `rows` (trailing args, defaults empty).
- `Compiler`: optional read-only `Dictionary` (constructor), and `compile( ..., $fields, $rowSlots )`.
  - Bitmask slot (`m*` in the pivot slots; slot `id` needed): one bitwise test via the dialect's `mask()`. Values without a bit are ignored for any/none; for all-of they match nothing. All-of on bit 63 is refused (signed literal vs unsigned column).
  - Multi-valued field with no `m` slot: falls back to typed-table probes (any = `IN`, none = `NOT IN`, all = one probe per value).
  - `RowCondition`: one correlated `EXISTS` on `nf_row_pivot` (pivot_id, field_id = Series, object_id = outer pivot row) with every condition on the same row; no self-joins. Int/dec/date slots take all operators; string (dictionary) slots take = != in not_in null not_null, values turned into dictionary ids by read-only lookup (unknown value matches no row; its negation matches rows that have a value).
  - `plan` gains `rows`; probes from mask fallbacks are listed in `probes`.

## Tests
`tests/unit/Query/MaskRowCompilerTest.php`: 5 tests, 27 assertions; `CompilerTest` 8 tests still pass (phpunit 9.6 phar, minimal autoload bootstrap). Not run: WP-bootstrapped suite, WPCS phpcs, phpstan; CI is authoritative.

## Deviations / follow-ups
- No contract change.
- Mirrored taxonomy (`tax()`) is not in this WP: it needs the mirroring writer (not built yet); left for 3.3 with `tax_query`.
- Rollup prefilter on the object row and driving from `nf_row_pivot` are the planner (3.1d).
- `Pivot::slots()` is the source of slot `id`; no reader exists for row-pivot slots per Series (`RowPivot` has none). The caller (3.2) needs one, in `src/Pivot/` (A3): `RowPivot::slotsFor( pivotId )`.
- Multi-valued sub-fields in rows (`nf_row_pivot.m1`) unused, as in 2.2c.
- DB-backed corpus still pending (3.3).
