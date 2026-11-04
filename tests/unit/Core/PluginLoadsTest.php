<?php
/**
 * Plugin load and activation smoke test.
 *
 * @package NF
 */

/**
 * Proves NF loads, autoloads its classes and activates under the WP test bootstrap.
 */
class PluginLoadsTest extends WP_UnitTestCase {

	/**
	 * Constants exist and the autoloader resolves NF classes.
	 */
	public function test_plugin_loaded() {
		$this->assertTrue( defined( 'NF_VERSION' ) );
		$this->assertTrue( class_exists( 'NF\Plugin' ) );
		$this->assertTrue( class_exists( 'NF\Install\Activator' ) );
	}

	/**
	 * Activation runs and records the version.
	 */
	public function test_activation_stores_version() {
		delete_option( 'nf_version' );
		NF\Install\Activator::activate();
		$this->assertSame( NF_VERSION, get_option( 'nf_version' ) );
	}
}
