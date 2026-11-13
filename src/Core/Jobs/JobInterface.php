<?php
/**
 * A resumable background job.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Core\Jobs;

defined( 'ABSPATH' ) || exit;

/**
 * A job is a series of small steps. All progress lives in the state array, which the runner stores after every
 * step, so a killed request or a PHP timeout loses at most one step.
 */
interface JobInterface {

	/**
	 * Job type, used in the job id and in WP-CLI (`wp nf job run <key>`).
	 *
	 * @return string
	 */
	public function key(): string;

	/**
	 * Initial state for the given arguments.
	 *
	 * @param array<string,mixed> $args Job arguments (JSON-safe scalars and arrays).
	 * @return array<string,mixed>
	 */
	public function init( array $args ): array;

	/**
	 * Do one small step. Must be safe to repeat after a crash (idempotent for the step that was running).
	 *
	 * @param array<string,mixed> $state State from init() or the previous step.
	 * @return array<string,mixed> New state; include `done => true` when finished.
	 */
	public function step( array $state ): array;
}
