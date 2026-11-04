<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite and NF.
 *
 * @package NF
 */

$nf_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $nf_tests_dir ) {
	$nf_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $nf_tests_dir . '/includes/functions.php' ) ) {
	echo "WordPress test suite not found in {$nf_tests_dir}. Run bin/install-wp-tests.sh first.\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	exit( 1 );
}

if ( ! getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) && ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 3 ) . '/vendor/yoast/phpunit-polyfills' );
}

require_once $nf_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 3 ) . '/normalized-fields.php';
	}
);

require $nf_tests_dir . '/includes/bootstrap.php';
