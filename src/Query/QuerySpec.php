<?php
/**
 * Immutable description of a query, input of the compiler (WP 3.1a).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Query;

use NF\Storage\ObjectType;
use NF\Storage\Status;

defined( 'ABSPATH' ) || exit;

/**
 * What the builder (WP 3.2) and the WP_Query translation (WP 3.3) both produce.
 */
final class QuerySpec {

	/**
	 * Constructor.
	 *
	 * @param ObjectType                           $type       Object type.
	 * @param string                               $subtype    Post type, taxonomy or ''.
	 * @param Status[]                             $statuses   Allowed statuses; empty means publish.
	 * @param Condition[]                          $conditions ANDed conditions.
	 * @param array<int,array{0:string,1:string}>  $orders     [ field, 'ASC'|'DESC' ] in priority order; empty means date DESC.
	 * @param int                                  $limit      Page size; 0 means no limit.
	 * @param int                                  $offset     Rows to skip.
	 */
	public function __construct(
		public readonly ObjectType $type,
		public readonly string $subtype,
		public readonly array $statuses = array(),
		public readonly array $conditions = array(),
		public readonly array $orders = array(),
		public readonly int $limit = 0,
		public readonly int $offset = 0
	) {}
}
