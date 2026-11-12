# WP 2.2b: Bitmask slots for multi-valued fields

Agent: A3 (daily build agent). Branch `wp/2.2b-bitmasks`.

## What changed
- `src/Pivot/Dictionary.php`: `bit()` assigns the lowest free bit (0-63) per field in `nf_dict.bit`, never reused, null when the value is over 191 chars or the field has 64 bits already; `findBit()` is read-only.
- `src/Pivot/Pivot.php`: multi-valued top-level `str` fields (checkbox, multi-select) can take `m1`-`m4`. `sync()` writes the OR of the value bits (0, never NULL, when empty) as an unsigned decimal string so bit 63 survives PHP's signed ints. Single-valued and non-str multi fields are refused for `m` slots.
- `tests/unit/Pivot/BitmaskTest.php`: stable bits, read never writes, masks incl. empty and update, all 64 bits plus overflow, rebuild equals write-through, assignment rules.

## Deviations / notes
- A value with no bit (65th value of a field) is left out of the mask. Design says to spill into a second m-slot or demote the field; that is not built here (follow-up, with the admin notice).
- Queries do not use `m` slots yet (compiler, 3.1). Rollups of bits are 2.2c/3.4.

## Contract change requested
None.

## Verification
- `php -l` on changed files: clean.
- phpunit, phpcs, phpstan: not run locally (no vendor). CI is authoritative.

## Resume from
If CI fails, check `Pivot::mask()` / `Dictionary::bit()` and the test. Next: 2.2c row pivot and rollups.
