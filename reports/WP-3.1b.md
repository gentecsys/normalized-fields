# WP 3.1b: Compiler, typed-table probes

State: in-review. Branch `wp/3.1b-compiler-probes`.

## What changed
- `Compiler::compile()` takes an optional `$fields` map (top-level `FieldDef` by name). A condition on a field with no pivot slot, or whose slot is a dictionary string slot, compiles to a semi-join probe by primary key: `object_id [NOT] IN (SELECT object_id FROM nf_<table> WHERE object_type = ? AND field_id = ? AND value ...)`. Probes sit beside pivot conditions in the same statement; no joins, values bound.
- Tables probed: int, dec, date, str. text/json/rows/none, strings over 191 characters (`=`/`!=`) and unknown fields throw `UnsupportedQueryException`.
- Semantics: single-valued `!=`/`not_in` need a matching row (as `WP_Query` does); multi-valued `!=`/`not_in` mean "no value matches" (`NOT IN`); `null` = no row, `not_null` = a row. A reverse relationship ("events using venue 123") is `venue = 123` on the multi int field; `whereRelated` is builder sugar for 3.2.
- The shared predicate builder replaces the inline switch; pivot SQL is unchanged. `plan['mode']` is `pivot+probe` with a `probes` list.

## Tests
`tests/unit/Query/CompilerTest.php`: 8 tests, 30 assertions pass (phpunit 9.6 phar, minimal bootstrap). Not run: WP-bootstrapped suite, WPCS phpcs, phpstan (composer vendor unavailable); CI is authoritative.

## Deviations / follow-ups
- No contract change.
- **Not done, left for later in 3.1:** the DB-backed corpus against `WP_Query` named in the PLAN row, and the ACF `LIKE '"id"'` translation (that is the `WP_Query` integration, 3.3). Corpus needs a DB suite; take it with 3.1c/3.1d or 3.3.
- Sorting by a probe-only field and driving from a probe are 3.1d (planner).
- Callers must build the `$fields` map from `FieldMap`; no wiring exists until 3.2.
- Review round: str guard now covers in/not_in/between and rejects ordering ops; sub-fields rejected; PLAN row text and 3.3 updated for the deferred corpus/LIKE work.
