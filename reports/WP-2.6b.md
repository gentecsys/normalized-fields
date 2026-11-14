# WP 2.6b: Port, value reader and writer for posts

Agent: A9 (daily build agent). Branch `wp/2.6b-port-posts`.

## What changed
- `NF\Migration\AcfSource`: feeds the scanner through ACF's API (`acf_get_field_groups()` + `acf_get_fields()`, so DB, acf-json, PHP registrations and expanded seamless clones); `portableFields()` returns the top-level fields of collections the scan marks portable (flexible content collections skipped, ADR-0008).
- `NF\Migration\AcfCopier`: per post and top-level field, copies the raw value (`acf_get_value()`, no formatting) through the public `Bridge` handlers (Series, group/clone, scalar), only when ACF holds a value (its `_name` reference matches the field key or source key). Same path as a live ACF save, so storage, pivots and the 2.4 rules stay in one place.
- `NF\Migration\PostPortJob` (key `port_posts`, args `post_type`, `chunk` default 50): keyset walk over `wp_posts` IDs (core table via `$wpdb`, auto-drafts skipped), one chunk per step, state holds `after`, `posts`, `values`, `error`. Stops with `error` = `acf_unavailable` or `no_post_type`. Runs on `JobRunner` (refuses while schema is behind). Registered in `Plugin::jobs()`; start with `wp nf job run port_posts --args='{"post_type":"post"}'`.
- `tests/unit/Migration/PostPortJobTest.php`: chunk resume, flexible skip, counts, empty type, error states, using doubles (no ACF).

## Tests / tooling
No `vendor/` or WP test suite locally; `php -l` passes. CI is authoritative. The ACF-backed path (`AcfCopier`, `AcfSource::groups`) is not unit-tested here; it is covered by the 2.6d verify (bench dataset) and the existing bridge tests.

## Deviations / follow-ups
- `AcfCopier` repeats the per-field switch of the private `Bridge::resyncObject()`. Follow-up for A5: expose a public per-field copy on `Bridge` and drop the duplicate.
- Deletes of NF values with no ACF counterpart (re-port cleanup) are left to 2.6d (re-port).
- Fields cloned in with a different parent owner (`ownedByOther`) are not specially handled.
- Report counts (objects/values, orphaned meta, options pages, raw-SQL scan) still planned for 2.6c.

## Contract change requested
None.
