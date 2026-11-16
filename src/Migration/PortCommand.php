<?php
/**
 * WP-CLI: wp nf port (WP 2.6d).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Migration;

use NF\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Port ACF data into NF from the command line.
 */
final class PortCommand {

	/**
	 * Dry-run report: what would be ported, and what would not.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table (default) or json.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function scan( array $args, array $assoc ): void {
		unset( $args );
		$source = new AcfSource();
		if ( ! $source->available() ) {
			\WP_CLI::error( 'ACF (or Secure Custom Fields) is not active.' );
		}
		$groups                   = $source->groups();
		$report                   = ( new Scanner() )->scan( $groups );
		$offtype                  = new OffTypeScanner();
		$report['offtype']        = $offtype->scan( $groups );
		$report['offtype_fields'] = $offtype->registered();
		if ( 'json' === ( $assoc['format'] ?? '' ) ) {
			\WP_CLI::log( (string) wp_json_encode( $report, JSON_PRETTY_PRINT ) );
			return;
		}
		foreach ( (array) ( $report['collections'] ?? array() ) as $c ) {
			\WP_CLI::log( sprintf( '%s: %s', (string) ( $c['title'] ?? $c['key'] ?? '?' ), (string) ( $c['status'] ?? '?' ) ) );
		}
		foreach ( $report['offtype'] as $o ) {
			\WP_CLI::warning( sprintf( 'Field %s (%s) holds arrays in %s%d value(s); NF keeps them in nf_json and they are not queryable.', $o['name'], $o['type'], $o['capped'] ? 'at least ' : '', $o['arrays'] ) );
		}
	}

	/**
	 * Copy ACF values of one post type into NF. Safe to repeat: this is also the re-port.
	 *
	 * ## OPTIONS
	 *
	 * --post-type=<post_type>
	 * : Post type to port.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function run( array $args, array $assoc ): void {
		unset( $args );
		$state = $this->runJob( 'port_posts', $assoc );
		\WP_CLI::success( sprintf( 'Ported %d value(s) from %d post(s).', absint( $state['values'] ?? 0 ), absint( $state['posts'] ?? 0 ) ) );
	}

	/**
	 * Compare ACF and NF values of one post type and list the mismatches.
	 *
	 * ## OPTIONS
	 *
	 * --post-type=<post_type>
	 * : Post type to verify.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function verify( array $args, array $assoc ): void {
		unset( $args );
		$state = $this->runJob( 'port_verify', $assoc );
		foreach ( (array) ( $state['list'] ?? array() ) as $m ) {
			\WP_CLI::log( sprintf( 'Mismatch: object %s, field %s (%s)', (string) $m['object'], (string) $m['field'], implode( ', ', (array) $m['paths'] ) ) );
		}
		if ( absint( $state['mismatches'] ?? 0 ) > 0 ) {
			\WP_CLI::error( sprintf( '%d mismatch(es) in %d post(s). Run "wp nf port run" for them again, then verify.', absint( $state['mismatches'] ), absint( $state['posts'] ?? 0 ) ) );
		}
		\WP_CLI::success( sprintf( 'Zero mismatches in %d post(s).', absint( $state['posts'] ?? 0 ) ) );
	}

	/**
	 * Start a job for a post type, run it to the end and return its state.
	 *
	 * @param string               $key   Job type.
	 * @param array<string,string> $assoc Options.
	 * @return array<string,mixed>
	 */
	private function runJob( string $key, array $assoc ): array {
		$type = sanitize_key( $assoc['post-type'] ?? '' );
		if ( '' === $type ) {
			\WP_CLI::error( 'Pass --post-type=<post_type>.' );
		}
		$runner = Plugin::jobs();
		$id     = $runner->start( $key, array( 'post_type' => $type ) );
		if ( '' === $id ) {
			\WP_CLI::error( 'Could not start the job (is the NF schema behind? run wp nf upgrade).' );
		}
		$status = 'queued';
		while ( 'queued' === $status ) {
			$status = $runner->run( $id );
		}
		$record = $runner->record( $id );
		$state  = (array) ( $record['state'] ?? array() );
		if ( 'done' !== $status || '' !== (string) ( $state['error'] ?? '' ) ) {
			// A failed job keeps its exception on the record; the state's own error is often ''.
			$error = (string) ( $state['error'] ?? '' );
			\WP_CLI::error( sprintf( 'Job %s ended as %s: %s', $id, $status, '' !== $error ? $error : (string) ( $record['error'] ?? '' ) ) );
		}
		return $state;
	}
}
