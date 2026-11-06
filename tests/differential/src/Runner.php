<?php
/**
 * Runs scenarios on two targets and compares every step.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

final class Runner {

	public function __construct( private readonly Comparator $comparator = new Comparator() ) {}

	/**
	 * @param ScenarioInterface[] $scenarios Scenarios to run.
	 * @param TargetInterface     $left      Reference target (ACF).
	 * @param TargetInterface     $right     Target under test (ACF again for the baseline, NF later).
	 */
	public function run( array $scenarios, TargetInterface $left, TargetInterface $right ): Report {
		$diffs = array();
		$steps = 0;
		foreach ( $scenarios as $scenario ) {
			$left->reset();
			$right->reset();
			foreach ( $scenario->steps() as $index => $step ) {
				$label = '' !== $step->label ? $step->label : $index . ':' . $step->function;
				array_push(
					$diffs,
					...$this->comparator->compare(
						$scenario->name(),
						$label,
						$this->capture( $left, $step ),
						$this->capture( $right, $step )
					)
				);
				++$steps;
			}
		}
		return new Report( count( $scenarios ), $steps, $diffs );
	}

	/**
	 * Exceptions are results too: both targets must fail the same way.
	 *
	 * @param TargetInterface $target Target.
	 * @param Step            $step   Step.
	 * @return mixed
	 */
	private function capture( TargetInterface $target, Step $step ): mixed {
		try {
			return $target->call( $step );
		} catch ( \Throwable $e ) {
			return array( '__exception' => get_class( $e ) );
		}
	}
}
