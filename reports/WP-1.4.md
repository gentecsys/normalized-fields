# WP 1.4: Differential check (NF vs ACF)

Agent: A11 (daily build agent). Branch `wp/1.4-differential-check`.

## What changed
- `tests/differential/src/NfProbe.php`: delete, write (one or two values, so the second is an overwrite) through ACF, read, delete, read. The write always goes through ACF so bridge mode mirrors it; the read comes from ACF storage (`get_field( .., false )`, Series from row meta) or from the NF tables (`Store::get`/`getRows`). Values are compared in a canonical form (empty = null, numeric strings as numbers, `Y-m-d 00:00:00` as `Ymd`).
- `tests/differential/src/NfBridgeScenario.php`: scalar (text, number, date, true/false, checkbox), relationship and Series (2 rows, then overwritten with 1, then empty), plus an empty relationship, on every bench post.
- `tests/differential/acf/NfBridgeDifferentialTest.php`: left target = ACF reads, right target = NF reads, asserts zero diffs and that the probe sees what the bridge copied. Runs as administrator (bridge never writes for logged-out GET/HEAD, D-18).
- `tests/differential/acf/bootstrap.php`: loads `normalized-fields.php` after ACF/SCF and installs the NF schema on `init`. This also activates the bridge for `AcfWriteBaselineTest` (harmless: it only mirrors).
- `tests/differential/tests/NfBridgeScenarioTest.php`: pure-PHP tests of the canonical form and the step list.

## Tests that ran
- Pure-PHP `differential-harness` suite via the phpunit phar: 8 tests, 74 assertions, green.
- NOT run locally: the SCF-backed `differential` job (needs WP test lib, MySQL, SCF), phpcs, phpstan. CI is authoritative; the new test is unverified against a real database.

## Deviations / notes
- Compares ACF *raw* storage with NF, not formatted values: until the standalone ACF API (3.6) exists there is no NF `get_field()` to compare against. Formatted-value parity belongs to 3.6.
- Series are read from ACF row meta because `get_field( 'sessions', .., false )` returns only a count.
- Nested Series are not stored in NF yet (see 1.2b follow-up) and are not covered.

## Resume from
If the `differential` CI job is red, read the first diff in the failure message: likely a representation difference the canonical form misses (for example decimal scale or time format), or a bridge gap.

## Contract change requested
None.
