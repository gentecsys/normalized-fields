# WP 3.2c1: hydration and `whereRelated`

State: in-review. Branch `wp/3.2c-results`.

3.2c was split in PLAN.md into 3.2c1 (this), 3.2c2 (`values()`, `wp_query()`) and 3.2c3 (`search`, `tax`, `after()`/`chunk()`); 3.5 and 3.9 now depend on 3.2c3.

## What changed
- `Builder::posts()`: ids, one `_prime_post_caches()`, `WP_Post[]` in query order, unresolved ids dropped.
- `Builder::terms()` (`get_terms` with `include` + `orderby=include`) and `Builder::users()` (`get_users`, same ordering). Each throws `LogicException` on the wrong target type.
- `Builder::whereRelated( $field, $id )` = `where( $field, '=', $id )`; id below 1 throws.
- Tests added to `tests/unit/Query/QueryContextTest.php`: post order, empty type, wrong-target errors, `whereRelated`.

## Tests run
Only `php -l`. No vendor tooling in this sandbox; phpunit, phpcs and phpstan are left to CI.

## Review fixes
- `users()` passes `blog_id => 0` (network-wide users, ADR-0011); doc nits; users() wrong-target test.

## Follow-ups
- Term and user hydration have no dedicated DB-backed test (the term/user pivot setup is covered with 3.2c3's design-doc examples).
- Field lookup is still by top-level name across collections (see WP-3.2b).
