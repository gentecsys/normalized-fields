# WP 3.0h: Term/user pivot sets (slice 1)

Agent: A3 (daily build agent). Branch `wp/3.0h-term-user-sets`.

## What changed
- `Pivot::setType()` reads a set's object type; `sync()` reads typed values and rollups with it, and passes it to `RowPivot::syncRows()` / `rollup()` / `rowIds()` (new optional `ObjectType` parameter, default Post).
- `Pivot::rebuildFromCoreChunk()` / `coreObjects()` now cover term sets (taxonomy = subtype; parent from `term_taxonomy`; status publish) and user sets (all users from `wp_users`; `user_registered` goes to `published`).

## Tests
Added `tests/unit/Pivot/TermUserSetTest.php` (term and user rebuild from core). Only `php -l` ran locally (no WP test env); CI is authoritative.

## Split
nf_query parity test on the bench type and the anonymous-GET-writes-nothing test move to new row 3.0i.

## Contract change requested
None. Note: a user set's subtype is only a label (all users are included); on multisite this should later be limited to the site's members.
