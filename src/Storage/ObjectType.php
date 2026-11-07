<?php
/**
 * Object types.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * What a value belongs to (nf_*.object_type).
 */
enum ObjectType: int {
	case Post    = 1;
	case Term    = 2;
	case User    = 3;
	case Comment = 4;
	case Option  = 5;
}
