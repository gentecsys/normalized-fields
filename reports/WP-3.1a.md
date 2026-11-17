# WP 3.1a: Compiler, pivot tier

State: in-review. Branch `wp/3.1a-compiler-pivot`.

## What changed
- WP 3.1 (XL) split in `PLAN.md` into 3.1a (this) pivot tier, 3.1b typed-table probes, 3.1c rows and bitmasks, 3.1d planner and `explain()`. Dependants of 3.1 (3.2, 3.3, 3.4) now depend on 3.1d. 3.0b marked merged (PR #59).
- `src/Query/`: `Condition` (field, op, value; ops `= != < <= > >= between in not_in null not_null`), `QuerySpec` (immutable input shared by the builder and the WP_Query translation), `CompiledQuery` (sql, params, count statement, plan array, `toSql()`), `UnsupportedQueryException`, `Compiler`.
- `Compiler::compile( $spec, $pivotId, Pivot::slots() )` emits one statement on `nf_pivot` through the dialect: quoted identifiers, `nullsOrder()` with explicit NULLS placement, `limit()`, bound parameters, no implicit casts (values are validated and converted by the slot's type; ACF `Ymd` dates accepted), `object_id` tie-break, no joins, no `$wpdb`. Core columns `date modified author parent menu_order ID` map to the mirrored pivot columns.
- Fields without an int/dec/date slot, or values that do not fit the type, throw `UnsupportedQueryException`, for 3.1b/3.1c to answer.

## Tests
- `tests/unit/Query/CompilerTest.php` (pure, stub backend + MysqlDialect): 5 tests, 17 assertions. Ran with a phpunit 9.6 phar and a minimal autoload bootstrap: OK. Not run here: the WP-bootstrapped suite, phpcs, phpstan (composer vendor unavailable); CI is authoritative.

## Deviations / contract
None. No contract change. Unchanged: `SpikeQuery` stays until 3.3 replaces it.

## Resume / follow-ups
- 3.1b next: typed-table probes, building on `Compiler` (add a driver/probe stage; keep `UnsupportedQueryException` for what remains).
- Corpus against `WP_Query` (differential) starts in 3.1b, since 3.1a has no DB-backed test.
- Review round: DECISIONS entry added; offset-without-limit, >18-digit integers and lists over 1000 now throw UnsupportedQueryException. Open for 3.1b: NULL semantics of != / not_in.
