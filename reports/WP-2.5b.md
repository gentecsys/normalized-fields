# WP 2.5b: Job failure cap

Agent: A1 (daily build agent). Branch `wp/2.5b-job-failure-cap`.

## What changed
- `JobRunner`: a step that throws counts an attempt (`attempts`, `error` in the record; the step's state is not kept, so the retry repeats it). A successful step resets the count. After `MAX_ATTEMPTS` (3) the status is `failed`: the run stops, the watchdog is cleared (nothing is re-queued), action `nf/jobs/failed( $id, $error )` fires. `run()` on a failed job returns `failed` and does no work; `start()` on a failed job restarts it from `init()`.
- `JobCommand::status` prints the error and attempts of a failed job.
- `docs/cron.md`: short section "A job that keeps failing" (cron documentation rule).
- Tests: `JobRunnerTest` (failure after 3, reset on success, restart), new `PivotRebuildJobTest` (chunked steps, total, done, missing pivot id).

## Tests / tooling
Nothing ran locally (no `vendor/`, no WP test suite in the sandbox); `php -l` passes. CI is authoritative.

## Deviations / follow-ups
- `JobCommand` has no unit test: the WP-CLI classes exist only as static-analysis stubs. Covered indirectly through `JobRunner`.
- Hook `nf/jobs/failed` to be listed in `contracts/api.md` by A0 (with `nf/jobs/blocked`, `nf/jobs/done`, `nf/jobs/budget`).
- Still open from 2.5: lazy job list in `Plugin::jobs()`, backoff on repeated blocked retries.

## Contract change requested
None.
