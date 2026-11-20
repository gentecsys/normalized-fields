<?php
/**
 * WP-CLI: wp nf pivot.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Pivot;

use NF\Install\Upgrader;
use NF\Migration\AcfSource;
use NF\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Pivot set and slot maintenance from the command line (a trusted context).
 */
final class PivotCommand {

	/**
	 * Create pivot sets for post types with NF fields and assign slots to eligible fields.
	 *
	 * Existing slots never move. A set that got new slots is rebuilt from the typed tables.
	 *
	 * ## OPTIONS
	 *
	 * [--rebuild]
	 * : Also rebuild sets that got no new slots (use after an interrupted sync; new slots are queried before their rebuild finishes).
	 *
	 * [--dry-run]
	 * : Print the sets and slots that would be assigned; write nothing.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function sync( array $args, array $assoc ): void {
		unset( $args );
		$dry = isset( $assoc['dry-run'] );
		if ( ! Upgrader::isCurrent() ) {
			\WP_CLI::error( 'The NF schema is behind; run wp nf upgrade first.' );
		}
		$source = new AcfSource();
		if ( ! $source->available() ) {
			\WP_CLI::error( 'ACF (or Secure Custom Fields) is not active.' );
		}
		$by_type = SlotAssigner::fieldsByPostType( $source->groups() );
		$assign  = new SlotAssigner( new Pivot( Plugin::db(), Plugin::fields(), Plugin::store() ), Plugin::fields() );
		$changed = 0;
		foreach ( $by_type as $post_type => $fields ) {
			try {
				$res = $assign->assign( (string) $post_type, $fields, $dry );
			} catch ( \InvalidArgumentException $e ) {
				\WP_CLI::warning( sprintf( '%s: %s', $post_type, $e->getMessage() ) );
				continue;
			}
			foreach ( $res['assigned'] as $key => $slot ) {
				\WP_CLI::log( sprintf( '%s: %s => %s%s', $post_type, $key, $slot, $dry ? ' (dry run)' : '' ) );
			}
			foreach ( $res['skipped'] as $key ) {
				\WP_CLI::warning( sprintf( '%s: no free slot for %s; it stays on the typed-table probe path.', $post_type, $key ) );
			}
			if ( ! $dry && ( array() !== $res['assigned'] || isset( $assoc['rebuild'] ) ) && null !== $res['pivot_id'] ) {
				++$changed;
				$this->rebuild( $res['pivot_id'] );
			}
		}
		\WP_CLI::success( $dry ? 'Dry run finished.' : sprintf( 'Synced %d pivot set(s).', $changed ) );
	}

	/**
	 * Start the rebuild job for a set and run it to the end.
	 *
	 * @param int $pivot_id Pivot set id.
	 * @return void
	 */
	private function rebuild( int $pivot_id ): void {
		$runner = Plugin::jobs();
		$id     = $runner->start( 'pivot_rebuild', array( 'pivot_id' => $pivot_id ) );
		if ( '' === $id ) {
			\WP_CLI::warning( "Could not start the rebuild for set {$pivot_id}." );
			return;
		}
		$status = 'queued';
		for ( $i = 0; 'queued' === $status && $i < 10000; $i++ ) {
			$status = $runner->run( $id );
		}
		if ( 'done' !== $status ) {
			\WP_CLI::warning( "Rebuild of set {$pivot_id} ended as {$status}." );
		}
	}
}
