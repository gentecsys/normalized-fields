<?php
/**
 * One ACF API call in a scenario (for example get_field or update_field).
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

final class Step {

	/**
	 * @param string  $function ACF function name, for example 'update_field'.
	 * @param mixed[] $args     Positional arguments.
	 * @param string  $label    Key under which the result is recorded.
	 */
	public function __construct(
		public readonly string $function,
		public readonly array $args,
		public readonly string $label = '',
	) {}
}
