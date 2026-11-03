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
- **Open:** D-10 to D-14 below.

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
- **Open:** D-16, shared tables or one schema per site on PostgreSQL.

## Owner decisions

| # | Decision | Needed by | Status | Value |
| --- | --- | --- | --- | --- |
| D-01 | Coding conventions | G0 | **Decided by owner 2026-11-03** | Class-based internals with Composer; procedural public API and ACF proxy (ADR-0010) |
| D-02 | Plugin name | G0 | **Default, pending owner confirmation** | Keep "NF" internally until 4.10 |
| D-03 | Minimum WordPress | G0 | **Decided by owner 2026-11-02** | 7.0 (test 7.0, 7.1, nightly) |
| D-04 | Minimum PHP | G0 | **Default, pending owner confirmation** | 8.1 |
| D-05 | ACF PRO + SCF support | G0 | **Default, pending owner confirmation** | Both from day one (same public API) |
| D-06 | Pilot site | G1 | Open | Default: one large client site copy |
| D-07 | ACF shim default | G3 | Open | Default: off, enabled by the scanner's suggestion |
| D-08 | Business model | 4.10 | Open | Undecided; doesn't block the build |
| D-09 | Third-party adapters in 4.8 | Start of Phase 4 | Open | Default: the scanner's top hits on client sites |
| D-10 | PostgreSQL backend scope | G0 (shapes contracts v0) | **Default, pending owner confirmation** | NF tables only; WordPress core stays in MySQL |
| D-11 | When Phase 5 runs | G3 | Open | After G3, in parallel with Phase 4 |
| D-12 | Uncached reads while PostgreSQL is unreachable | Start of Phase 5 | Open | Return empty and log, with an admin notice |
| D-13 | Providers tested in CI | Start of Phase 5 | Open | Neon and AWS RDS; Supabase, Cloud SQL and Azure documented |
| D-14 | Minimum PostgreSQL version | Start of Phase 5 | Open | 15 |
| D-15 | Query builder entry point | G0 (contracts v0) | **Decided by owner 2026-11-03** | `nf_query()` and `nf_options()`; `NF::query()` and `NF::options()` as aliases |
| D-16 | PostgreSQL multisite layout | Start of Phase 5 | Open | Shared tables with `site_id` leading every key, partitioned by site |

## Gate approvals

Only the owner edits this section. To approve a gate, add a line in the form `- G0: approved YYYY-MM-DD (notes)`. The daily build agent won't start the next phase until the matching line exists. To ask for changes instead, add a line in the form `- G0: changes requested YYYY-MM-DD: <what>`.

_No gates approved yet._
