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
