<?php
/**
 * Job used by JobRunnerTest: throws on chosen steps.
 *
 * @package NF
 */

use NF\Core\Jobs\JobInterface;

/**
 * Throws until it has been called `fail_times` times, then finishes.
 */
class JobRunnerTestFailingJob implements JobInterface {

	/**
	 * Calls so far.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Calls that throw (the first N).
	 *
	 * @var int
	 */
	public int $fail_times = 99;

	/**
	 * Job type.
	 *
	 * @return string
	 */
	public function key(): string {
		return 'failing';
	}

	/**
	 * Initial state.
	 *
	 * @param array<string,mixed> $args Args.
	 * @return array<string,mixed>
	 */
	public function init( array $args ): array {
		return array(
			'args' => $args,
			'done' => false,
		);
	}

	/**
	 * One step.
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 * @throws \RuntimeException While failing.
	 */
	public function step( array $state ): array {
		++$this->calls;
		if ( $this->calls <= $this->fail_times ) {
			throw new \RuntimeException( 'boom ' . $this->calls ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		$state['done'] = true;
		return $state;
	}
}
