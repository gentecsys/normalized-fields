<?php
/**
 * TypeMap tests (WP 2.1a): the design doc's type table.
 *
 * @package NF
 */

use NF\Acf\TypeMap;
use NF\Storage\DataTable;

/**
 * Pure mapping tests.
 */
class TypeMapTest extends WP_UnitTestCase {

	/**
	 * Each ACF type maps to the design doc's table.
	 */
	public function test_storage_table_per_type() {
		$expected = array(
			'text'             => array( DataTable::Str, false ),
			'email'            => array( DataTable::Str, false ),
			'time_picker'      => array( DataTable::Int, false ),
			'date_picker'      => array( DataTable::Date, false ),
			'date_time_picker' => array( DataTable::Date, false ),
			'textarea'         => array( DataTable::Text, false ),
			'number'           => array( DataTable::Dec, false ),
			'true_false'       => array( DataTable::Int, false ),
			'gallery'          => array( DataTable::Int, true ),
			'taxonomy'         => array( DataTable::Int, true ),
			'repeater'         => array( DataTable::Rows, false ),
			'oembed'           => array( DataTable::Json, false ),
		);
		foreach ( $expected as $type => $want ) {
			$this->assertSame( $want, TypeMap::storage( array( 'type' => $type ) ), $type );
		}
		$long_text = array(
			'type'      => 'text',
			'maxlength' => 500,
		);
		$multi_sel = array(
			'type'     => 'select',
			'multiple' => 1,
		);
		$this->assertSame( array( DataTable::Str, false ), TypeMap::storage( $long_text ) );
		$this->assertSame( array( DataTable::Json, false ), TypeMap::storage( $multi_sel ) );
	}

	/**
	 * Time pickers become seconds since midnight; date-time and date values keep their form.
	 */
	public function test_normalize_time_and_dates() {
		$this->assertSame( 0, TypeMap::normalize( '00:00:00', DataTable::Int, false ) );
		$this->assertSame( 9 * 3600 + 30 * 60, TypeMap::normalize( '09:30:00', DataTable::Int, false ) );
		$this->assertSame( 13 * 3600 + 5 * 60 + 7, TypeMap::normalize( '13:05:07', DataTable::Int, false ) );
		$this->assertSame( 14, TypeMap::normalize( 14, DataTable::Int, false ) );
		$this->assertSame( '2026-11-11 08:15:00', TypeMap::normalize( '2026-11-11 08:15:00', DataTable::Date, false ) );
		$this->assertSame( '2026-11-11 00:00:00', TypeMap::normalize( '20261111', DataTable::Date, false ) );
		$this->assertNull( TypeMap::normalize( '', DataTable::Int, false ) );
	}
}
