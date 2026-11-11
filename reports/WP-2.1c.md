# WP 2.1c: Composite field types (link, google_map, group)

## What changed
- `TypeMap::storage()`: `group`, `link`, `google_map` -> `DataTable::None` (parent holds no value).
- `TypeMap::composite()` lists a type's children: Link `url/title/target` (str), Google Map `lat/lng` (dec), `address` (str), `zoom` (int); Group uses its own sub-fields. Pseudo keys are `<key>__<name>` (fit 64 chars). `TypeMap::normalizeComposite()` normalises values.
- `Store::get/set/delete` handle `none` parents with children (stored on row 0, nested groups recurse); a set replaces the whole value, absent children are removed.
- `Bridge`: registers children (`registerParts`) and mirrors composite values via `normalizeComposite`.
- PLAN: clone split to new **2.1d**.

## Tests (added, NOT run here)
`TypeMapTest::test_composite_parts`, `test_normalize_composite`; `StoreTest::test_group_like_round_trip`. Sandbox has no vendor/MySQL/WPCS; only `php -l` ran. CI is the check.

## Deviations / notes
- Groups inside a Series (repeater) are not handled: `Store::getRows/setRows` do not recurse into `none` children yet. Follow-up.
- Group sub-fields saved via `update_sub_field` on their own are not re-synced by the Bridge (only Series are); the group's own `update_value` mirrors the full array.
- Differential coverage belongs to A11.
