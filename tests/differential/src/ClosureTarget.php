<?php
/**
 * Target that delegates each call to a closure (test doubles and adapters).
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

final class ClosureTarget implements TargetInterface {

	/**
	 * @param string        $name    Target name.
	 * @param \Closure      $handler Receives a Step, returns the result.
	 * @param \Closure|null $reset   Optional reset hook.
	 */
	public function __construct(
		private readonly string $name,
		private readonly \Closure $handler,
		private readonly ?\Closure $reset = null,
	) {}

	public function name(): string {
		return $this->name;
	}

	public function reset(): void {
		if ( null !== $this->reset ) {
			( $this->reset )();
		}
	}

	public function call( Step $step ): mixed {
		return ( $this->handler )( $step );
	}
}
