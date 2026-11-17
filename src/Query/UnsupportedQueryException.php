<?php
/**
 * Thrown when the compiler cannot answer a query from the pivot (WP 3.1a).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Query;

defined( 'ABSPATH' ) || exit;

/**
 * The caller falls back to core (WP_Query integration) or, from WP 3.1b, to the typed-table probes.
 */
final class UnsupportedQueryException extends \RuntimeException {}
