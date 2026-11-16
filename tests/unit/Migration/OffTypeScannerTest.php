<?php
/**
 * Off-type scan tests (WP 3.0b).
 *
 * @package NF
 */

use NF\Migration\OffTypeScanner;

/**
 * Counts arrays stored in scalar fields.
 */
class OffTypeScannerTest extends WP_UnitTestCase {

	/**
	 * Groups used by the tests.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function groups(): array {
		return array(
			array(
				'fields' => array(
					array(
						'type' => 'text',
						'name' => 'websites',
					),
					array(
						'type' => 'text',
						'name' => 'clean',
					),
					array(
						'type' => 'checkbox',
						'name' => 'tags',
					),
					array(
						'type' => 'repeater',
						'name' => 'rows',
					),
				),
			),
		);
	}

	/**
	 * Only scalar types are checked.
	 */
	public function test_scalar_fields_only() {
		$this->assertSame(
			array(
				'clean'    => 'text',
				'websites' => 'text',
			),
			( new OffTypeScanner() )->scalarFields( $this->groups() )
		);
	}

	/**
	 * Arrays are counted per field and capped.
	 */
	public function test_counts_arrays_and_caps() {
		$post = self::factory()->post->create();
		for ( $i = 0; $i < 3; $i++ ) {
			add_post_meta( $post, 'websites', array( 'https://example.org/' . $i ) );
		}
		add_post_meta( $post, 'websites', 'plain' );
		add_post_meta( $post, 'websites', 'a: note' );
		add_post_meta( $post, 'clean', 'plain' );
		add_post_meta( $post, 'tags', array( 'a' ) );

		$scanner = new OffTypeScanner();
		$found   = $scanner->scan( $this->groups() );
		$this->assertCount( 1, $found );
		$this->assertSame( 'websites', $found[0]['name'] );
		$this->assertSame( 3, $found[0]['arrays'] );
		$this->assertFalse( $found[0]['capped'] );

		$capped = $scanner->scan( $this->groups(), 2 );
		$this->assertSame( 2, $capped[0]['arrays'] );
		$this->assertTrue( $capped[0]['capped'] );
	}
}
