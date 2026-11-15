# WP 2.6c: Port, terms, users, comments, options

Agent: A9 (daily build agent). Branch `wp/2.6c-port-objects`.

## What changed
- `NF\Migration\ObjectPortJob` (key `port_objects`, args `kind` = term|user|comment|option, `chunk`): same resumable keyset walk as `PostPortJob`, using ACF object IDs (`term_5`, `user_3`, `comment_7`, `options`). Options pages are ported in one step (`options` plus each `acf_get_options_pages()` `post_id`). State counts `objects`, `values` and `empty` (objects with no ACF value). Core tables via `$wpdb` only to list IDs. Registered in `Plugin::jobs()`.
- `AcfCopier::copy()` now takes `int|string` object IDs; the test double follows.
- `docs/cron.md`: `port_objects` example.
- `tests/unit/Migration/ObjectPortJobTest.php`: chunk resume, ID prefixes, options single step, errors.

## Tests / tooling
No `vendor/` or phpcs/phpstan run locally; `php -l` passes on all touched files. CI is authoritative.

## Review round
- Custom options page IDs the bridge cannot decode are skipped and counted in `unsupported` (no inflated `values`). Added a real-table lister test and decode test. `JobRunner` enforces `Upgrader::isCurrent()` (no NF write while the schema is behind).
- The PLAN row's remaining items (ACF post type / taxonomy / options-page definitions, orphaned meta, raw-SQL scan) are split into new WP 2.6c2.

## Deviations / follow-ups (left for 2.6d or A0)
- Not in this slice: porting ACF post types / taxonomies / options pages definitions, orphaned-meta counts and the raw-SQL scan in the report. The job state gives object and value counts only.
- Custom options page `post_id` values other than `options` are decoded as unsupported by `Bridge::decode()`; they are visited but copy nothing until A5 extends it.
- Network-wide user values rely on the Bridge/Store ADR-0011 handling; not tested end to end here.

## Contract change requested
None.
