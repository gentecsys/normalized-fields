<?php
/**
 * Background job: delete expired and old-version count cache rows.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Core\Jobs;

use NF\Backend\BackendInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes rows of `nf_count_cache` that have expired or carry a version older than their pivot set's write_version.
 * No arguments. One step.
 */
final class CountCacheCleanupJob implements JobInterface {

	/**
	 * Constructor.
	 *
	 * @param BackendInterface $db Backend.
	 */
	public function __construct( private BackendInterface $db ) {}

	/**
	 * Job type.
	 *
	 * @return string
	 */
	public function key(): string {
		return 'count_cache_cleanup';
	}

	/**
	 * Initial state.
	 *
	 * @param array<string,mixed> $args Arguments (unused).
	 * @return array<string,mixed>
	 */
	public function init( array $args ): array {
		return array(
			'deleted' => 0,
			'done'    => false,
		);
	}

	/**
	 * Delete stale rows.
	 *
	 * @param array<string,mixed> $state State.
	 * @return array<string,mixed>
	 */
	public function step( array $state ): array {
		$t = $this->db->table( 'nf_count_cache' );
		$s = $this->db->table( 'nf_pivot_sets' );
		$n = $this->db->execute( "DELETE FROM {$t} WHERE expires <= ?", array( gmdate( 'Y-m-d H:i:s' ) ) );
		$n += $this->db->execute( "DELETE FROM {$t} WHERE version < COALESCE((SELECT write_version FROM {$s} WHERE {$s}.pivot_id = {$t}.pivot_id), 0)" );
		$state['deleted'] = absint( $state['deleted'] ) + $n;
		$state['done']    = true;
		return $state;
	}
}
