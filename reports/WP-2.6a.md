# WP 2.6a: Port, scan and dry-run report

Agent: A9 (daily build agent). Branch `wp/2.6a-scan-v2`.

## What changed
- WP 2.6 split in `PLAN.md` into 2.6a (scan), 2.6b (posts: read/write job), 2.6c (terms, users, options, comments), 2.6d (verify, re-port, WP-CLI). 2.5b marked merged.
- `NF\Migration\Scanner::scan( $groups )`: pure; per collection field and type counts, Series and clone counts, depth, unknown (third-party) types, flexible content fields. A collection with flexible content gets status `skipped` (ADR-0008); the rest are `portable`. Totals included.
- `tests/unit/Migration/ScannerTest.php`: counts and nesting, nested flexible content, unknown types, empty input.

## Tests / tooling
Nothing ran locally (no `vendor/`); `php -l` passes. CI is authoritative.

## Review follow-up
Branch rebuilt on current origin/main (the first push was cut from a stale local main; PR #51 is superseded). Fixed: PLAN 2.6a depends on 2.1 only (2.5 moved to 2.6b), one-item-per-line arrays in tests, docblock alignment, missing `type` reported as `invalid`, `icon_picker` known. Left for later: `KNOWN_TYPES` is a second list next to `TypeMap`; fields inside flexible layouts still count in totals; clone targets are not resolved (2.6b).

## Resume / next
- 2.6b: the caller that feeds the scanner (`acf_get_field_groups()` plus `acf_get_fields()`) and raw value reading belong there, with a `JobRunner` job.
- Not yet in the report (design "Report"): object/value counts, orphaned meta, options pages, raw-SQL code scan. Planned for 2.6b/2.6c.

## Contract change requested
None.
