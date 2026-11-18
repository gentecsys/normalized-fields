# WP 3.2b: Builder resolver and `nf_query()`

State: in-review. Branch `wp/3.2b-resolver`.

## What changed
- `NF\Query\QueryContext`: resolves, per `type|subtype` (memoised), the pivot set id, slots, top-level fields (for typed-table probes), row slots per Series name and field statistics (`nf_fields.row_count/distinct_count`, plus `*` = pivot object count) through `nf_db()` only. Runs `compile()`, `ids()`, `count()` (live, ignores limit/offset) and `explain()` (compiler plan + the database's `EXPLAIN` rows). Read only; no writes, so safe for anonymous requests. A type with no pivot set returns empty results (`toSql()` is `''`).
- `Builder` takes an optional `QueryContext` and gains `toSql()`, `explain()`, `ids()`, `count()`. Without a context they throw `LogicException` (spec-only builders stay pure).
- `nf_query( $target )` in `src/functions/nf.php`; `Plugin::queryContext()` wires it.
- Tests: `tests/unit/Query/QueryContextTest.php` (WP-bootstrapped, real tables): ids/order/limit/count on a slot, probe on an unslotted field, status filter, unknown type, `toSql`/`explain`, `nf_query()`, no-context error.

## Tests run
Only `php -l` here. Not run locally: phpunit (WP suite), phpcs, phpstan; CI is authoritative.

## Deviations / follow-ups
- No contract change. The "design-doc examples compile as tests" criterion is covered for the shapes the compiler supports today; examples using `whereRelated`, `search`, `tax`, `with`, `after` belong to 3.2c.
- `NF::query()` alias (class `NF`) not added; it comes with the ACF `NF` shim class (3.6) or 3.2c.
- `stats['*']` uses `COUNT(*)` on `nf_pivot` per set, memoised per request; if costly at 1M objects, move to a cached counter (3.4).
