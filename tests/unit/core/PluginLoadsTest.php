<?php
/**
 * Plugin load and activation smoke test.
 *
 * @package NF
 */

/**
 * Proves NF loads and activates under the WP test bootstrap.
 */
class PluginLoadsTest extends WP_UnitTestCase {

	/**
	 * Constants and bootstrap function exist.
	 */
	public function test_plugin_loaded() {
		$this->assertTrue( defined( 'NF_VERSION' ) );
		$this->assertTrue( function_exists( 'nf_bootstrap' ) );
	}

	/**
	 * Activation runs and records the version.
	 */
	public function test_activation_stores_version() {
		delete_option( 'nf_version' );
		nf_activate();
		$this->assertSame( NF_VERSION, get_option( 'nf_version' ) );
	}
}
