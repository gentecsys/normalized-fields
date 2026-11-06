# Gate G0: foundations and contracts v0

Written 2026-11-06 by the daily build agent. The build is paused until the owner approves G0.

## What was built (Phase 0, all WPs merged)
- 0.1, 0.2, 0.2b: plugin skeleton, wp-env and CI matrix, class-based `NF\` structure with Composer, renamed to Normalized Fields.
- 0.3a, 0.3c: deterministic dataset generator (10k/100k) in ACF format, matching the design benchmark (66 postmeta rows per post).
- 0.4: ACF API inventory in `contracts/api.md`.
- 0.5: contracts v0: `schema.sql`, `backend.md`, `rest.md` (public part), `nf-json.schema.json`.
- 0.6a-c: differential harness (runner, normalizer, comparator), ACF-vs-ACF baseline against real SCF in CI, scenarios for scalar, Series and relationship fields.

## Test and benchmark results
- Latest main commit: full CI matrix (PHP 8.1-8.4, WP 7.0/7.1/nightly, MySQL 5.7/8.0, MariaDB 10.4/10.11) and the differential baseline job are green.
- Differential baseline (ACF vs ACF on SCF): zero diffs.
- No NF benchmarks yet; the generator is the Phase 0 deliverable. Benchmarks start in 1.5.

## Open risks
- ACF PRO parity is only checked on the owner's LocalWP site (no licence in CI).
- 0.4 ranked the API by tier rather than measured usage (flagged by the WP 0.4 agent).
- Contracts v0 includes choices beyond the design doc (see the DECISIONS.md entry "Contracts v0 drafted"): `nf_pivot` gets `parent_id`, `modified`, `by_modified`, `by_author`; `nf_json.value` is LONGTEXT/jsonb; added `nf_meta` and `nf_count_cache`. Please review them.
- WP 0.6a's `nf:ready` label was once denied by the permission classifier; check nothing was left unlabelled (all Phase 0 PRs are merged).

## Decisions the owner must make
- D-05 (ACF PRO + SCF both supported): default, pending owner confirmation.
- D-10 (PostgreSQL scope: NF tables only, core stays in MySQL): default, pending owner confirmation.
- Review contracts v0 (`contracts/`) and freeze it for Phase 1.
- D-06 (pilot site) is needed by G1.

## To approve
Add `- G0: approved YYYY-MM-DD (notes)` under "Gate approvals" in `DECISIONS.md`, or `- G0: changes requested YYYY-MM-DD: <what>`.
