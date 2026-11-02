# NF build: status board

Owned by A0 (orchestrator). Each WP has a state: `todo` · `ready` · `in-progress` · `in-review` · `merged` · `blocked`. Sizes are relative (S/M/L/XL). Full WP definitions are in the kb build plan, section 3.

Current phase: **Phase 0: Foundations**
Next gate: **G0**: approve contracts v0 and settle D-01 to D-05.

## Phase 0: Foundations

| WP | Title | Agent | Size | Depends on | State | Branch | Done when |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 0.1 | Repo and plugin skeleton | A1 | S | — | ready | | Activates cleanly on WP 7.0 and 7.1 |
| 0.2 | Dev environment and CI | A1 | M | 0.1 | todo | | Empty test suite green on the whole matrix |
| 0.3 | Dataset generator | A12 | S | 0.1 | todo | | Same post IDs and values in ACF and NF stores (10k, 100k) |
| 0.4 | ACF API inventory | A5 | M | — | ready | | `contracts/api.md` lists the full surface, ranked by usage |
| 0.5 | Contracts v0 | A0 (+A2, A4, A5, A9) | M | 0.4 | todo | | Reviewed by A13; no open questions |
| 0.6 | Differential test harness | A11 | L | 0.2, 0.3 | todo | | Zero diffs on an ACF-vs-ACF baseline |

Lanes: A1 runs 0.1 then 0.2, A12 runs 0.3 once 0.1 lands, and A5 runs 0.4 in parallel. After those, A0 runs 0.5 and A11 runs 0.6.

**Gate G0 [owner]:** `reports/gate-G0.md`.

## Phase 1: Spike (go / no-go)

| WP | Title | Agent | Size | Depends on | State | Branch | Done when |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1.1 | Store: scalar, repeater, relationship | A2 | M | G0 | todo | | Write/read/update/delete round-trip tests |
| 1.2 | Bridge mode | A5 | M | 1.1 | todo | | ACF admin saves land in NF tables |
| 1.3 | Minimal pivot + two translated queries | A3 + A4 | M | 1.1 | todo | | Same IDs as `WP_Query` on `wp_postmeta` |
| 1.4 | Differential check | A11 | S | 1.2 | todo | | Zero diffs |
| 1.5 | Spike benchmark | A12 | S | 1.3 | todo | | Report in `reports/` |

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
| 3.3 | `WP_Query` integration | A4 | L | 3.1 | todo | | Same IDs and order on `WP_Query` args |
| 3.4 | Counting tier | A3 | M | 3.1 | todo | | Rollup equals live `COUNT(*)` in fuzz tests |
| 3.5 | Loading | A6 | L | 3.2 | todo | | 20-card listing in ≤ 15 queries |
| 3.6 | Standalone ACF API | A5 | XL | G2 | todo | | Differential suite passes with ACF deactivated |
| 3.7 | Timber integration | A7 | M | 3.6 | todo | | Starter + two real Timber themes render identically |
| 3.8 | Benchmark gates | A12 | M | 3.3, 3.4, 3.5 | todo | | CI fails on regression > 20% |

XL WPs (3.1, 3.6) are split into sub-WPs of size L or smaller when they start.

**Gate G3 [owner]:** first production site in read-NF / write-both mode, a soak period with no regressions, and a rehearsed rollback.

## Phase 4: Admin, migration, compatibility, release

| WP | Title | Agent | Size | Depends on | State | Branch | Done when |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 4.1 | Admin shell | A8 | M | G3 | todo | | Loads on WP 7.0 / 7.1, no console errors |
| 4.2 | Field group builder | A8 | XL | 4.1 | todo | | Every core type configurable; Playwright tests |
| 4.3 | Edit forms and repeater editor | A8 | XL | 4.1 | todo | | Save/reload identical for every type; no `max_input_vars` limit |
| 4.4 | Tools, import wizard, health screens | A8 + A9 | L | 4.1, 2.6 | todo | | Wizard ports a real site end to end |
| 4.5 | Schema import and export | A9 | L | 3.6 | todo | | Round-trip on corpus of real ACF exports |
| 4.6 | Content migration | A9 | L | 4.5 | todo | | Cross-site ACF → NF-only migration |
| 4.7 | ACF Blocks and front-end forms | A5 + A8 | L | 3.6, 4.3 | todo | | Blocks render identically; forms save to NF |
| 4.8 | Third-party compatibility | A10 | XL | 3.6, 2.4 | todo | | Each listed plugin passes or is documented unsupported |
| 4.9 | Hardening | A11 | L | 4.2–4.8 | todo | | Matrix green; no WCAG 2.1 AA blockers; security issues closed |
| 4.10 | Docs and release | A0 | M | 4.9 | todo | | Plugin Check passes; RC tagged |

**Gate G4 [owner]:** release decision.

## Blockers

_None._
