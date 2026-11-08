<?php
/**
 * Bridge mode tests (WP 1.2): ACF update hooks land in NF tables.
 *
 * The hooks are driven directly with ACF-shaped arguments, so no ACF install is needed.
 *
 * @package NF
 */

use NF\Acf\Bridge;
use NF\Acf\TypeMap;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * Bridge and type map.
 */
class BridgeTest extends WP_UnitTestCase {

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Bridge under test.
	 *
	 * @var Bridge
	 */
	private Bridge $bridge;

	/**
	 * Create tables and a clean field map.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t12%' ) );
		$fields       = new FieldMap( $db );
		$this->store  = new Store( $db, $fields );
		$this->bridge = new Bridge( $this->store, $fields );
	}

	/**
	 * Clean up rows written by this test (tables are real, not temporary).
	 */
	public function tear_down() {
		foreach ( array( 3, 7, 8 ) as $id ) {
			$this->store->deleteObject( ObjectType::Post, $id );
		}
		parent::tear_down();
	}

	/**
	 * Decode covers every ACF $post_id convention.
	 */
	public function test_decode(): void {
		$this->assertSame( array( ObjectType::Post, 12 ), Bridge::decode( '12' ) );
		$this->assertSame( array( ObjectType::User, 3 ), Bridge::decode( 'user_3' ) );
		$this->assertSame( array( ObjectType::Term, 5 ), Bridge::decode( 'term_5' ) );
		$this->assertSame( array( ObjectType::Option, 0 ), Bridge::decode( 'option' ) );
		$this->assertNull( Bridge::decode( 'nonsense' ) );
	}

	/**
	 * Type map picks tables and normalises dates and empties.
	 */
	public function test_type_map(): void {
		$this->assertSame( array( DataTable::Int, true ), TypeMap::storage( array( 'type' => 'relationship' ) ) );
		$this->assertSame( array( DataTable::Text, false ), TypeMap::storage( array( 'type' => 'text' ) ) );
		$short = array(
			'type'      => 'text',
			'maxlength' => 100,
		);
		$this->assertSame( array( DataTable::Str, false ), TypeMap::storage( $short ) );
		$this->assertSame( '2026-03-04 00:00:00', TypeMap::normalize( '20260304', DataTable::Date, false ) );
		$this->assertNull( TypeMap::normalize( '', DataTable::Str, false ) );
	}

	/**
	 * A scalar and a relationship save land in NF; an empty value removes the row.
	 */
	public function test_scalar_and_relationship_update(): void {
		$title = array(
			'key'       => 'field_t12_title',
			'name'      => 'title',
			'type'      => 'text',
			'maxlength' => 80,
		);
		$rel   = array(
			'key'  => 'field_t12_rel',
			'name' => 'rel',
			'type' => 'relationship',
		);
		$this->assertSame( 'Hello', $this->bridge->onUpdate( 'Hello', 7, $title ) );
		$this->bridge->onUpdate( array( '9', '4' ), 7, $rel );
		$this->assertSame( 'Hello', $this->store->get( ObjectType::Post, 7, 'field_t12_title' ) );
		$this->assertSame( array( 9, 4 ), $this->store->get( ObjectType::Post, 7, 'field_t12_rel' ) );
		$this->bridge->onUpdate( '', 7, $title );
		$this->assertNull( $this->store->get( ObjectType::Post, 7, 'field_t12_title' ) );
	}

	/**
	 * A Series save writes rows in order, ignores the sub-field writes ACF makes next, and onDelete clears it.
	 */
	public function test_series_update_and_delete(): void {
		$series = array(
			'key'        => 'field_t12_sessions',
			'name'       => 'sessions',
			'type'       => 'repeater',
			'sub_fields' => array(
				array(
					'key'       => 'field_t12_s_name',
					'name'      => 'name',
					'type'      => 'text',
					'maxlength' => 50,
				),
				array(
					'key'  => 'field_t12_s_len',
					'name' => 'length',
					'type' => 'number',
				),
			),
		);
		$raw    = array(
			'row-0' => array(
				'field_t12_s_name' => 'Intro',
				'field_t12_s_len'  => '30',
			),
			'row-1' => array(
				'field_t12_s_name' => 'Talk',
				'field_t12_s_len'  => '45.5',
			),
		);
		$this->assertSame( $raw, $this->bridge->beforeSeries( $raw, 8, $series ) );
		// ACF now saves each sub-field through acf/update_value; the bridge must not store it as a top-level field.
		$sub = $series['sub_fields'][0];
		$this->bridge->onUpdate( 'Intro', 8, array_merge( $sub, array( 'name' => 'sessions_0_name' ) ) );
		$this->bridge->afterSeries( 2 );
		$rows = $this->store->getRows( ObjectType::Post, 8, 'field_t12_sessions' );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'Talk', $rows[1]['name'] );
		$this->assertEquals( 45.5, $rows[1]['length'] );
		$this->bridge->onDelete( 8, 'sessions', $series );
		$this->assertSame( array(), $this->store->getRows( ObjectType::Post, 8, 'field_t12_sessions' ) );
	}

	/**
	 * Through apply_filters, the Series rows land in NF even though ACF's handler swaps them for a count,
	 * and a nested Series save does not write a top-level field.
	 */
	public function test_series_through_filters_and_nested(): void {
		$this->bridge->register();
		$handler = static fn( $value ) => is_array( $value ) ? count( $value ) : $value;
		add_filter( 'acf/update_value/type=repeater', $handler, 10 );
		$series  = array(
			'key'        => 'field_t12_outer',
			'name'       => 'outer',
			'type'       => 'repeater',
			'sub_fields' => array(
				array(
					'key'  => 'field_t12_o_name',
					'name' => 'name',
					'type' => 'text',
				),
			),
		);
		$inner   = array(
			'key'  => 'field_t12_inner',
			'name' => 'outer_0_inner',
			'type' => 'repeater',
		);
		$rows    = array( 'row-0' => array( 'field_t12_o_name' => 'A' ) );
		$this->assertSame( 1, apply_filters( 'acf/update_value/type=repeater', $rows, 8, $series ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->assertCount( 1, $this->store->getRows( ObjectType::Post, 8, 'field_t12_outer' ) );
		// An inner Series while the outer one is open is ignored.
		$this->bridge->beforeSeries( $rows, 8, $series );
		$this->bridge->beforeSeries( $rows, 8, $inner );
		$this->bridge->afterSeries( 1 );
		$this->bridge->afterSeries( 1 );
		$this->assertNull( ( new FieldMap( nf_db() ) )->byKey( 'field_t12_inner' ) );
		remove_filter( 'acf/update_value/type=repeater', $handler, 10 );
	}

	/**
	 * A sub-field saved outside a Series save (depth 0) writes nothing: no top-level value, no inner Series.
	 */
	public function test_sub_field_outside_series_writes_nothing(): void {
		$this->bridge->beforeSeries(
			array( 'row-0' => array( 'field_t12_o_name' => 'A' ) ),
			8,
			array(
				'key'        => 'field_t12_outer',
				'name'       => 'outer',
				'type'       => 'repeater',
				'sub_fields' => array(
					array(
						'key'  => 'field_t12_o_name',
						'name' => 'name',
						'type' => 'text',
					),
				),
			)
		);
		$this->bridge->afterSeries( 1 );
		$fields = new FieldMap( nf_db() );

		// Known sub-field (registered with its Series), saved alone.
		$known = array(
			'key'  => 'field_t12_o_name',
			'name' => 'outer_0_name',
			'type' => 'text',
		);
		$this->bridge->onUpdate( 'B', 8, $known );
		$this->assertNull( $this->store->get( ObjectType::Post, 8, 'field_t12_o_name' ) );
		$this->assertSame( 'A', $this->store->getRows( ObjectType::Post, 8, 'field_t12_outer' )[0]['name'] );

		// Unregistered sub-field, recognised by its field_ parent.
		$loose = array(
			'key'    => 'field_t12_o_loose',
			'name'   => 'outer_0_loose',
			'type'   => 'text',
			'parent' => 'field_t12_outer',
		);
		$this->bridge->onUpdate( 'C', 8, $loose );
		$this->assertNull( $fields->byKey( 'field_t12_o_loose' ) );

		// Inner Series saved on its own is not registered at the top level.
		$inner = array(
			'key'    => 'field_t12_inner2',
			'name'   => 'outer_0_inner2',
			'type'   => 'repeater',
			'parent' => 'field_t12_outer',
		);
		$this->bridge->beforeSeries( array(), 8, $inner );
		$this->bridge->afterSeries( 0 );
		$this->assertNull( $fields->byKey( 'field_t12_inner2' ) );
	}

	/**
	 * A storage failure never breaks the ACF save.
	 */
	public function test_failure_is_reported_not_thrown(): void {
		$seen  = 0;
		$count = static function () use ( &$seen ): void {
			++$seen;
		};
		add_action( 'nf/bridge/error', $count );
		$bad = array(
			'key'  => 'field_t12_bad',
			'name' => 'bad',
			'type' => 'number',
		);
		$this->assertSame( 'abc', $this->bridge->onUpdate( 'abc', 3, $bad ) );
		$this->assertSame( 1, $seen );
	}
}
