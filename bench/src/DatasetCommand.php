<?php
/**
 * WP-CLI: wp nf-bench ...
 *
 * @package NF\Bench
 */

declare(strict_types=1);

namespace NF\Bench;

/**
 * Generate, verify and fingerprint the benchmark dataset.
 */
final class DatasetCommand {

	/**
	 * Write the synthetic event catalogue to a store.
	 *
	 * ## OPTIONS
	 *
	 * [--posts=<n>]
	 * : Number of events (10000 or 100000 for the standard datasets).
	 * ---
	 * default: 10000
	 * ---
	 *
	 * [--seed=<n>]
	 * : Seed.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--start-id=<id>]
	 * : Post ID of the first event.
	 * ---
	 * default: 1000000
	 * ---
	 *
	 * [--store=<store>]
	 * : Target store. `nf` arrives with WP 0.3b.
	 * ---
	 * default: acf
	 * options:
	 *   - acf
	 * ---
	 *
	 * [--force]
	 * : Replace data already in the ID range.
	 *
	 * @param array                $args  Positional args.
	 * @param array                $assoc Named args.
	 */
	public function generate( array $args, array $assoc ): void {
		unset( $args );
		$dataset = self::dataset( $assoc );
		$store   = self::store( $assoc );
		$found   = $store->existing( $dataset );
		if ( 0 < $found ) {
			if ( empty( $assoc['force'] ) ) {
				\WP_CLI::error( "{$found} posts already exist in the ID range; pass --force to replace them." );
			}
			$store->clear( $dataset );
		}
		$start = microtime( true );
		$store->write( $dataset );
		\WP_CLI::success( sprintf( 'Wrote %d events in %.1fs.', $dataset->posts, microtime( true ) - $start ) );
	}

	/**
	 * Read the store back and compare it with the dataset.
	 *
	 * ## OPTIONS
	 *
	 * [--posts=<n>]
	 * [--seed=<n>]
	 * [--start-id=<id>]
	 * [--store=<store>]
	 *
	 * @param array                $args  Positional args.
	 * @param array                $assoc Named args.
	 */
	public function verify( array $args, array $assoc ): void {
		unset( $args );
		$dataset = self::dataset( $assoc );
		$bad     = self::store( $assoc )->verify( $dataset );
		if ( 0 < $bad ) {
			\WP_CLI::error( "{$bad} of {$dataset->posts} posts differ from the dataset." );
		}
		\WP_CLI::success( "All {$dataset->posts} posts match." );
	}

	/**
	 * Print the dataset fingerprint (sha256 of the logical records).
	 *
	 * ## OPTIONS
	 *
	 * [--posts=<n>]
	 * [--seed=<n>]
	 * [--start-id=<id>]
	 *
	 * @param array                $args  Positional args.
	 * @param array                $assoc Named args.
	 */
	public function fingerprint( array $args, array $assoc ): void {
		unset( $args );
		\WP_CLI::line( self::dataset( $assoc )->fingerprint() );
	}

	/**
	 * Build the dataset from CLI arguments.
	 *
	 * @param array                $assoc Named args.
	 */
	private static function dataset( array $assoc ): Dataset {
		try {
			return new Dataset(
				absint( $assoc['posts'] ?? 10000 ),
				absint( $assoc['seed'] ?? 1 ),
				absint( $assoc['start-id'] ?? 1000000 )
			);
		} catch ( \InvalidArgumentException $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}

	/**
	 * Resolve the target store.
	 *
	 * @param array                $assoc Named args.
	 */
	private static function store( array $assoc ): StoreWriter {
		global $wpdb;
		$name = (string) ( $assoc['store'] ?? 'acf' );
		if ( 'acf' !== $name ) {
			\WP_CLI::error( "Unknown store '{$name}'." );
		}
		return new AcfStore( $wpdb );
	}
}
