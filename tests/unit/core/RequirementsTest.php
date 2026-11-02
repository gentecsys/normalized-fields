<?php
/**
 * Skeleton sanity tests (run with a WP test bootstrap once WP 0.2 lands).
 *
 * @package NF
 */

class RequirementsTest extends WP_UnitTestCase {
	public function test_requirements_met() {
		$this->assertTrue( nf_requirements_met() );
	}

	public function test_loaded_action_fires() {
		$this->assertGreaterThanOrEqual( 1, did_action( 'nf_loaded' ) );
	}
}
