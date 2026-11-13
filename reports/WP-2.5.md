# WP 2.5: Background jobs

Agent: A1 (daily build agent). Branch `wp/2.5-background-jobs`.

## What changed
- `src/Core/Jobs/JobInterface.php`: a job is `key()`, `init( args )`, `step( state )`; all progress is in the state array.
- `src/Core/Jobs/JobRunner.php`: runs steps within a time budget (15 s, filter `nf/jobs/budget`), saving the state to option `nf_job_<id>` after every step. Queues the next run before the first step (watchdog `LOCK_TTL + 5` s), so a killed request or PHP timeout loses at most one step; a lock left by a dead request expires after 120 s. Refuses to start or run while `Upgrader::isCurrent()` is false (state kept, nothing written or queued, action `nf/jobs/blocked`); a run after the upgrade carries on. Fires `nf/jobs/done`.
- `src/Core/Jobs/Scheduler.php`: Action Scheduler when its functions exist, otherwise WP-Cron (`nf_run_job`). Only admin, WP-CLI or a running job schedule.
- `src/Core/Jobs/PivotRebuildJob.php`: resumable `Pivot::rebuildChunk()` loop plus `pruneRowPivot()` (closes the 2.2d follow-up); args `pivot_id`, `chunk`.
- `src/Core/Jobs/JobCommand.php`: `wp nf job run <key> --args=<json>` (runs to the end in-process), `wp nf job status <id>`.
- `src/Plugin.php`: `Plugin::jobs()` and the runner registered as a service.
- `tests/unit/Core/JobRunnerTest.php`: resume across runners, budget, id stability, lock/stale lock, blocked while schema behind, unknown job.

## Tests / tooling
Nothing could be run locally: no `vendor/`, no WordPress test suite in the sandbox, WPCS standard unavailable (phpcs phar only). `php -l` passes on all new files. CI is the authoritative check.

## Deviations / follow-ups
- **Action Scheduler is not bundled yet** (`woocommerce/action-scheduler` via Composer needs a regenerated `composer.lock` and the Strauss/registry setup, not possible offline here). The Scheduler detects it and falls back to WP-Cron today. Follow-up WP/slice: add the dependency and load it through its version registry.
- The admin progress screen that drives batches over REST belongs to WP 4.1/4.4 (A8).
- Other jobs (count-cache cleanup, pivot backfills, port pipeline in 2.6) implement `JobInterface` and are added in `Plugin::jobs()`.
- `Pivot::pruneDictionary()` is not part of the job (global, not set-scoped; see 2.2d follow-ups).

## Contract change requested
None.
