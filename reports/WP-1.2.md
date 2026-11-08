# WP 1.2: Bridge mode

Agent: A5 (daily build agent). Branch `wp/1.2-bridge-mode`.

## What changed
- `src/Acf/Bridge.php`: with ACF active, mirrors ACF value saves into the NF store (write-both). Hooks: `acf/update_value/type=repeater` at priority 1 (captures the raw rows before ACF's Series handler replaces them with a row count) and 99 (ends the Series), `acf/update_value` at 99 for top-level fields, `acf/delete_value`. Sub-field saves ACF makes while saving a Series are ignored (depth counter). Errors never break the ACF save; they fire `nf/bridge/error`.
- `src/Acf/TypeMap.php`: ACF field type to `DataTable` / multi, and value normalisation (empty becomes removal, `Ymd` becomes a DATETIME, object values become IDs).
- `src/Plugin.php`: `fields()` and `store()` accessors; `Bridge` registered as a service.
- `tests/unit/Acf/BridgeTest.php`: decode of every `$post_id` form, type map, scalar and relationship write and clear, Series write with sub-field suppression and delete, failure isolation.

## Deviations
- The design names `acf/pre_update_metadata` etc. Those hooks carry only the meta name, not the field key, so this WP uses `acf/update_value` (which has the field array) and mirrors instead of replacing storage. ACF still writes `wp_postmeta`. Taking over reads (`acf/pre_load_metadata`) and ACF 6.5 by-field layer is left for later.
- Ownership: edited `src/Plugin.php` (A1) for the accessors, as in WP 1.1.
- Series are one level deep; nested Series sub-fields are skipped.
- Text fields with no `maxlength` go to `nf_text` (a `nf_str` value over 191 chars would throw).

## Contract change requested
None.

## Follow-ups
- Backfill for existing postmeta, `acf/pre_load_metadata` read takeover, Series row deletion on `delete_post`, the `dec` canonical form (see WP 1.1), real-ACF end-to-end admin save test (needs wp-env with SCF; WP 1.4 differential check).

## Verification (this run)
- `php -l` on every new/changed file: clean.
- phpunit, phpstan, WPCS: not run locally (no vendor; WPCS phar unavailable; phpcs phar downloaded but cannot load the WordPress standard). CI is the authoritative check.
- Tests drive the hooks with ACF-shaped arguments; they do not install ACF.
