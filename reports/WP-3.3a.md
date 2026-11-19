# WP 3.3a: `WP_Query` args translator

State: in-review. Branch `wp/3.3a-args-translator`.

## What changed
- Split WP 3.3 (L) in `PLAN.md` into 3.3a (translator), 3.3b (`posts_pre_query` short-circuit and pagination parity), 3.3c (ACF `LIKE` relationship translation, `nf` argument, single-join mode) and 3.3d (DB-backed corpus moved from 3.1b).
- `NF\Query\ArgsTranslator::translate( array $args ): ?QuerySpec`. Pure; it takes a callable that says whether NF manages a meta key.
  - Translates: single `post_type`; named `post_status` list; `author`; `post_parent`; flat AND `meta_query` clauses on managed keys (`= != < <= > >= BETWEEN IN NOT IN EXISTS NOT EXISTS`); `orderby` of `date`, `modified`, `menu_order`, `parent`, `ID`, `meta_value(_num)` with `meta_key`; paging as in core (`offset` overrides `paged`, `-1` and `nopaging` mean no limit, `page` for static front pages).
  - Returns null (pass through to core) for anything else: `s`, `tax_query`, `post__in` and friends, OR or nested meta queries, `LIKE` and `REGEXP`, unmanaged keys, `orderby` of `title`, `rand` and so on, `any` as type or status, several post types. Unknown non-empty arguments also pass through.
- Tests: `tests/unit/Query/ArgsTranslatorTest.php`.

## Tests run
Only `php -l`. No vendor tooling in this sandbox; phpunit, phpcs and phpstan are left to CI.

## Follow-ups
- 3.3b wires the translator to `posts_pre_query` and does the counting.
- Not yet handled: sticky posts, taxonomy mirrors (`tax_query`), several post types, `meta_value` type casts (`type` is validated but the compiler uses the field's own type), per-clause `orderby` arrays.
- `meta_key` with no `orderby` is treated as an existence filter, as in core.
