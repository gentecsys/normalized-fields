# NF (Normalized Fields): project conventions

NF is a standalone WordPress plugin that replaces ACF. It stores field data in fixed typed, pivot and lookup tables instead of `wp_postmeta`. It keeps ACF's PHP API, hooks and JSON format, so themes, including Timber themes, keep working with ACF deactivated. ACF is used only as a migration source.

Source of truth:
- Design: `docs/design.md`, a copy of the kb note `projects/nf-normalized-fields/nf-design-doc.md`. Cite its sections by name.
- Build plan: the kb note `projects/nf-normalized-fields/nf-multi-agent-build-plan.md`. Its work packages and status live in `PLAN.md`.

## Start of every session

Read these in order, then stick to your work package (WP):
1. This file.
2. `PLAN.md`: your WP, what it depends on, and its acceptance criteria.
3. The `contracts/` files your WP touches.
4. The `docs/design.md` sections your WP lists.
5. `reports/WP-*.md` for the WPs yours depends on.

At the end of every session, write or update `reports/WP-<id>.md`. Don't rely on memory of earlier sessions; the repo holds all state.

## Operating rules

- **Contracts before code.** `contracts/` is frozen for each phase. Only the orchestrator (A0) changes it, and every change is logged in `DECISIONS.md`. If you need a change, stop and write it in your report under **Contract change requested**.
- **One WP = one branch = one PR.** Name the branch `wp/<id>-<slug>`, e.g. `wp/0.1-skeleton`. Work in your own git worktree.
- **Ownership.** Edit only the paths your agent file (`.claude/agents/`) allows. If something outside them needs changing, file it in your report.
- **No self-grading.** Every PR gets a fresh-context review by A13, plus CI, the differential suite and the benchmark gates.
- **Gates.** After each phase, A0 writes `reports/gate-Gn.md` and stops until the owner approves.

## Coding conventions (D-01)

- **Name (D-02):** the plugin is **Normalized Fields**. The slug and text domain are `normalized-fields`; every translatable string uses `'normalized-fields'`. `NF` is only the short name in code: the `NF\` namespace, the `nf_` prefix and `nf/` hooks.
- **Terminology (ADR-0006):** in NF's own UI, API, JSON, docs and identifiers, a set of fields is a **collection**, never a "field group" (for example `nf_register_collection()` and `"collections"`). The ACF compatibility layer keeps ACF's names exactly (`acf_add_local_field_group()`, `acf_get_field_groups()`, `group_` keys, the `acf-field-group` post type, ACF JSON).
- **Series (ADR-0007):** the repeater field is called **Series** in the UI and in user-facing strings. Each entry is a **row**. Code, storage and docs keep "repeater" as the technical name, and ACF compatibility keeps `type: "repeater"`, `have_rows()`, `the_row()` and `get_sub_field()`.
- **No flexible content (ADR-0008):** don't build a flexible content field, a layout picker, `layout_id` columns or `get_row_layout()`. The importer reports ACF flexible content fields and skips their collections.

- **Classes and Composer (D-01, ADR-0010):**
  - The internals are class-based wherever possible: namespace `NF\` in `src/`, autoloaded by Composer PSR-4 (`"NF\\": "src/"`). Every file declares `strict_types=1` and holds one class, interface, enum or trait, named after the file.
  - The main plugin file (`normalized-fields.php`) only boots: header, constants, requirement check, `vendor/autoload.php`, then `NF\Plugin::boot()`. A missing autoloader shows an admin notice, never a fatal error.
  - `NF\Plugin` wires the services. Each service adds its hooks in a `register()` method. Constructors take dependencies and have no side effects.
  - Use PHP 8.1: typed and `readonly` properties, enums for closed sets (object types, data types, storage modes), and interfaces for every contract (store, backend adapter, dialect, compiler, loader, field-type adapters).
- **The user-facing API stays procedural:**
  - Everything a theme or plugin developer calls is a plain global function: the ACF proxy (`get_field()`, `have_rows()`, `the_row()`, `get_sub_field()`, `update_field()`, `acf_add_local_field_group()`, `acf_get_field_groups()` and the rest, each guarded by `function_exists()`) and NF's own public functions (`nf_register_collection()`, `nf_register_row_sorter()`, `nf_db()` and the others in `contracts/api.md`).
  - They live in `src/functions/<area>.php`, load through Composer's `files` autoload, and are thin wrappers that delegate to the classes. Don't put logic in them.
  - The only public classes are the ones a dependency forces: the `ACF` shim class (themes check `class_exists( 'ACF' )`) and the Timber `IntegrationInterface` implementation. The query builder's entry points are `nf_query()` and `nf_options()` (D-15); `NF::query()` and `NF::options()` are only aliases.
- **Composer for the whole project:**
  - Never commit `vendor/`. Release builds run `composer install --no-dev --optimize-autoloader --classmap-authoritative` and ship `vendor/` in the zip.
  - Runtime dependencies are prefixed with Strauss into `NF\Vendor\`. Action Scheduler (`woocommerce/action-scheduler`) is the exception: it comes through Composer but loads through its own version registry.
  - Schema upgrades are versioned migration classes in `NF\Install\Migrations\`, run on version change for both backends.
  - Hook names and option keys keep the `nf/` and `nf_` prefixes.
- PHP follows the WordPress Coding Standards (tabs, PHPCS `WordPress`), except that file names follow PSR-4 in `src/`. PHPStan runs at level 6.
- Sanitize every input (`sanitize_text_field`, `absint`, …) and escape every output (`esc_html`, `esc_attr`, `wp_kses_post`). All SQL is parameterised (`$wpdb->prepare()` for core tables, bound parameters in `nf_db()` for NF tables). Table and column names come only from the contract, never from input.
- **Backend seam (ADR-0009):** NF tables are read and written only through the backend adapter `nf_db()`, never through `$wpdb`. `$wpdb` is only for WordPress core tables. SQL for NF tables is built by the dialect layer (MySQL now, PostgreSQL in Phase 5). Don't rely on MySQL-only behaviour without writing it explicitly: no implicit casts, always `NULLS FIRST`/`NULLS LAST` when sorting nullable columns, no `FORCE INDEX` outside the MySQL dialect, and no joins between NF tables and core tables (a query resolves IDs on the NF side first).
- **Multisite (ADR-0011):** never add a stored `site_id` to MySQL tables. Per-site NF tables come from `$wpdb->prefix` through `nf_db()`, which re-resolves the prefix on `switch_blog`. User values are network-wide, as in ACF: they go to network-level tables on the base prefix, with a network field map keyed by ACF field key. On PostgreSQL, `site_id` is the first column of every key (user values use `site_id = 0`). NF cache groups are per site, except user groups, which are global groups.
- Use `wp_cache_get` / `wp_cache_set` for caching.
- Admin JavaScript is ES modules and functional React components, built with `@wordpress/scripts`. Core `@wordpress/*` packages stay external; only `@wordpress/dataviews` is bundled, at a pinned version. Use `@wordpress/data` for state and `@wordpress/api-fetch` for REST. No jQuery.
- Lint JavaScript with ESLint and Prettier (the `@wordpress/scripts` configs).
- Minimum WordPress 7.0 (D-03) and PHP 8.1 (D-04). Test against WordPress 7.0, 7.1 and nightly, PHP 8.1–8.4, MySQL 5.7 / 8.0 and MariaDB 10.4 / 10.11.

## Definition of done (every PR)

- The WP's acceptance criteria in `PLAN.md` are met.
- CI is green on the full matrix.
- Differential suite: zero diffs between ACF and NF for any ACF-visible behaviour you touched.
- Benchmarks: no regression over 20% on the 100k dataset, and query-count assertions pass.
- A13 reports no blocking issues.
- Any admin UI change passes `npm run build` with no errors, then the `wp-ada-audit` skill (WCAG 2.1 AA). All 🔴 and 🟡 findings are fixed.
- Contracts are updated if behaviour or signatures changed, with a `DECISIONS.md` entry.
- `reports/WP-<id>.md` is written.

## Repository layout

Directories are created by the WP that first needs them.

```
normalized-fields.php     # main plugin file, boot only (A1; renamed from nf.php in WP 0.2b)
composer.json             # A1: PSR-4 "NF\\" => src/, files autoload for src/functions/, Strauss
src/
  Plugin.php              # A1: service wiring
  Core/  Install/         # A1: requirements, activation, migrations, jobs
  Backend/                # A2: nf_db() adapter (MySQL; PostgreSQL in Phase 5)
  Storage/                # A2: field map, typed tables, rows, archive, sorting
  Pivot/                  # A3: pivots, row pivot, dictionary, bitmasks, rollups, counts
  Query/                  # A4: nf_query() builder, compiler, SQL dialects, planner, WP_Query integration
  Loading/                # A6: result-set registry, batching, primers, with()
  Acf/                    # A5: registration, Local JSON, hooks, shim, bridge, meta interception, blocks
  Timber/                 # A7: Timber integration
  Rest/                   # A8: nf/v1 routes
  Migration/  SchemaIO/   # A9: scan/port/verify/undo, NF JSON and ACF JSON, WXR
  Compat/                 # A10: third-party adapters, postmeta mirror, scanner
  functions/              # procedural public API: acf.php (A5), nf.php and others, owned by the area's agent
admin/src/                # A8: React admin
bench/                    # A12: dataset generator, benchmark suite
tests/
  unit/<Area>/            # owned by the area's agent
  differential/  e2e/  corpus/   # A11
contracts/                # A0 (frozen per phase)
docs/  reports/
PLAN.md  DECISIONS.md
```
