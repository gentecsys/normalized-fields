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

- **Terminology (ADR-0006):** in NF's own UI, API, JSON, docs and identifiers, a set of fields is a **collection**, never a "field group" (for example `nf_register_collection()` and `"collections"`). The ACF compatibility layer keeps ACF's names exactly (`acf_add_local_field_group()`, `acf_get_field_groups()`, `group_` keys, the `acf-field-group` post type, ACF JSON).
- **Series (ADR-0007):** the repeater field is called **Series** in the UI and in user-facing strings. Each entry is a **row**. Code, storage and docs keep "repeater" as the technical name, and ACF compatibility keeps `type: "repeater"`, `have_rows()`, `the_row()` and `get_sub_field()`.
- **No flexible content (ADR-0008):** don't build a flexible content field, a layout picker, `layout_id` columns or `get_row_layout()`. The importer reports ACF flexible content fields and skips their collections.

- PHP is procedural: plain functions with an `nf_` prefix, hooked through `add_action` / `add_filter`. No OOP wrappers around WordPress APIs.
- Classes are allowed only for:
  - the fluent query builder `NF::query()`;
  - Timber's `IntegrationInterface`;
  - the minimal `ACF` shim class;
  - the field-type adapter contract for add-ons.
- PHP follows the WordPress Coding Standards: tabs, PHPCS with `WordPress`, and PHPStan at level 6.
- Sanitize every input (`sanitize_text_field`, `absint`, …) and escape every output (`esc_html`, `esc_attr`, `wp_kses_post`). All SQL is parameterised (`$wpdb->prepare()` for core tables, bound parameters in `nf_db()` for NF tables). Table and column names come only from the contract, never from input.
- **Backend seam (ADR-0009):** NF tables are read and written only through the backend adapter `nf_db()`, never through `$wpdb`. `$wpdb` is only for WordPress core tables. SQL for NF tables is built by the dialect layer (MySQL now, PostgreSQL in Phase 5). Don't rely on MySQL-only behaviour without writing it explicitly: no implicit casts, always `NULLS FIRST`/`NULLS LAST` when sorting nullable columns, no `FORCE INDEX` outside the MySQL dialect, and no joins between NF tables and core tables (a query resolves IDs on the NF side first).
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
nf.php                    # plugin bootstrap (A1)
includes/
  core/  install/         # A1: bootstrap, install/upgrade, dbDelta, jobs
  storage/                # A2: field map, typed tables, rows, archive, sorting
  pivot/                  # A3: pivots, row pivot, dictionary, bitmasks, rollups, counts
  query/                  # A4: NF::query(), compiler, planner, WP_Query integration
  loading/                # A6: result-set registry, batching, primers, with()
  acf/                    # A5: ACF API, registration, Local JSON, hooks, shim, bridge, meta interception, blocks
  timber/                 # A7: Timber integration
  rest/                   # A8: nf/v1 routes
  migration/  schema-io/  # A9: scan/port/verify/undo, NF JSON & ACF JSON, WXR
  compat/                 # A10: third-party adapters, postmeta mirror, scanner
admin/src/                # A8: React admin
lib/                      # A1: bundled Action Scheduler
bench/                    # A12: dataset generator, benchmark suite
tests/
  unit/<area>/            # owned by the area's agent
  differential/  e2e/  corpus/   # A11
contracts/                # A0 (frozen per phase)
docs/  reports/
PLAN.md  DECISIONS.md
```
