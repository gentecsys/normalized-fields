<?php
/**
 * Bootstrap for the real-ACF differential baseline: WordPress test suite + SCF (or ACF) + bench fixtures.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

$nf_root      = dirname( __DIR__, 3 );
$nf_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
$nf_acf_main  = getenv( 'NF_ACF_PLUGIN' ) ? getenv( 'NF_ACF_PLUGIN' ) : rtrim( sys_get_temp_dir(), '/\\' ) . '/nf-acf/secure-custom-fields/secure-custom-fields.php';

if ( ! file_exists( $nf_tests_dir . '/includes/functions.php' ) || ! file_exists( $nf_acf_main ) ) {
	echo "Need the WordPress test suite ({$nf_tests_dir}) and SCF/ACF ({$nf_acf_main}). Run bin/install-wp-tests.sh and bin/install-scf.sh.\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	exit( 1 );
}

if ( ! getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $nf_root . '/vendor/yoast/phpunit-polyfills' );
}

require_once $nf_tests_dir . '/includes/functions.php';
require_once $nf_root . '/tests/differential/bootstrap.php';
require_once $nf_root . '/tests/differential/src/functions.php';
foreach ( array( 'Dataset', 'AcfMeta', 'AcfSchema', 'StoreWriter', 'AcfStore' ) as $nf_bench_class ) {
	require_once $nf_root . '/bench/src/' . $nf_bench_class . '.php';
}

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $nf_acf_main, $nf_root ): void {
		require $nf_acf_main;
		// NF in bridge mode (WP 1.4): ACF writes are mirrored into the NF tables, which the NF-vs-ACF check reads back.
		require $nf_root . '/normalized-fields.php';
		add_filter(
			'acf/settings/load_json',
			static function ( array $paths ) use ( $nf_root ): array {
				$paths[] = $nf_root . '/bench/acf-json';
				return $paths;
			}
		);
	}
);

tests_add_filter(
	'init',
	static function (): void {
		NF\Install\Schema::install();
		// The bridge writes only when the schema version is current (normally set by the upgrade).
		update_option( NF\Install\Upgrader::OPTION, NF\Install\Upgrader::SCHEMA_VERSION );
	},
	1
);

tests_add_filter(
	'init',
	static function (): void {
		register_post_type( NF\Bench\Dataset::POST_TYPE, array( 'public' => true ) );
	},
	0
);

require $nf_tests_dir . '/includes/bootstrap.php';
