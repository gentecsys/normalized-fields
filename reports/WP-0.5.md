# WP 0.5: Contracts v0

State: in-review. Agent: A0 (run by the daily build agent).

## What changed
- `contracts/schema.sql`: full logical DDL (meta, fields, rows, six typed tables, pivot sets/slots/pivot/row pivot, dict, count, count cache, archive; PostgreSQL-only and network user tables described).
- `contracts/backend.md` (new): backend adapter and dialect interfaces, rules, result-equality and multisite notes.
- `contracts/rest.md`: public part specified (routes, parameter grammar, response shapes, cursor, headers, limits and filter names, error codes, caching, core extension, Block Bindings args). Admin-private routes deferred to Phase 4.
- `contracts/nf-json.schema.json`: JSON Schema for NF JSON v1.
- `DECISIONS.md`: entry for the choices made.

## Tests
None runnable: documentation and schema only. The JSON Schema was parsed with Python's `json` module. The SQL was not executed against a database; WP 1.1 will run it through dbDelta in CI.

## Contract change requested / open questions
- None blocking. Owner to review the additions listed in the DECISIONS entry (pivot `parent_id`/`modified`, `nf_json` as LONGTEXT) at G0.
- `contracts/api.md` ranking-by-usage deviation from WP 0.4 is still open.

## Follow-ups
- A2 should check `schema.sql` through dbDelta formatting rules in WP 1.1 (multiple columns per line in `nf_pivot` may need one per line).
- A reviewer pass by A13 is the "Reviewed by A13" acceptance criterion.
