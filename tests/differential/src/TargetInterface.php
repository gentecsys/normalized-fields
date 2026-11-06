<?php
/**
 * Something that executes ACF API calls: real ACF, NF, or a test double.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

interface TargetInterface {

	/** Target name, used in reports (for example 'acf' or 'nf'). */
	public function name(): string;

	/** Prepare a clean state before a scenario runs. */
	public function reset(): void;

	/**
	 * Run one call and return its raw result. Exceptions are recorded by the runner.
	 *
	 * @param Step $step The call.
	 * @return mixed
	 */
	public function call( Step $step ): mixed;
}
