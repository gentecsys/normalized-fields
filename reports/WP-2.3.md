# WP 2.3: Archive and revisions

Agent: A2 (daily build agent). Branch `wp/2.3-archive-revisions`.

## What changed
- `src/Storage/Archive.php`: `capture()` (snapshot every top-level field of a live object into one `nf_archive` row), `write()`, `read()` (preview / compare, no typed-table access), `ids()`, `restore()` (writes the snapshot back through `Store`; fields empty at snapshot time are cleared, fields registered later are left alone), `delete()`, `deleteForParent()`.
- `src/Install/Schema.php`: `nf_archive` exactly as `contracts/schema.sql` section 12. `Upgrader::SCHEMA_VERSION` 3 -> 4 (A1 paths, same precedent as 2.2a).
- `tests/unit/Storage/ArchiveTest.php`: capture + preview, restore (scalar, multi-valued, Series rows), clear-on-restore, recapture, delete.

## Deviations / notes
- No WordPress hooks yet: wiring `_wp_put_post_revision` / autosave / `wp_restore_post_revision` to `Archive` belongs to the ACF layer (2.4 / 3.6, A5).
- `capture()` snapshots every registered top-level field (one read per field). Limiting it to the fields of the post's collections needs the ACF location layer; follow-up for A5.
- Snapshots are not pivot-synced on restore; pivot write-through is wired by the pivot/hook WPs.

## Contract change requested
None.

## Verification
- `php -l` clean. phpunit, phpcs, phpstan not run locally (no vendor; dist downloads blocked). CI is authoritative.
