# WP 3.0b: Scan reports off-type values

Agent: A9 (daily build agent). Branch `wp/3.0b-offtype-scan`.

## What changed
- `NF\Migration\OffTypeScanner`: lists top-level scalar ACF fields (text, textarea, number, range, email, url, password, wysiwyg, oembed, radio, button_group, true_false, date/time/color pickers), then counts serialized arrays (`meta_value LIKE 'a:%'`) in `postmeta` per field, capped (default 1000) via `COUNT(*)` over a `LIMIT` subquery. `registered()` returns the IDs in `nf_offtype_fields`.
- `wp nf port scan` adds `offtype` (name, type, arrays, capped) and `offtype_fields` (the health data) to the JSON report, and prints one warning per field in text mode saying the values are kept in `nf_json` and are not queryable.
- `tests/unit/Migration/OffTypeScannerTest.php`.

## Tests / tooling
`php -l` clean. phpunit, phpcs and phpstan did not run locally; CI is authoritative.

## Limits / follow-ups
- Posts only, top-level fields only (Series/Group sub-fields, terms, users, comments and options are not scanned).
- The admin health screen (4.4) should read `offtype_fields` from `OffTypeScanner::registered()`; no health screen exists yet.

## Contract change requested
None.
