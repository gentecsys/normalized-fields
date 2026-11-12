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
			'oembed'           => array( DataTable::Text, false ),
			'checkbox'         => array( DataTable::Str, true ),
			'page_link'        => array( DataTable::Int, false ),
			'select'           => array( DataTable::Str, false ),
			'link'             => array( DataTable::None, false ),
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
		$page_link = array(
			'type'     => 'page_link',
			'multiple' => 1,
		);
		$this->assertSame( array( DataTable::Str, true ), TypeMap::storage( $multi_sel ) );
		$this->assertSame( array( DataTable::Int, true ), TypeMap::storage( $page_link ) );
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

	/**
	 * Multi-valued strings keep order; objects and arrays collapse to IDs.
	 */
	public function test_normalize_multi_and_objects() {
		$this->assertSame( array( 'b', 'a' ), TypeMap::normalize( array( 'b', 'a' ), DataTable::Str, true ) );
		$this->assertSame( array( 5, 7 ), TypeMap::normalize( array( (object) array( 'ID' => 5 ), array( 'ID' => '7' ) ), DataTable::Int, true ) );
		$this->assertSame( 9, TypeMap::normalize( (object) array( 'term_id' => 9 ), DataTable::Int, false ) );
		$this->assertNull( TypeMap::normalize( array(), DataTable::Str, true ) );
	}

	/**
	 * Link and Google Map decompose into fixed pseudo sub-fields; a group uses its own sub-fields.
	 */
	public function test_composite_parts() {
		$link = TypeMap::composite( $this->field( 'field_l', 'link' ) );
		$this->assertSame( array( 'url', 'title', 'target' ), array_column( $link, 'name' ) );
		$this->assertSame( 'field_l__url', $link[0]['key'] );
		$map = TypeMap::composite( $this->field( 'field_m', 'google_map' ) );
		$this->assertSame( array( 'lat', 'lng', 'address', 'zoom' ), array_column( $map, 'name' ) );
		$this->assertSame( DataTable::Dec, $map[0]['table'] );
		$this->assertSame( DataTable::Int, $map[3]['table'] );
		$group               = $this->field( 'field_g', 'group' );
		$group['sub_fields'] = array( $this->field( 'field_g1', 'number', 'a' ) );
		$parts               = TypeMap::composite( $group );
		$this->assertSame( DataTable::Dec, $parts[0]['table'] );
		$this->assertNull( TypeMap::composite( $this->field( 'x', 'text' ) ) );
		$this->assertSame( DataTable::None, TypeMap::storage( $group )[0] );
	}

	/**
	 * A seamless clone adds its cloned fields at its own level (prefixed on request); a group clone is a group.
	 */
	public function test_clone_resolution() {
		$clone                = $this->field( 'field_c', 'clone', 'hero' );
		$clone['sub_fields']  = array( $this->field( 'field_c1', 'text', 'title' ), $this->field( 'field_c2', 'text', 'hero_sub' ) );
		$clone['display']     = 'seamless';
		$clone['prefix_name'] = 0;
		$this->assertSame( DataTable::None, TypeMap::storage( $clone )[0] );
		$this->assertFalse( TypeMap::isGroupClone( $clone ) );
		$this->assertNull( TypeMap::composite( $clone ) );
		$this->assertSame( array( 'title', 'hero_sub' ), array_column( TypeMap::seamlessFields( $clone ), 'name' ) );
		$clone['prefix_name'] = 1;
		$this->assertSame( array( 'hero_title', 'hero_sub' ), array_column( TypeMap::seamlessFields( $clone ), 'name' ) );
		$this->assertSame( 'field_c1', TypeMap::seamlessFields( $clone )[0]['key'] );
		$clone['display'] = 'group';
		$this->assertTrue( TypeMap::isGroupClone( $clone ) );
		$this->assertSame( array(), TypeMap::seamlessFields( $clone ) );
		$this->assertSame( array( 'title', 'hero_sub' ), array_column( TypeMap::composite( $clone ), 'name' ) );
	}

	/**
	 * Composite values keep known keys only and empty ones become null.
	 */
	public function test_normalize_composite() {
		$field = $this->field( 'field_l', 'link' );
		$value = array(
			'url'    => 'https://a.test',
			'title'  => 'A',
			'target' => '',
			'junk'   => 1,
		);
		$want  = array(
			'url'    => 'https://a.test',
			'title'  => 'A',
			'target' => null,
		);
		$this->assertSame( $want, TypeMap::normalizeComposite( $field, $value ) );
		$this->assertNull( TypeMap::normalizeComposite( $field, array( 'url' => '' ) ) );
		$this->assertNull( TypeMap::normalizeComposite( $field, '' ) );
	}

	/**
	 * Minimal ACF field array.
	 *
	 * @param string $key  Key.
	 * @param string $type Type.
	 * @param string $name Name.
	 * @return array<string,mixed>
	 */
	private function field( string $key, string $type, string $name = '' ): array {
		return array(
			'key'  => $key,
			'type' => $type,
			'name' => $name,
		);
	}
}
