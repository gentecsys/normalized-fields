# Decision log

Owned by A0. Newest entries go at the bottom. Each entry gives the date, the decision, the reason, and the alternatives considered. D-xx entries are owner decisions from the build plan's section 5. ADR-xxxx entries are architecture decisions.

## Settled (from the design doc and build plan)

### ADR-0001: Replace ACF outright (2026-10-29)
- **Decision:** NF is a standalone plugin. ACF is only a migration source.
- **Why:** sitting underneath ACF ties NF to ACF internals and caps the speed gain.
- **Alternatives:** a storage layer under ACF, which remains a temporary bridge mode only (WP 1.2).

### ADR-0002: Generic typed store (2026-10-29)
- **Decision:** one table per data type, not per content type, so field changes never need DDL. Pivot, row pivot, dictionary, count and archive tables are derived and can be rebuilt.
- **Alternatives:** a table per collection, rejected because every schema edit would need DDL.

### ADR-0003: Fluent query builder, not an ORM (2026-10-29)
- **Decision:** `NF::query()` returns native `WP_Post` / `WP_Term` / `WP_User`, and `WP_Query` `meta_query` is translated transparently.

### ADR-0004: React admin on core WordPress packages (2026-10-29)
- **Decision:** the admin uses the core `@wordpress/*` packages, kept external. Only `@wordpress/dataviews` is bundled, at a pinned version.

### ADR-0005: Multi-agent build against frozen contracts (2026-11-02)
- **Decision:** specialised Claude Code agents (A0–A13), one WP per branch and PR, a fresh-context reviewer on every PR, and owner approval at each gate.

### ADR-0006: "Collection" replaces "field group" in NF's own vocabulary (2026-11-02, owner)
- **Decision:** a set of fields shown together is a **collection**. This applies to NF's admin UI (Collections list, Collection builder), NF's PHP API (`nf_register_collection()`), the NF JSON top-level key (`"collections"`), docs, plans and code identifiers inside `includes/` (no new `nf_*field_group*` names).
- **Exception:** the ACF compatibility surface keeps ACF's names exactly: `acf_add_local_field_group()`, `acf_get_field_groups()`, `acf_get_field_group()`, `group_`-prefixed keys, the `acf-field-group` post type, ACF JSON files and ACF hooks. Themes and plugins call them, so renaming them would break compatibility. Where both appear, write "collection (ACF: field group)".
- **Why:** owner preference for a clearer, product-neutral term.

### ADR-0007: "Series" is the UI name for the repeater field (2026-11-02, owner)
- **Decision:** the admin UI and user-facing strings call the repeater field **Series**; each entry is a **row**. Code identifiers, storage (`nf_rows`, `nf_row_pivot`) and technical docs keep "repeater".
- **Exception:** ACF compatibility keeps `type: "repeater"` in ACF JSON, plus `have_rows()`, `the_row()` and `get_sub_field()`.
- **Why:** not a copy of ACF's vocabulary.

### ADR-0008: No flexible content field (2026-11-02, owner)
- **Decision:** NF doesn't implement flexible content. The `layout_id` columns are dropped from `nf_rows` and `nf_row_pivot`. There's no layout picker, and NF doesn't provide `get_row_layout()` or `acf_fc_layout`.
- **Why:** flexible content is an old page-building paradigm; blocks cover that use case.
- **Consequences:** the scanner and the port dry-run list ACF flexible content fields. Collections that contain one aren't ported: they stay on ACF in bridge mode or get rebuilt, for example as blocks. Templates that call `get_row_layout()` won't work once ACF is deactivated. This is listed in the design doc's risks.
- **Alternatives:** read-only import of flexible content as JSON. Not chosen for now; it can be revisited if a pilot site needs it.

### ADR-0009: Optional remote PostgreSQL backend for NF tables (2026-11-03, owner)
- **Decision:** NF can keep its tables in a remote PostgreSQL database (Neon, Supabase, RDS/Aurora, Cloud SQL, Azure or self-managed) instead of MySQL, for much larger data. It must return exactly what the MySQL backend returns. WordPress core stays in MySQL. MySQL remains the default; PostgreSQL is opt-in per site.
- **Consequence now (Phase 0 onwards):** every NF table access goes through `nf_db()` and a SQL dialect layer, never `$wpdb`, and no NF query joins core tables. Contracts v0 (WP 0.5) define that seam.
- **Delivery:** Phase 5 (WPs 5.1 to 5.9, gate G5). Design: `docs/design.md`, "Remote PostgreSQL backend".
- **Open:** D-11 to D-14 below.

### ADR-0010: Class-based internals with Composer; procedural public API (2026-11-03, owner)
- **Decision:** replaces the procedural convention in D-01.
  - Internals are class-based wherever possible, under `NF\` in `src/`, autoloaded by Composer PSR-4.
  - Composer handles bootstrapping, dependencies (Strauss-prefixed), release builds and future upgrades.
  - **The user-facing API and the ACF proxy stay procedural:** plain global functions in `src/functions/` that delegate to the classes.
- **Why:** classes make the backend adapter, dialects, field-type adapters and tests easier to structure. Composer gives one autoloader and a clean upgrade path. Procedural public functions keep ACF compatibility and match how WordPress developers call field APIs.
- **Consequence:** WP 0.2b converts the 0.1 code (about 135 lines) before 0.3 starts.
- **Open:** D-15, the query builder's entry point.

### ADR-0011: Multisite storage (2026-11-03, owner)
- **Decision:**
  - **MySQL:** follow ACF and WordPress, with per-site NF tables through `$wpdb->prefix` and no stored `site_id`. Site deletion, export and clone tools keep working.
  - **User values are network-wide,** because ACF stores user fields in the shared `wp_usermeta`. NF keeps them in network-level tables on the base prefix, with a network field map keyed by ACF field key.
  - **PostgreSQL:** one shared set of tables, with `site_id` as the first column of every key (user values use `site_id = 0`), partitioned by site. Deleting a site drops its partitions.
  - **Caches:** per-site cache groups, except user groups, which are global.
- **Why:** ACF has no site ID column; separation comes from per-site tables. A per-site table set in one remote PostgreSQL database doesn't scale to large networks.
- **D-16 decided (2026-11-03):** shared tables, not a schema per site.

### ADR-0012: Public REST API and block integration (2026-11-03, owner)
- **Decision:** NF exposes its fields to blocks in three ways, all on top of the `nf_query()` compiler, the loader and the pagination parity rules:
  - **Block Bindings:** the sources `nf/field` and ACF's `acf/field`, on the server and in the editor. `setValues` editing is in v1; options are read-only.
  - **Query Loop:** `core/query` variations with an `nf` attribute, translated on the front end and in the editor preview.
  - **Public `nf/v1` routes:** collections, fields GET/PATCH, query and facets. Core `/wp/v2/*` endpoints also gain an `nf` field and `nf` query parameters.
- **Exposure:** opt-in per field. `show_in_rest` controls read and bind; `rest_filterable` controls filter, sort and facet. Both are off by default.
- **Anonymous access:** anonymous `/nf/v1/query` and `/facets` are allowed for public post types and `show_in_rest` fields, within these limits: at most 100 per page, at most 8 conditions, one level of depth, capped counts by default, and cursors for deep pages.
- **Caching:** public responses are edge-cacheable, with `Surrogate-Key` tags from the pivot write version. Authenticated responses send `private, no-store`.
- **Contract:** the public part of `contracts/rest.md` freezes in contracts v0 (WP 0.5) and is versioned (`nf/v1`). Admin-private routes freeze before Phase 4.
- **D-17 decided (2026-11-03):** anonymous query allowed; `setValues` in v1; register `acf/field`.
- **Why:** blocks are how sites are built now, and Block Bindings let core blocks show NF fields with no custom code. Registering `acf/field` keeps sites already bound to ACF working.

## Owner decisions

| # | Decision | Needed by | Status | Value |
| --- | --- | --- | --- | --- |
| D-01 | Coding conventions | G0 | **Decided by owner 2026-11-03** | Class-based internals with Composer; procedural public API and ACF proxy (ADR-0010) |
| D-02 | Plugin name | G0 | **Decided by owner 2026-11-03** | **Normalized Fields**; slug and text domain `normalized-fields`; `NF` stays the short name in code |
| D-03 | Minimum WordPress | G0 | **Decided by owner 2026-11-02** | 7.0 (test 7.0, 7.1, nightly) |
| D-04 | Minimum PHP | G0 | **Decided by owner 2026-11-03** | 8.1 |
| D-05 | ACF PRO + SCF support | G0 | **Confirmed by owner 2026-11-06** | Both from day one (same public API) |
| D-06 | Pilot site | G1 | Open | Default: one large client site copy |
| D-07 | ACF shim default | G3 | Open | Default: off, enabled by the scanner's suggestion |
| D-08 | Business model | 4.10 | Open | Undecided; doesn't block the build |
| D-09 | Third-party adapters in 4.8 | Start of Phase 4 | Open | Default: the scanner's top hits on client sites |
| D-10 | PostgreSQL backend scope | G0 (shapes contracts v0) | **Confirmed by owner 2026-11-06** | NF tables only; WordPress core stays in MySQL |
| D-11 | When Phase 5 runs | G3 | Open | After G3, in parallel with Phase 4 |
| D-12 | Uncached reads while PostgreSQL is unreachable | Start of Phase 5 | Open | Return empty and log, with an admin notice |
| D-13 | Providers tested in CI | Start of Phase 5 | Open | Neon and AWS RDS; Supabase, Cloud SQL and Azure documented |
| D-14 | Minimum PostgreSQL version | Start of Phase 5 | Open | 15 |
| D-15 | Query builder entry point | G0 (contracts v0) | **Decided by owner 2026-11-03** | `nf_query()` and `nf_options()`; `NF::query()` and `NF::options()` as aliases |
| D-16 | PostgreSQL multisite layout | Start of Phase 5 | **Decided by owner 2026-11-03** | Shared tables with `site_id` leading every key, partitioned by site |
| D-17 | Public REST API for blocks | G0 (contracts v0) | **Decided by owner 2026-11-03** | Anonymous `/nf/v1/query` within limits; Block Bindings `setValues` in v1 (options read-only); register `acf/field` too (ADR-0012) |
| D-18 | Anti-bot plan for anonymous REST (with and without a WAF) | Before WP 3.9 | **Decided by owner 2026-11-06** | Bound the work, don't identify bots: profiles, early guard, cold slots, degradation |
| D-19 | Licence | Release | **Decided by owner 2026-11-09** | GPL v3 or later (`GPL-3.0-or-later`); `LICENSE` holds the GPL v3 text. Every bundled dependency must be GPL v3 compatible |

### Decimal precision: DECIMAL(24,10) (2026-11-12, owner)
Owner agreed to more decimal places after Google Map `lat`/`lng` read back rounded (`42.6977082` → `42.697708`, about 10 cm). Every decimal column (`nf_dec`, `nf_user_dec`, pivot `n` slots, row pivot `n` slots) and the MySQL `dec` cast move from `DECIMAL(20,6)` to `DECIMAL(24,10)`: 10 decimal places as agreed, and the same 14 integer digits as before (`DECIMAL(20,10)` would have cut them to 10). PostgreSQL uses `numeric(24,10)`. Schema version 5; `dbDelta` alters the existing columns when the owner runs the upgrade.

### Meta API writes while the schema is behind (2026-11-13, owner)
- **Decision:** while `nf_db_version` is behind, `MetaInterceptor` refuses (`false`) add, update and delete of NF-managed top-level names and their `_name` reference rows. Nothing is written to NF or to `wp_postmeta`, so no resync is needed. This matches the bridge, which blocks NF writes until the manual upgrade.
- **Limits:** the lookup uses only `nf_fields` columns present since schema 1. With no schema installed, NF manages nothing and writes pass through. If the lookup fails, the write passes through and the error is reported on `nf/meta/error`, so a broken table does not block all meta writes. Reads fall through to core while behind. Series and Group raw meta stays with WP 2.4b.

### No Action Scheduler (2026-11-13, owner)
- **Decision:** NF does not use Action Scheduler, neither bundled nor another plugin's copy. Background jobs (WP 2.5) run on WP-Cron (`nf_run_job`) only; the admin progress screen (WP 4.x) drives batches over REST.
- **Consequences:** no `woocommerce/action-scheduler` dependency and no exception to the Strauss rule. Sites with `DISABLE_WP_CRON` need a system cron that requests `wp-cron.php`. Job runs are only ever queued by admin, WP-CLI or a running job; an anonymous `wp-cron.php` hit only runs what those queued.

## Gate approvals

Only the owner edits this section. To approve a gate, add a line in the form `- G0: approved YYYY-MM-DD (notes)`. The daily build agent won't start the next phase until the matching line exists. To ask for changes instead, add a line in the form `- G0: changes requested YYYY-MM-DD: <what>`.

- G0: approved 2026-11-06 (owner, after the contract changes below)
- G1: approved 2026-11-10 (owner: go; `bench-spike` CI job confirmed; local nf-dev benchmark at 10k/100k follows in `reports/gate-G1.md`)

### Contracts v0 drafted (2026-11-06, WP 0.5, made by daily agent, owner to review at G0)
- **Decision:** `contracts/schema.sql` (all NF tables, MySQL dialect, `{p}` prefix, no `site_id`), `contracts/backend.md` (new: `BackendInterface` / `DialectInterface` seam), `contracts/rest.md` (public `nf/v1` part) and `contracts/nf-json.schema.json` are drafted; `contracts/api.md` came from WP 0.4.
- **Choices beyond the design doc:** `nf_pivot` gains `parent_id`, `modified` and `by_modified`/`by_author` keys (needed to avoid joins to `wp_posts`, ADR-0009); `nf_json.value` is `LONGTEXT` on MySQL (MariaDB 10.4 compatible) and `jsonb` on PostgreSQL; added `nf_meta` and `nf_count_cache`; `nf_terms`/`nf_outbox` are PostgreSQL-only and listed as comments; user tables are `nf_user_*` on the base prefix.
- **REST:** anonymous limits, error codes, cursor format and filter names are specified in `contracts/rest.md`; `facets` requires `rest_filterable` plus a dictionary or bitmask slot.

### Contract changes at G0 (2026-11-06, owner approved)
- **`nf_pivot_sets.write_version`:** the design's pivot write version had no storage. It is now a column, bumped in the same transaction as the pivot write. The count cache, pagination and `Surrogate-Key` tags all read it.
- **`nf_count_cache` cleanup:** added `KEY by_expires (expires)`, a one-day TTL (`nf/count_cache/ttl`) and a daily job that deletes expired and old-version rows.
- **`nf_count_cache` writes:** anonymous requests read the table but never write to it, in REST and on the front end. On a site with no object cache, an anonymous cache miss computes the count each time.
- **Correction:** `nf_meta` and `nf_count_cache` are not additions; the design doc names both.

### Anonymous reads never write (2026-11-06, owner)
- **Rule:** a GET or HEAD from a logged-out visitor never writes to any NF table, directly or by queuing a job.
- **Exceptions:** guest `acf_form()` submissions and comment fields, which pass NF's own validation and nonce checks, with or without ACF active.
- **Count cache:** filled only by logged-in requests and a background job for collection-declared filter combinations (WP 3.4). Other anonymous combinations pay the live count on sites with no object cache.
- **Tightened (owner, same day):** anonymous writes follow WordPress's own settings. No REST route saves field values anonymously. Comment fields save only after WordPress accepts the comment. WordPress has no guest-form setting, so NF adds `nf_allow_guest_forms`, off by default, with a signed field list and a per-IP limit. This changes ACF behaviour for themes with guest forms, and the import wizard reports them.
- **REST overload (owner, same day):** local calls (PHP, and browser calls from the same host, by `Sec-Fetch-Site` or `Referer`) are open. Outside anonymous `/query` and `/facets` are refused by default (`nf_rest_public_query`), and when enabled are limited per IP with an object-cache counter. No object cache means outside calls stay refused. A script can set those headers itself, so edge rate limiting stays the recommended outer layer.

### D-18 anti-bot plan (2026-11-06, owner, after an independent review)
- **Replaces** the local and outside calls rule, including the same-host check, `nf_rest_public_query` and `nf_rest_outside_disabled`.
- **Design:** query profiles (default on), early guard, `GET_LOCK` cold slots with statement timeouts, degradation, then per-prefix limits where an object cache or APCu exists. See `contracts/rest.md`.
- **Owner choices:** profiles default on, changing D-17's open anonymous filtering. Anonymous listings may be 60-300 s stale. No filesystem counters. Anonymous front-end `nf_query()` uses count slots and the timeout, but lists never get a 503.
- **Caching:** post-type tags lose the write version; saves purge object keys only.
- **Contract change:** `backend.md` gains `tryLock()`, `unlock()` and `statementTimeout()`. PostgreSQL pooled endpoints may break session locks; check in Phase 5.
- **Unverified:** all latency and capacity figures are estimates, `GET_LOCK` and timeout support needs a version check across the matrix, and Pantheon behaviour needs a multidev test. WP 3.9 measures these.
