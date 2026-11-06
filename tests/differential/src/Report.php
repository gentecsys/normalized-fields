<?php
/**
 * Outcome of a differential run.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

final class Report {

	/**
	 * @param int    $scenarios Scenarios run.
	 * @param int    $steps     Steps compared.
	 * @param Diff[] $diffs     All differences found.
	 */
	public function __construct(
		public readonly int $scenarios,
		public readonly int $steps,
		public readonly array $diffs,
	) {}

	public function is_clean(): bool {
		return array() === $this->diffs;
	}

	public function summary(): string {
		$lines = array( sprintf( '%d scenarios, %d steps, %d diffs', $this->scenarios, $this->steps, count( $this->diffs ) ) );
		foreach ( $this->diffs as $diff ) {
			$lines[] = '  ' . $diff->describe();
		}
		return implode( "\n", $lines );
	}
}
