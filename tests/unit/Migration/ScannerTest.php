<?php
/**
 * Scanner tests (WP 2.6a).
 *
 * @package NF
 */

use NF\Migration\Scanner;

/**
 * Pure scan tests.
 */
class ScannerTest extends WP_UnitTestCase {

	/**
	 * Counts, nesting, Series and clones.
	 */
	public function test_counts_nesting_and_series() {
		$report = ( new Scanner() )->scan(
			array(
				array(
					'key'    => 'group_a',
					'title'  => 'Event',
					'fields' => array(
						array(
							'key'  => 'field_1',
							'type' => 'text',
							'name' => 't',
						),
						array(
							'key'        => 'field_2',
							'type'       => 'repeater',
							'name'       => 'sessions',
							'sub_fields' => array(
								array(
									'key'  => 'field_3',
									'type' => 'text',
									'name' => 's',
								),
								array(
									'key'  => 'field_4',
									'type' => 'clone',
									'name' => 'c',
								),
							),
						),
					),
				),
			)
		);
		$c      = $report['collections'][0];
		$this->assertSame( 'portable', $c['status'] );
		$this->assertSame( 4, $c['fields'] );
		$this->assertSame( 1, $c['series'] );
		$this->assertSame( 1, $c['clones'] );
		$this->assertSame( 2, $c['depth'] );
		$this->assertSame( 2, $c['types']['text'] );
		$this->assertSame( 1, $report['totals']['portable'] );
	}

	/**
	 * Flexible content skips the whole collection, even when nested.
	 */
	public function test_flexible_content_skips_collection() {
		$report = ( new Scanner() )->scan(
			array(
				array(
					'key'    => 'group_b',
					'title'  => 'Blocks',
					'fields' => array(
						array(
							'key'        => 'field_9',
							'type'       => 'group',
							'sub_fields' => array(
								array(
									'key'  => 'field_10',
									'type' => 'flexible_content',
									'name' => 'body',
								),
							),
						),
					),
				),
				array(
					'key'    => 'group_c',
					'title'  => 'Plain',
					'fields' => array( array( 'type' => 'text' ) ),
				),
			)
		);
		$this->assertSame( 'skipped', $report['collections'][0]['status'] );
		$this->assertSame( 'flexible_content', $report['collections'][0]['reason'] );
		$this->assertSame( array( 'body' ), $report['collections'][0]['flexible'] );
		$this->assertSame( 1, $report['totals']['skipped'] );
		$this->assertSame( 1, $report['totals']['portable'] );
	}

	/**
	 * Unknown third-party types are listed once and do not skip the collection.
	 */
	public function test_unknown_types_listed() {
		$report = ( new Scanner() )->scan(
			array(
				array(
					'key'    => 'group_d',
					'title'  => 'X',
					'fields' => array(
						array(
							'type' => 'fancy_map',
						),
						array(
							'type' => 'fancy_map',
						),
					),
				),
			)
		);
		$this->assertSame( array( 'fancy_map' ), $report['collections'][0]['unknown'] );
		$this->assertSame( 'portable', $report['collections'][0]['status'] );
	}

	/**
	 * No groups, no collections.
	 */
	public function test_empty() {
		$report = ( new Scanner() )->scan( array() );
		$this->assertSame( array(), $report['collections'] );
		$this->assertSame( 0, $report['totals']['fields'] );
	}

	/**
	 * A field with no type is counted as invalid, not listed as an unknown type.
	 */
	public function test_missing_type_is_invalid() {
		$report = ( new Scanner() )->scan(
			array(
				array(
					'key'    => 'group_e',
					'title'  => 'Y',
					'fields' => array( array( 'key' => 'field_x' ) ),
				),
			)
		);
		$this->assertSame( 1, $report['collections'][0]['types']['invalid'] );
		$this->assertSame( array(), $report['collections'][0]['unknown'] );
	}
}
