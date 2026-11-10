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

## Owner decision and local benchmark (2026-11-10)

**G1 approved 2026-11-10 (go).** The `bench-spike` CI job is confirmed. D-05 and D-10 were already confirmed on 2026-11-06; nothing in `DECISIONS.md` is pending.

Benchmark run by the owner's Claude on the LocalWP site nf-dev (Apple silicon, MySQL 8.0.35, PHP 8.3.23, WP 7.1.4, ACF PRO 6.8.11, stock ACF meta, no tuned indexes), `wp nf-bench spike`, 3 runs each, every result identical between ACF and NF. Both datasets verified in full first (10,000/10,000 and 100,000/100,000 posts match).

| Query | 10k ACF ms | 10k NF ms | 10k speed-up | 100k ACF ms | 100k NF ms | 100k speed-up |
| --- | --- | --- | --- | --- | --- | --- |
| Archive: featured, upcoming, by date, page 1 + total (716 / 7,360 matches) | 172–175 | 7.2–9.0 | 19–24x | 2,121–2,666 | 29.6–37.6 | 69–72x |
| Deep page: featured by date, page 20 + total | 208–219 | 10.5–12.5 | 18–20x | 2,838–3,072 | 38.2–41.4 | 74–77x |
| Reverse relationship: artist 123 (49 / 484 matches) | 17–20 | 1.9–2.1 | 9–11x | 183–186 | 3.8–5.6 | 33–48x |

- Stock ACF grows about 12x from 10k to 100k; NF grows about 4x. The gap widens with size, as the design doc predicts.
- At 10k the local speed-up (18–24x) is lower than CI's (53–77x): ACF is faster locally, NF slightly slower. The 100k figures agree with CI's 10k order of magnitude.

### Problems found while running it (fixed or queued)
- `wp nf-bench spike` crashed: `bench/bootstrap.php` did not load `SpikeBenchmark.php`. Fixed.
- `generate --store=nf --posts-exist` refused because the posts exist, which is what the flag is for. Fixed.
- **Schema upgrades:** the pivot tables from 1.3 were never created on a site where NF was already active; the schema installs only on activation. Queued as WP 2.0.
- **Bridge type map vs design doc:** `TypeMap` stores time pickers as `str` (design doc: `int` seconds), date-time pickers as `str` (design doc: `date`) and `text` without `maxlength` in `nf_text` (design doc: `str`, over 191 chars spills to `text`). The bench writer follows the design doc, so the two registered `field_nfbench_sessions_time` and `_room` with different types and the second writer failed. Queued in WP 2.1's "Done when": one mapping, the design doc's.
- Test isolation: NF tables are real in tests and CI starts from a fresh database, so leftover field rows only broke repeated local runs. Fixed for `SpikeQueryTest`; `StoreTest` and `BridgeTest` still leave rows with non-clashing names.
