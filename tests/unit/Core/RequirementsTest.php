<?php
/**
 * Requirements and boot tests.
 *
 * @package NF
 */

/**
 * Checks requirements and that boot fires nf_loaded.
 */
class RequirementsTest extends WP_UnitTestCase {

	/**
	 * The environment satisfies NF's minimums.
	 */
	public function test_requirements_met() {
		$this->assertTrue( NF\Core\Requirements::met() );
	}

	/**
	 * The bootstrap fires nf_loaded.
	 */
	public function test_loaded_action_fires() {
		$this->assertGreaterThanOrEqual( 1, did_action( 'nf_loaded' ) );
	}
}
