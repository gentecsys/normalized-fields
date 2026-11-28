<?php
/**
 * N+1 detector for single-object field loads (WP 3.5e).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Loading;

defined( 'ABSPATH' ) || exit;

/**
 * Counts field loads that hit one object only. When the same field is read for more than THRESHOLD
 * different objects one at a time, it fires `nf/debug/n_plus_one` once for that field. Active only in
 * debug mode (WP_DEBUG, Query Monitor, or the `nf/debug/enabled` filter).
 */
final class Detector {

	/**
	 * Lone loads that are tolerated per field.
	 */
	public const THRESHOLD = 5;

	/**
	 * Distinct object IDs loaded alone, by field key.
	 *
	 * @var array<string,array<int,true>>
	 */
	private array $lone = array();

	/**
	 * Fields already reported this request.
	 *
	 * @var array<string,true>
	 */
	private array $reported = array();

	/**
	 * Whether detection is on.
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		$on = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || class_exists( 'QM_Collectors', false );
		return (bool) apply_filters( 'nf/debug/enabled', $on ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	/**
	 * Note a load that read one object.
	 *
	 * @param string $field   Field key.
	 * @param int    $post_id Object ID.
	 * @return void
	 */
	public function note( string $field, int $post_id ): void {
		if ( isset( $this->reported[ $field ] ) || ! $this->enabled() ) {
			return;
		}
		$this->lone[ $field ][ $post_id ] = true;

		$count = count( $this->lone[ $field ] );
		if ( $count <= self::THRESHOLD ) {
			return;
		}
		$this->reported[ $field ] = true;
		/**
		 * A field was read one object at a time more than five times in a request.
		 *
		 * @param string $field  Field key.
		 * @param int    $count  Objects loaded alone.
		 * @param array  $caller File and line of the calling code outside NF ('file', 'line'), when found.
		 */
		do_action( 'nf/debug/n_plus_one', $field, $count, $this->caller() ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	/**
	 * Forget counts (new request scope, flush).
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->lone     = array();
		$this->reported = array();
	}

	/**
	 * First stack frame outside NF's own code.
	 *
	 * @return array{file:string,line:int}
	 */
	private function caller(): array {
		$root = dirname( __DIR__, 2 );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) {
			$file = $frame['file'] ?? '';
			if ( '' !== $file && ! str_starts_with( $file, $root . '/src/' ) && ! str_contains( $file, '/wp-includes/' ) ) {
				return array(
					'file' => $file,
					'line' => (int) ( $frame['line'] ?? 0 ),
				);
			}
		}
		return array(
			'file' => '',
			'line' => 0,
		);
	}
}
