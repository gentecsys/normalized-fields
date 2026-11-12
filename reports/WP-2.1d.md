# WP 2.1d: Clone field resolves to cloned fields' storage

## What changed
- `TypeMap::storage()`: `clone` -> `DataTable::None` (no value of its own).
- `TypeMap::isGroupClone()`; display `group` clones use `composite()` like a Group (children on the parent).
- `TypeMap::seamlessFields()`: seamless clone yields its cloned fields at the clone's level, names prefixed with the clone name when `prefix_name` is on (unless ACF already did). Each keeps its own key.
- `Bridge`: `acf/update_value/type=clone` goes through `beforeGroup` (group display only); seamless clone saves are ignored (cloned fields save themselves); `ensure()` registers a seamless clone's fields as top-level; `isSubField()` treats fields flagged `_clone` under a top-level seamless clone as top-level.

## Tests
Added `TypeMapTest::test_clone_resolution`. Only `php -l` ran locally (no vendor/MySQL here); CI is the check.

## Deviations / notes
- Relies on ACF resolving cloned fields into the clone's `sub_fields` and flagging them with `_clone`; not verified against real ACF PRO (follow-up for nf-dev / A11 differential).
- Clones inside a Series, and clones that clone whole groups/collections referencing other clones, are not handled.
- Bridge-level behaviour has no unit test.

## Review fixes
- B1: `onUpdate` now returns early for every `clone` (group clones are mirrored by `beforeGroup`), so a group clone is not wiped.
- N2: the seamless short-circuit in `isSubField` is explicit.

## Follow-ups
- Verify on nf-dev with ACF PRO: cloned field keys (ACF may rewrite to `<clone>_<key>`), `parent`, `_clone`; then pin in a fixture test (A11 differential).
- Bridge-level round-trip tests for clones; clones in a Series; nested clone prefix chains; 64-char key limit for rewritten keys.

## nf-dev check with ACF PRO (fix/2.1d-clone-identity)
ACF saves every cloned value with the **source** field's key (`key` = `field_src`, `_clone` = clone key, `parent` = the source group), and loads a seamless clone's fields as `<clone>_<source>` with the source key in `__key`. A group clone's loaded sub-fields keep the source keys. Keyed by `field_key` alone, every clone of one source shared one NF field:
- two seamless clones on one post (`title=S`, `pref_title=P` in ACF) left one NF row, `P`;
- the NF name was whichever clone registered first (`title`, not `pref_title`);
- a group clone's values landed on the source fields at top level, and reading the clone returned `[]`.

Fix:
- `TypeMap::cloneKey()` / `scope()`: a cloned field (and its nested sub-fields) gets the key `<clone key>_<source key>`, hashed past 64 characters. The Bridge scopes any field with `_clone` set before mirroring or deleting.
- Group clones scope their parts, name them by `__name` (ACF returns a prefixed group clone by source name), and read values keyed by the source key.
- `isSubField()` finds a cloned field's owner through `_clone`, not `parent` (the source group).
- `tests/differential/acf/NfCloneDifferentialTest.php` (real ACF / SCF) fails without the fix.

Known differences: when ACF itself stores two clones under one meta name (two unprefixed seamless clones of one source, or a source field also used directly), ACF keeps one value and NF keeps one per clone. A clone inside a group or a Series is still stored with its parent (not scoped). Rows registered by 2.1d before this fix (source keys at top level) are not migrated; there are no installs yet.

## Contract change requested
None.
