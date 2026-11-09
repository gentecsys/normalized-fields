# NF build: status board

Owned by A0 (orchestrator). Each WP has a state: `todo` · `ready` · `in-progress` · `in-review` · `merged` · `blocked`. Sizes are relative (S/M/L/XL). Full WP definitions are in the kb build plan, section 3.

Current phase: **Phase 0: Foundations**
Next gate: **G0**: approve contracts v0 and settle D-01 to D-05.

## Phase 0: Foundations

| WP | Title | Agent | Size | Depends on | State | Branch | Done when |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 0.1 | Repo and plugin skeleton | A1 | S | — | merged | wp/0.1-skeleton | Activates cleanly on WP 7.0 and 7.1 (activation check deferred to 0.2 CI) |
| 0.2 | Dev environment and CI | A1 | M | 0.1 | merged | wp/0.2-ci-env | Empty test suite green on the whole matrix |
| 0.2b | Class-based structure and Composer autoloading | A1 | S | 0.2 | merged | | 0.1 code converted to `NF\` classes in `src/` (PSR-4), procedural public functions in `src/functions/`; release build zip with `vendor/`; Dependabot; plugin renamed per D-02 (header `Plugin Name: Normalized Fields`, main file `normalized-fields.php`, text domain `normalized-fields` in code, header and PHPCS); activation unchanged on WP 7.0 / 7.1; CI green |
| 0.3a | Dataset generator: model, ACF store, WP-CLI | A12 | S | 0.2b | merged | wp/0.3a-dataset-generator | Deterministic 10k/100k dataset written to `wp_posts`/`wp_postmeta` in ACF format; `wp nf-bench generate/verify/fingerprint`; unit tests |
| 0.3c | Dataset parity with the design benchmark | A12 | S | 0.3a | merged | wp/0.3c-dataset-parity | `bench/bootstrap.php` registers the `nf_bench_event` post type and an ACF field group (keys `field_nfbench_*`, Local JSON in `bench/acf-json/`), so `get_field()` returns formatted values: date, checkbox array, relationship, Series rows; field set matches the design doc benchmark (17 top-level fields incl. 10 text fields, plus `sessions`; 12-value category checkbox, 1–4 artists, 5-row × 3-sub-field `sessions`: 66 `wp_postmeta` rows per post); `verify` batched (no per-post queries); unit tests updated |
| 0.4 | ACF API inventory | A5 | M | — | merged | wp/0.4-acf-api-inventory | `contracts/api.md` lists the full surface, ranked by usage |
| 0.5 | Contracts v0 (incl. public part of `rest.md`, ADR-0012) | A0 (+A2, A4, A5, A8, A9) | M | 0.4 | merged | wp/0.5-contracts-v0 | Reviewed by A13; no open questions |
| 0.6a | Differential harness core (runner, normalizer, comparator, ACF-vs-ACF baseline on doubles) | A11 | S | 0.2, 0.3a, 0.3c | merged | wp/0.6a-diff-harness-core | Pure-PHP harness with unit tests; baseline of two identical targets has zero diffs, a tampered target is detected |
| 0.6b | Differential harness: real ACF on wp-env and CI job | A11 | M | 0.6a | merged | wp/0.6b-diff-real-acf | ACF-vs-ACF baseline against SCF (Secure Custom Fields, free, includes the repeater) installed in wp-env, on the bench dataset (incl. `sessions` Series rows), zero diffs, running in CI. No ACF PRO in CI: there is no licence for it; ACF PRO parity is checked on the owner's LocalWP nf-dev site (`run-checks.sh`, outside the repo) |
| 0.6c | Differential scenarios for the spike types | A11 | M | 0.6b | merged | wp/0.6c-diff-scenarios | Scenarios for scalar, Series and relationship fields; baseline zero diffs |

Lanes: A1 runs 0.1 then 0.2, A12 runs 0.3 once 0.1 lands, and A5 runs 0.4 in parallel. After those, A0 runs 0.5 and A11 runs 0.6.

**Gate G0 [owner]:** `reports/gate-G0.md`.

## Phase 1: Spike (go / no-go)

| WP | Title | Agent | Size | Depends on | State | Branch | Done when |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1.1 | Store: scalar, repeater, relationship | A2 | M | G0 | merged | wp/1.1-store | Write/read/update/delete round-trip tests |
| 1.2 | Bridge mode | A5 | M | 1.1 | merged | wp/1.2-bridge-mode | ACF admin saves land in NF tables |
| 1.2b | Bridge: re-sync a Series after a lone sub-field save | A5 | S | 1.2 | merged | wp/1.2b-series-resync | **Do this before 1.3.** See `reports/WP-1.2b.md`. After `update_sub_field()` or `update_field( 'outer_0_name' )` on real ACF PRO, NF holds the same rows as ACF; tests cover sub-field, row add/remove via `add_row()`/`delete_row()`, and no write when ACF's save fails |
| 1.3 | Minimal pivot + two translated queries | A3 + A4 | M | 1.1 | merged | wp/1.3-pivot-queries | Same IDs, order, `found_posts` and `max_num_pages` as `WP_Query` on `wp_postmeta`, incl. the design doc's pagination cases (`paged`, `offset`, `-1`) |
| 1.4 | Differential check | A11 | S | 1.2, 1.2b | in-review | | Zero diffs |
| 1.5 | Spike benchmark | A12 | S | 1.3 | ready | | Report in `reports/` |
| 0.3b | Dataset generator: NF store writer | A12 | S | 0.3a, 1.1 | merged | wp/0.3b-nf-store-writer | Same post IDs and values in ACF and NF stores (10k, 100k); `nf` store verifies against the same fingerprint |

0.3b sits here because it needs 1.1, which needs G0; it stays numbered 0.3b so existing reports still match.

**Gate G1 [owner]:** go / no-go. Go requires zero diffs and a clear win on the slow queries.

## Phase 2: Storage MVP

| WP | Title | Agent | Size | Depends on | State | Branch | Done when |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 2.1 | All field types | A2 | L | G1 | todo | | Differential suite passes for every type |
| 2.2 | Pivot, row pivot, dictionary, bitmasks, rollups | A3 | L | G1 | todo | | Rebuild equals write-through, byte for byte |
| 2.3 | Archive and revisions | A2 | M | 2.1 | todo | | Preview and restore tests pass |
| 2.4 | Meta API interception | A5 | M | 2.1 | todo | | Duplicate-post and copy tests keep NF fields |
| 2.5 | Background jobs | A1 | M | G1 | todo | | Survives killed requests and PHP timeouts |
| 2.6 | Port pipeline (no UI) | A9 | L | 2.1, 2.5 | todo | | Zero mismatches on a copy of a real site |
| 2.7 | Repeater sorting | A2 | S | 2.1 | todo | | Unit tests for all three modes |

**Gate G2 [owner]:** verify reports zero mismatches on a staging copy of a real site.

## Phase 3: Query layer, loading, ACF API, Timber

| WP | Title | Agent | Size | Depends on | State | Branch | Done when |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 3.1 | Compiler and planner | A4 | XL | G2 | todo | | Compiler corpus green; `explain()` matches |
| 3.2 | `NF::query()` builder | A4 | L | 3.1 | todo | | Every design-doc example runs as a test |
| 3.3 | `WP_Query` integration | A4 | L | 3.1 | todo | | Same IDs, order, `found_posts` and `max_num_pages` on `WP_Query` args; every "Pagination parity" case in `docs/design.md` passes |
| 3.4 | Counting tier | A3 | M | 3.1 | todo | | Rollup equals live `COUNT(*)` in fuzz tests; debounced background job recomputes count-cache entries for the top declared filter combinations, counts up to 60 s stale; a test proves an anonymous GET writes to no NF table |
| 3.5 | Loading | A6 | L | 3.2 | todo | | 20-card listing in ≤ 15 queries |
| 3.6 | Standalone ACF API | A5 | XL | G2 | todo | | Differential suite passes with ACF deactivated |
| 3.7 | Timber integration | A7 | M | 3.6 | todo | | Starter + two real Timber themes render identically |
| 3.8 | Benchmark gates | A12 | M | 3.3, 3.4, 3.5 | todo | | CI fails on regression > 20% |
| 3.9 | Public REST API, split into guard, profiles, cold slots and routes | A8 + A4 | L | 3.2, 3.6 | todo | | Every route in `contracts/rest.md` tested; `/query` matches `nf_query()` IDs and order; anonymous requests never see non-public statuses or fields; profiles are the default; forged cookies and headers change nothing; `_method`, `rest_route=` and `Origin` rotation are refused or normalised; saturated slots degrade as specified; no NF table, transient or option write on anonymous paths; k6 on a 1-CPU, no-cache rig with a measured bootstrap and facet baseline at 100k and 1M posts; Pantheon multidev checks for client IP, 4xx caching and stale directives; no route saves field values for an anonymous request, including `/wp/v2/comments` with `rest_allow_anonymous_comments` on; limit and oracle tests pass |

XL WPs (3.1, 3.6) are split into sub-WPs of size L or smaller when they start.

**Gate G3 [owner]:** first production site in read-NF / write-both mode, a soak period with no regressions, and a rehearsed rollback.

## Phase 4: Admin, migration, compatibility, release

| WP | Title | Agent | Size | Depends on | State | Branch | Done when |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 4.1 | Admin shell | A8 | M | G3 | todo | | Loads on WP 7.0 / 7.1, no console errors |
| 4.2 | Collection builder | A8 | XL | 4.1 | todo | | Every core type configurable; Playwright tests |
| 4.3 | Edit forms and Series editor | A8 | XL | 4.1 | todo | | Save/reload identical for every type; no `max_input_vars` limit |
| 4.4 | Tools, import wizard, health screens | A8 + A9 | L | 4.1, 2.6 | todo | | Wizard ports a real site end to end |
| 4.5 | Schema import and export | A9 | L | 3.6 | todo | | Round-trip on corpus of real ACF exports |
| 4.6 | Content migration | A9 | L | 4.5 | todo | | Cross-site ACF → NF-only migration |
| 4.7 | ACF Blocks and front-end forms | A5 + A8 | L | 3.6, 4.3 | todo | | Blocks render identically; forms save to NF; with ACF deactivated, guest submissions are refused unless `nf_allow_guest_forms` is on, and otherwise pass the signed field list, NF's own validation and nonce check, and the per-IP limit; comment fields save only for comments WordPress accepted |
| 4.8 | Third-party compatibility | A10 | XL | 3.6, 2.4 | todo | | Each listed plugin passes or is documented unsupported |
| 4.9 | Hardening | A11 | L | 4.2–4.8, 4.11 | todo | | Matrix green; no WCAG 2.1 AA blockers; security issues closed |
| 4.11 | Block Bindings and Query Loop | A8 + A5 | L | 3.9, 4.1 | todo | | `nf_register_bindable_attributes()` and block.json `nf.bindable` work for custom blocks; `nf/field` + `acf/field` bound core blocks render the same values as `get_field()`; editor edits save with the post; Query Loop front end and preview return the same IDs; build and ADA audit pass; blocks fall back to full navigation on 503 |
| 4.10 | Docs and release | A0 | M | 4.9 | todo | | Plugin Check passes; RC tagged |

**Gate G4 [owner]:** release decision.

## Phase 5: Remote PostgreSQL backend (ADR-0009)

Runs after G3, in parallel with Phase 4 (D-11). Design: `docs/design.md`, section "Remote PostgreSQL backend".

| WP | Title | Agent | Size | Depends on | State | Branch | Done when |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 5.1 | Backend adapter and dialect seam | A2 + A4 | L | G3 | todo | | MySQL suite and benchmarks unchanged; no `$wpdb` on NF tables |
| 5.2 | PostgreSQL schema and migrations | A1 | M | 5.1 | todo | | Fresh install and every upgrade pass on PostgreSQL 15–18 |
| 5.3 | Connection layer | A1 | M | 5.1 | todo | | Neon pooled endpoint, PgBouncer, RDS Proxy; outage and timeout drills pass |
| 5.4 | PostgreSQL store | A2 | L | 5.2, 5.3 | todo | | Store round-trips byte-identical on both backends |
| 5.5 | PostgreSQL dialect for the compiler | A4 | XL | 5.4 | todo | | Three-way differential (ACF, NF-MySQL, NF-PostgreSQL): zero diffs |
| 5.6 | Cross-database writes | A2 + A1 | L | 5.4 | todo | | No lost write in outage tests; reconcile reports zero drift |
| 5.7 | Move data between backends | A9 | L | 5.5, 5.6 | todo | | Real site copy moves both ways, zero mismatches |
| 5.8 | Scale and provider tests | A11 + A12 | L | 5.5 | todo | | 10M-object benchmarks on Neon and RDS; no regression over 20% |
| 5.9 | Backend settings and health screen | A8 | M | 5.3 | todo | | Playwright tests; build and ADA audit pass |

**Gate G5 [owner]:** three-way suite at zero diffs, 10M-object benchmarks on Neon and RDS, outage and environment-stamp drills passed, and one pilot site on PostgreSQL in shadow mode.

## Blockers

_None._
