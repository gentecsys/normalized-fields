# WP 2.2a: Dictionary and string slots

Agent: A3 (daily build agent). Branch `wp/2.2a-dictionary`. WP 2.2 (size L) was split into 2.2a-2.2d in `PLAN.md`.

## What changed
- `src/Pivot/Dictionary.php`: `id()` (get-or-create, write paths only), `find()` (read-only, for query paths), `value()`, `fits()` (191 characters). Ids are never reused.
- `src/Pivot/Pivot.php`: single-valued top-level `str` fields can take `s1`-`s8`. `sync()` stores the dictionary id; a value over 191 characters or no value leaves the slot NULL. Optional 4th constructor argument for the dictionary (defaults to one on the same backend); `dictionary()` getter.
- `src/Install/Schema.php`: `nf_dict` exactly as `contracts/schema.sql` section 10. `Upgrader::SCHEMA_VERSION` 2 -> 3, so existing sites get the table through the 2.0 upgrade path.
- `tests/unit/Pivot/DictionaryTest.php`: stable and reversible ids, `find()` never writes, length rule, sync (shared ids, NULL cases, change and clear), rebuild equals write-through, slot assignment rules.

## Deviations / notes
- Edits `src/Install/` (A1 paths) for the DDL and version bump, as 1.3 did; A1 should review.
- `SpikeQuery` still returns null for `str` slots (falls back to core), so nothing in the query path changes. Translating `= 'value'` through `Dictionary::find()` belongs to the compiler (3.1).
- Values over 191 characters have no slot value; filters on them must use `nf_str`/`nf_text` (the prefix rule from 2.1a).
- No dictionary pruning: unused entries stay until a later cleanup job (2.2d or 2.5).

## Contract change requested
None.

## Verification
- `php -l` on changed files: clean.
- phpunit, phpcs, phpstan: not run locally (no vendor; dist downloads blocked). CI is authoritative.

## Resume from
If CI fails, fix `src/Pivot/Dictionary.php` / `Pivot::sync()` or the test. Next: 2.2b bitmasks (`m` slots via `nf_dict.bit`).
