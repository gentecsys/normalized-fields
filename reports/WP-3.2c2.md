# WP 3.2c2: `values()` and `wp_query()`

State: in-review. Branch `wp/3.2c2-values`.

## What changed
- `QueryContext::values( $spec, $fields )` and `Builder::values( array $fields )`: ids from the pivot query, then one read per field on its typed table (`object_id IN (...)`), with 191-char `nf_str` prefixes replaced from `nf_text` in one batch. Rows are `id` plus the fields: scalars typed through `DataTable::fromDb()`, multi-valued fields as lists, missing as null or `[]`. Unknown fields and Series or group fields throw `InvalidArgumentException`.
- `Builder::wp_query()`: a real `WP_Query` answered through a one-shot `posts_pre_query` filter. It returns the hydrated posts and sets `found_posts` (live `count()`) and `max_num_pages` (from the limit). Throws `LogicException` for term and user queries.
- Tests in `tests/unit/Query/QueryContextTest.php`: projection and order, empty type, unknown field, `wp_query()` totals and pages, wrong target.

## Tests run
Only `php -l`. No vendor tooling in this sandbox; phpunit, phpcs and phpstan are left to CI.

## Deviations and follow-ups
- "ACF-formatted" values in the design doc are not applied: values are typed as stored. Formatting needs the ACF layer (3.6); it is the follow-up.
- Off-type values (arrays in a scalar field, stored in `nf_json`) are not projected; `values()` returns null for them.
- `wp_query()` is the builder-driven path. The `WP_Query` args translation (the existing-theme path) is WP 3.3.
- Not in this WP: `search`, `tax`, `after()`/`chunk()` (3.2c3).

## Review fixes
- `wp_query()` sets `found_posts` and `max_num_pages` itself after the short-circuit (CI showed core does not count after `posts_pre_query`; no `FOUND_ROWS`), passes `offset` and the builder's statuses, reports the real total for an empty deep page, and maps count mode `none` to `no_found_rows`. Count mode `capped` is treated as `exact` for now (deviation; capping arrives with the counting tier, 3.4).
- Follow-ups: `values()` has no core columns (`title`) or dotted relation paths, and does not chunk very large id lists; spill, multi-valued and decimal/date tests are still to add.
