<?php
/**
 * A named, ordered list of ACF API calls that both targets must answer identically.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

interface ScenarioInterface {

	/** Unique scenario name, used in reports. */
	public function name(): string;

	/**
	 * Steps in execution order. Steps are plain data, so the same list runs on every target.
	 *
	 * @return Step[]
	 */
	public function steps(): array;
}
