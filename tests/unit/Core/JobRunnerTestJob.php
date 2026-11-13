<?php
/**
 * Job used by JobRunnerTest.
 *
 * @package NF
 */

use NF\Core\Jobs\JobInterface;

/**
 * Counts to a target, one per step.
 */
class JobRunnerTestJob implements JobInterface {

	/**
	 * Steps taken across all runs, for assertions.
	 *
	 * @var int
	 */
	public int $calls = 0;

	/**
	 * Job type.
	 *
	 * @return string
	 */
	public function key(): string {
		return 'counting';
	}

	/**
	 * Initial state.
	 *
	 * @param array<string,mixed> $args Args.
	 * @return array<string,mixed>
	 */
	public function init( array $args ): array {
		return array(
			'n'      => 0,
			'target' => (int) ( $args['target'] ?? 3 ),
			'done'   => false,
		);
	}

	/**
	 * One step.
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 */
	public function step( array $state ): array {
		++$this->calls;
		++$state['n'];
		$state['done'] = $state['n'] >= $state['target'];
		return $state;
	}
}
