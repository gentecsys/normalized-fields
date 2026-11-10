# WP 1.5: Spike benchmark

Agent: A12 (daily build agent). Branch `wp/1.5-spike-benchmark`.

## What changed
- `bench/src/SpikeBenchmark.php`: builds the pivot (set, slots `featured=i1`, `event_date=t1`, `price=n1`, rows via `Pivot::sync`) for the NF store, then runs three queries through `WP_Query` on `wp_postmeta` (core query cache off) and through `SpikeQuery`, 5 runs each, median. Compares IDs, `found_posts`, `max_num_pages`. Equal sort values have no tie-break in core, so a different order among equal dates (also across a page boundary) counts as the same result.
- `bench/src/NfStore.php`: optional `$with_posts` (false when the ACF store already wrote `wp_posts`); `wp nf-bench generate --store=nf --posts-exist`.
- `wp nf-bench spike`: prints the markdown table, fails on any difference.
- `tests/unit/Bench/SpikeBenchmarkTest.php`: 300 posts by default (`NF_BENCH_POSTS`), asserts identical results; commits its own clean-up (the suite only rolls back, and leftover `artists` fields made `SpikeQueryTest` ambiguous).
- `.github/workflows/ci.yml`: job `bench-spike` runs it at 10,000 posts and prints the table in the job summary. **CI file is A1's; owner to confirm.**

## Results (CI run, 10k posts, MySQL 8.0, PHP 8.3, WP 7.0, stock ACF meta, no tuned indexes, shared GitHub runner)
| Query | Matches | ACF ms | NF ms | Speed-up | Same |
| --- | --- | --- | --- | --- | --- |
| Archive: featured, upcoming, by date, page 1 + total | 716 | 278.19 | 3.63 | 76.7x | yes |
| Deep page: featured by date, page 20 + total | 716 | 221.02 | 4.15 | 53.3x | yes |
| Reverse relationship: artist 123 | 49 | 21.29 | 1.99 | 10.7x | yes |

Zero diffs; NF is 10x-77x faster than stock ACF at 10k. Matches the design doc's order of magnitude (10k stock 974 / 21 ms). NOT run: 100k (CI time), the repeater-sub-field query and facets (not in the spike translator), ACF-tuned indexes.

## Verification
php -l local. CI on the PR head: 28/28 checks green (phpunit matrix, PHPCS, PHPStan, bench-spike). Numbers come from one shared-runner run (5-run median), so noisy.

Review round 1: `equivalent()` made strict (equal sort-key sequence, interior equal-key runs must hold the same ID set; edge runs may straddle a page), tolerated tie order is reported, negative unit test added, teardown asserts after COMMIT. Non-blocking left: CI file is A1's (owner to confirm); `price`->n1 slot unused; `spike` command does not check both stores exist; a 100k dispatch run is a tracked follow-up before G1.

## Deviations / follow-ups
- Only two spike query shapes exist (1.3), so the design doc's repeater and facet rows are not benchmarked.
- A 100k run needs a dispatch workflow or a local `wp nf-bench` run; the `rebuild()` N+1 noted in 1.3 is not used here (rows are synced once).
- Contract change requested: none.
