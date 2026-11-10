# Gate G1: spike go / no-go

Written 2026-11-10 by the daily build agent. The build is paused until the owner approves G1 (go) or requests changes (no-go).

## What was built (Phase 1, all WPs merged)
- 1.1: store for scalar, Series (repeater) and relationship fields; round-trip tests.
- 1.2, 1.2b: bridge mode mirrors ACF saves (including lone sub-field and row-count saves) into NF tables.
- 1.3: minimal pivot (sets, slots, write-through, rebuild) and `SpikeQuery` translating the archive and reverse-relationship `WP_Query` shapes, incl. `paged`, `offset`, `-1`.
- 1.4: differential check, ACF storage reads vs NF table reads on the spike types: zero diffs.
- 1.5: spike benchmark.

## Go criteria: zero diffs and a clear win on the slow queries
- Zero diffs: met. `WP_Query` differential tests (IDs, order, `found_posts`, `max_num_pages`) and the NF-vs-ACF differential check are green in CI (28/28 on the last PR).
- Win (CI, 10k posts, MySQL 8.0, PHP 8.3, WP 7.0, stock ACF meta, shared runner, 5-run median):

| Query | ACF ms | NF ms | Speed-up |
| --- | --- | --- | --- |
| Archive: featured, upcoming, by date, page 1 + total | 278.19 | 3.63 | 76.7x |
| Deep page: featured by date, page 20 + total | 221.02 | 4.15 | 53.3x |
| Reverse relationship: artist 123 | 21.29 | 1.99 | 10.7x |

Recommendation: **go**.

## Open risks
- Not measured: 100k posts, the repeater sub-field query, facets, ACF-tuned indexes (tracked follow-up: a 100k dispatch run).
- Numbers come from one shared-runner run, so they are noisy; the order of magnitude matches the design doc.
- Equal sort values have no tie-break in core; the comparison tolerates a different order among equal keys.
- Bridge known issues: the mirror runs at priority 99, so site filters on `name=`/`key=` handlers can make NF and postmeta differ; group/clone sub-fields register top-level; `text` without `maxlength` goes to `nf_text` (decide permanently).
- Not done yet: backfill of existing postmeta, `acf/pre_load_metadata` read takeover, Series row deletion on `delete_post`.
- ACF PRO parity is only checked on the owner's LocalWP site.

## Decisions the owner must make
- Go / no-go for Phase 2.
- Confirm the `bench-spike` job added to `.github/workflows/ci.yml` by WP 1.5 (A1's file).
- D-05 (ACF PRO + SCF both supported) and D-10 (PostgreSQL scope: NF tables only) are still pending owner confirmation.
- Whether to require the 100k run before approving.

To approve add `- G1: approved YYYY-MM-DD` under "Gate approvals" in `DECISIONS.md`.
