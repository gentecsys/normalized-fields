<?php
/**
 * One difference between two results, at a path such as 'rows[2].room'.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

final class Diff {

	public function __construct(
		public readonly string $scenario,
		public readonly string $step,
		public readonly string $path,
		public readonly mixed $left,
		public readonly mixed $right,
	) {}

	public function describe(): string {
		return sprintf(
			'%s / %s @ %s: %s != %s',
			$this->scenario,
			$this->step,
			'' === $this->path ? '(root)' : $this->path,
			var_export( $this->left, true ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			var_export( $this->right, true ) // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
		);
	}
}
