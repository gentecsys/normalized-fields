<?php
/**
 * Target that calls the real global ACF functions (get_field() and friends). Needs WordPress; used by WP 0.6b.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

final class GlobalFunctionTarget implements TargetInterface {

	/**
	 * @param string        $name  Target name.
	 * @param \Closure|null $reset Optional reset hook (truncate fixtures, flush caches).
	 * @param bool          $reset_each_call Also run the reset hook before every call (cold-cache reads).
	 */
	public function __construct(
		private readonly string $name,
		private readonly ?\Closure $reset = null,
		private readonly bool $reset_each_call = false,
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
		if ( $this->reset_each_call ) {
			$this->reset();
		}
		if ( ! function_exists( $step->function ) ) {
			throw new \BadFunctionCallException( 'Function does not exist: ' . $step->function ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return ( $step->function )( ...$step->args );
	}
}
