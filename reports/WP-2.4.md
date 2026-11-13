# WP 2.4: Meta API interception

Agent: A5 (daily build agent). Branch `wp/2.4-meta-interception`.

## What changed
- `src/Acf/MetaInterceptor.php`: filters `get_/add_/update_/delete_{post,term,user,comment}_metadata` answer NF-managed top-level field names from `Store`.
  - Scalar and multi-valued fields: raw (string) shape, list for multi-valued, `''`/`[]` when empty.
  - `_name` reference rows: read returns the field key; writes and deletes are discarded (keys live in `nf_fields`).
  - Full-array reads (`get_post_meta( $id )`) merge NF fields and `_name` rows into core's array, so duplicate/copy tools carry them; writes of those keys back land in NF.
  - Active only in standalone mode (`! defined( 'ACF_VERSION' )`, injectable); with ACF active the Bridge mirrors instead.
  - Schema behind (`Upgrader::isCurrent()` false): the interceptor does nothing (reads and writes fall through to core), because `nf_fields` may not exist yet (CI bootstrap failed on this). Managed-key writes made while behind therefore land in `wp_postmeta`; follow-up: an existence-checked refusal + NEEDS_RESYNC.
- `src/Plugin.php`: registers the interceptor as a service (A1 path, one line).
- `tests/unit/Acf/MetaInterceptorTest.php`: scalar round trip (nothing in `wp_postmeta`), multi + add-unique, reference rows, delete, full-array read + copy to a new post, pass-through of unmanaged keys.

## Deviations / notes
- Series and Group raw meta (`series_0_sub`, group sub-keys) is NOT intercepted yet and falls through to `wp_postmeta`. Needs row/sub-field name resolution; follow-up (WP 2.4b).
- Full-array read costs one `Store::get` per top-level field (no bulk read yet); follow-up: bulk per-table select.
- `delete_all` deletes (all objects) are not intercepted.
- `meta_query` / `orderby meta_value` translation belongs to the query compiler (Phase 3).
- The shim (WP 3.6) must make the default "active" check true when it defines `ACF_VERSION`.
- No `$wpdb` use for NF tables; field lookup goes through `nf_db()`.

## Contract change requested
None.

## Verification
- `php -l` clean. phpunit/phpstan not run locally (no vendor; dist downloads blocked); phpcs phar downloaded but WPCS ruleset unavailable. CI is authoritative.

## Review follow-ups (reviewer, non-blocking, not done)
- NEEDS_RESYNC while the schema is behind: **ruled by the owner 2026-11-13 (option 2)**. Writes and deletes of NF-managed names (and their `_name` rows) are refused (`false`) until the upgrade runs, so nothing lands in `wp_postmeta`; no resync flag. Reads still fall through to core while behind. Branch `fix/meta-block-while-behind`.
- Series `_name` reference rows fall through to core (fixed a fatal in `Store::get`).
- `Store::set` failures are caught and reported through `nf/meta/error`.
- Not done: bulk full-array read, duplicate field names across collections, `add_post_meta` appending to multi-valued lists, value-specific delete, user fields on multisite (network field map), tests for terms/users/comments, WP 3.6 dependency for the shim's `ACF_VERSION`.

- Plugin registers the interceptor only when `nf/meta/intercept` returns true (default false until standalone mode, WP 3.6, flips it): enabling it by default broke SpikeQueryTest, which writes ACF-style postmeta under names that other tests registered in `nf_fields`.
