<?php
/**
 * WP-CLI: wp nf job.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Core\Jobs;

use NF\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Manage background jobs from the command line.
 */
final class JobCommand {

	/**
	 * Start a job and run it to the end in this process.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Job type, for example pivot_rebuild.
	 *
	 * [--args=<json>]
	 * : Job arguments as JSON, for example {"pivot_id":1}.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function run( array $args, array $assoc ): void {
		$decoded = json_decode( $assoc['args'] ?? '{}', true );
		if ( ! is_array( $decoded ) ) {
			\WP_CLI::error( 'Invalid --args JSON.' );
		}
		$runner = Plugin::jobs();
		$id     = $runner->start( sanitize_key( $args[0] ?? '' ), $decoded );
		if ( '' === $id ) {
			\WP_CLI::error( 'Unknown job type, or the NF schema is behind (run wp nf upgrade).' );
		}
		$status = 'queued';
		while ( 'queued' === $status ) {
			$status = $runner->run( $id );
		}
		'done' === $status ? \WP_CLI::success( "Job {$id} finished." ) : \WP_CLI::error( "Job {$id} ended as {$status}." );
	}

	/**
	 * Show a job's status.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Job id.
	 *
	 * @param string[] $args Positional arguments.
	 * @return void
	 */
	public function status( array $args ): void {
		$record = Plugin::jobs()->record( sanitize_key( $args[0] ?? '' ) );
		if ( null === $record ) {
			\WP_CLI::error( 'No such job.' );
		}
		\WP_CLI::log( sprintf( '%s: %d step(s), state %s', $record['status'], $record['steps'], (string) wp_json_encode( $record['state'] ) ) );
		if ( 'failed' === $record['status'] ) {
			\WP_CLI::log( sprintf( 'Error after %d attempt(s): %s', $record['attempts'] ?? 0, $record['error'] ?? '' ) );
		}
	}
}
