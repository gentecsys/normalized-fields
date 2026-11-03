<?php
/**
 * Skeleton sanity tests.
 *
 * @package NF
 */

/**
 * Checks the plugin loads under the WP test bootstrap.
 */
class RequirementsTest extends WP_UnitTestCase {

	/**
	 * The environment satisfies NF's minimums.
	 */
	public function test_requirements_met() {
		$this->assertTrue( nf_requirements_met() );
	}

	/**
	 * The bootstrap fires nf_loaded.
	 */
	public function test_loaded_action_fires() {
		$this->assertGreaterThanOrEqual( 1, did_action( 'nf_loaded' ) );
	}
}
