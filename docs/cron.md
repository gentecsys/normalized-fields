# Cron setup for Normalized Fields

NF runs long work (pivot rebuilds, backfills, verify, statistics and the ACF port) as **background jobs on WP-Cron**. NF does not use Action Scheduler. Every job runs in slices of about 15 seconds (filter `nf/jobs/budget`) and queues its next slice straight away, so **a job moves forward only as often as WP-Cron fires**. How you set up cron decides whether a 100k-post rebuild takes minutes or days.

> Owner requirement: the release README and the user documentation must include these examples (PLAN.md, WP 4.10). Keep this page current whenever NF adds or changes background work.

## What NF needs

| Need | Why |
| --- | --- |
| WP-Cron fires **every 1–5 minutes** | Each firing runs one slice of each due job. Hourly cron means one 15-second slice per hour. |
| A PHP time limit of **30 s or more** for cron requests | Slices stop at 15 s plus one step; hosts with a lower limit cut slices short (the job resumes, but more slowly). |
| Nothing else | Jobs are only ever queued by an admin action, WP-CLI or a running job. An anonymous request to `wp-cron.php` runs only what those queued; it cannot start a job. Schema upgrades never run from cron (they are manual: the admin button or `wp nf upgrade`). |

## Recommended: turn off traffic cron and run a real one

WordPress's default WP-Cron fires only when someone visits the site, so a quiet site or a cached site (most visits served by a CDN) may barely run it. Turn it off and trigger it on a schedule instead.

**1. In `wp-config.php`:**

```php
define( 'DISABLE_WP_CRON', true );
```

**2. Add one system cron entry. WP-CLI is preferred** (no HTTP request, no web server time limit, works when `wp-cron.php` is blocked at the server):

```cron
# Every minute: run all due WP-Cron events, including NF jobs.
* * * * * cd /var/www/example.com/htdocs && /usr/local/bin/wp cron event run --due-now --quiet >/dev/null 2>&1
```

Use the PHP user that owns the site (for example `crontab -u www-data -e`) so files the job writes keep the right owner.

**Or over HTTP**, when you can't run WP-CLI on the server:

```cron
*/2 * * * * curl -fsS --max-time 60 https://example.com/wp-cron.php >/dev/null 2>&1
```

Request `wp-cron.php` with no `doing_wp_cron` value.

### Multisite

Each site has its own cron. Run due events for every site:

```cron
*/2 * * * * cd /var/www/example.com/htdocs && /usr/local/bin/wp site list --field=url --archived=0 --deleted=0 | xargs -I{} /usr/local/bin/wp cron event run --due-now --quiet --url={} >/dev/null 2>&1
```

### Pantheon

- Pantheon's WordPress upstream disables WP-Cron and runs **Pantheon Cron once an hour**. That is too slow for NF jobs (one slice an hour), and it does not run on sleeping environments. Multisite on Pantheon uses regular WP-Cron by default. See [Cron for WordPress](https://docs.pantheon.io/guides/wordpress-developer/wordpress-cron).
- Use an external scheduler that calls the site every 1–5 minutes while jobs are running. Either use an HTTP cron service (EasyCron, cron-job.org and similar) on `https://<env>-<site>.pantheonsite.io/wp-cron.php`, or run Terminus from a scheduler you control, for example a GitHub Actions workflow:

```yaml
# .github/workflows/nf-cron.yml (GitHub schedules run at most every 5 minutes, and may run late)
name: NF cron
on:
  schedule:
    - cron: '*/5 * * * *'
  workflow_dispatch:
jobs:
  cron:
    runs-on: ubuntu-latest
    timeout-minutes: 10
    steps:
      - uses: pantheon-systems/terminus-github-actions@v1
        with:
          pantheon-machine-token: ${{ secrets.PANTHEON_MACHINE_TOKEN }}
      - run: terminus wp my-site.live -- cron event run --due-now
```

- Pantheon caps a cron run at 180 seconds. NF's 15-second slices fit within that.
- For one-off large jobs (the first port, a full rebuild), running them to the end from WP-CLI is faster than waiting for cron: `terminus wp my-site.live -- nf job run pivot_rebuild --args='{"pivot_id":1}'`.

### Managed hosts

Many managed hosts already run a server cron for WordPress. Check how often it fires: if it's less often than every 5 minutes, add your own entry as above or ask the host to shorten the interval.

## Running and checking jobs

```bash
# Start a job and run it to the end in this process (no cron needed):
wp nf job run pivot_rebuild --args='{"pivot_id":1}'

# Status of a job queued from the admin:
wp nf job status <job-id>

# Is anything queued, and when is it due?
wp cron event list --hook=nf_run_job

# Run what is due now (same as one cron firing):
wp cron event run --due-now
```

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| A job stays `queued` and its step count never changes | Cron isn't firing: `DISABLE_WP_CRON` is on with no system cron, or a sleeping environment. Check `wp cron event list --hook=nf_run_job`. |
| A job moves very slowly | Cron fires rarely (hourly host cron, low traffic), or the PHP time limit for cron requests is below 30 s. |
| A job reports `blocked` | The NF database update hasn't been run. Use the admin notice's button or `wp nf upgrade`; the job resumes afterwards. |
| `running` from two places at once | Expected: a second run returns while the first holds the job's lock. A lock left by a killed request expires after 120 s. |
