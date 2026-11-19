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

## Review fixes
- Reviewer found silent divergences from core; fixed by passing through: falsy-but-real filters (`meta_value => 0`, `menu_order => 0`), `meta_type`, `posts_per_archive_page`, `page`, negative or non-numeric `paged` / `offset`, negative `post_parent`, extra clause keys (`compare_key`, `type_key`), explicit statuses other than `publish`, and a missing `post_status` when the user can read private posts (`$canReadPrivate`).
- The key callback is now `fn( $key, $postType ): ?string` returning `text`, `number` or `date` for managed single-valued scalar fields. A number field needs a numeric clause `type` and `meta_value_num`; text and date fields need no type (or CHAR) and `meta_value`. Other combinations pass through. `IN` strings split like core (`/[,\s]+/`).

## Deviations and open questions
- Draft, private and other statuses are left to 3.3b, which has the user's capabilities.
- Serialized values (relationship, checkbox) are `null` kind, so they pass through until 3.3c.
- Core's `orderby date` has no tie-break; NF's compiler adds an id tie-break. Watch for this in 3.3d.
- `suppress_filters` handling and third-party `posts_*` filters belong to 3.3b.

## Second review round
- Fixed: `offset => 0` falls back to `paged`; `has_password` / `post_password` pass through whenever set; the constructor flag is now `$loggedIn` (core adds a logged-in user's own private posts when `post_status` is unset); `statuses()` simplified; `number` kind documented as integer-valued fields only (core casts `NUMERIC` to SIGNED), so the 3.3b key callback must not return `number` for decimal fields.
- Not re-reviewed (budget stop rule): the second round of fixes needs a fresh reviewer before merge; the PR is NOT labelled `nf:ready`.
- Open: date value normalisation and `EXISTS` on empty values vs NULL are left to the 3.3d differential corpus.
