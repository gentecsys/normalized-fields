<?php
/**
 * NF's own public procedural API (contracts/api.md). Thin wrappers only; no logic here.
 * Functions are added by the WPs that own them, each guarded by function_exists().
 *
 * @package NF
 */

declare(strict_types=1);

// No ABSPATH guard: Composer CLI tools load this file outside WordPress, and it only declares guarded functions.
