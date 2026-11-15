<?php
/**
 * Series row sorting tests (WP 2.7): rule, comparison, hooks and the three modes in Store.
 *
 * @package NF
 */

use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\RowSortRule;
use NF\Storage\RowSorter;
use NF\Storage\Store;

/**
 * Sorting by sub-field, linked post date and callback; manual, on_save and on_read.
 */
class RowSorterTest extends WP_UnitTestCase {

	/**
	 * Sorter under test.
	 *
	 * @var RowSorter
	 */
	private RowSorter $sorter;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->sorter = new RowSorter(
			static fn( array $ids ): array => array_intersect_key(
				array(
					1 => '2026-03-01 00:00:00',
					2 => '2026-01-01 00:00:00',
					3 => '2026-02-01 00:00:00',
				),
				array_flip( $ids )
			)
		);
	}

	/**
	 * Names of the rows in order.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @return string[]
	 */
	private function names( array $rows ): array {
		return array_map( static fn( array $r ): string => (string) ( $r['name'] ?? '' ), $rows );
	}

	/**
	 * Setting array is validated; no key means no rule.
	 */
	public function test_rule_from_array() {
		$this->assertNull( RowSortRule::fromArray( array( 'mode' => 'on_save' ) ) );
		$rule = RowSortRule::fromArray(
			array(
				'mode'  => 'bogus',
				'by'    => 'sub_field:name',
				'order' => 'desc',
			)
		);
		$this->assertSame( 'manual', $rule->mode );
		$this->assertSame( 'desc', $rule->order );
		$this->assertSame( 'locale', $rule->compare );
	}

	/**
	 * Alphabetical, natural, descending, empty values and stable ties.
	 */
	public function test_sub_field_sort() {
		$rows = array(
			array( 'name' => 'b', 'n' => 1 ),
			array( 'name' => '', 'n' => 2 ),
			array( 'name' => 'a', 'n' => 3 ),
			array( 'name' => 'b', 'n' => 4 ),
			array( 'name' => 'item10', 'n' => 5 ),
			array( 'name' => 'item9', 'n' => 6 ),
		);
		$asc  = $this->sorter->sort( $rows, new RowSortRule( 'manual', 'sub_field:name', 'asc', 'natural', 'last' ) );
		$this->assertSame( array( 3, 1, 4, 6, 5, 2 ), array_column( $asc, 'n' ) );

		$first = $this->sorter->sort( $rows, new RowSortRule( 'manual', 'sub_field:name', 'asc', 'natural', 'first' ) );
		$this->assertSame( 2, $first[0]['n'] );

		$desc = $this->sorter->sort( $rows, new RowSortRule( 'manual', 'sub_field:name', 'desc', 'binary', 'last' ) );
		$this->assertSame( array( 'item9', 'item10', 'b', 'b', 'a', '' ), $this->names( $desc ) );
		$this->assertSame( array( 6, 5, 1, 4, 3, 2 ), array_column( $desc, 'n' ), 'Descending keeps tie order.' );
	}

	/**
	 * Dates sort as dates, numbers as numbers.
	 */
	public function test_date_and_numeric() {
		$rows = array(
			array( 'name' => 'x', 'd' => '2026-03-01 10:00:00', 'v' => '10' ),
			array( 'name' => 'y', 'd' => '2025-12-31 23:59:59', 'v' => '9' ),
		);
		$by_d = $this->sorter->sort( $rows, new RowSortRule( 'manual', 'sub_field:d' ) );
		$this->assertSame( array( 'y', 'x' ), $this->names( $by_d ) );
		$by_v = $this->sorter->sort( $rows, new RowSortRule( 'manual', 'sub_field:v' ) );
		$this->assertSame( array( 'y', 'x' ), $this->names( $by_v ) );
	}

	/**
	 * Sort by the post_date of a linked post, with empty links last.
	 */
	public function test_post_date_sort() {
		$rows = array(
			array( 'name' => 'one', 'speaker' => 1 ),
			array( 'name' => 'none', 'speaker' => null ),
			array( 'name' => 'two', 'speaker' => array( 2 ) ),
			array( 'name' => 'three', 'speaker' => 3 ),
		);
		$out  = $this->sorter->sort( $rows, new RowSortRule( 'manual', 'post_date:speaker' ) );
		$this->assertSame( array( 'two', 'three', 'one', 'none' ), $this->names( $out ) );
		$out = $this->sorter->sort( $rows, new RowSortRule( 'manual', 'post_date:speaker', 'desc' ) );
		$this->assertSame( array( 'one', 'three', 'two', 'none' ), $this->names( $out ) );
	}

	/**
	 * Registered sorters and the filter.
	 */
	public function test_callback_and_filter() {
		$this->assertFalse( $this->sorter->register( 'bad', array( 'compare' => 'nope_not_callable' ) ) );
		$this->assertTrue(
			$this->sorter->register(
				'price_then_name',
				array(
					'label'   => 'Price, then name',
					'compare' => static fn( $a, $b ) => array( $a['price'], $a['name'] ) <=> array( $b['price'], $b['name'] ),
				)
			)
		);
		$this->assertSame( array( 'price_then_name' => 'Price, then name' ), $this->sorter->sorters() );
		$rows = array(
			array( 'name' => 'b', 'price' => 2 ),
			array( 'name' => 'z', 'price' => 1 ),
			array( 'name' => 'a', 'price' => 2 ),
		);
		$out  = $this->sorter->sort( $rows, new RowSortRule( 'manual', 'callback:price_then_name' ) );
		$this->assertSame( array( 'z', 'a', 'b' ), $this->names( $out ) );
		$this->assertSame( $rows, $this->sorter->sort( $rows, new RowSortRule( 'manual', 'callback:missing' ) ) );

		add_filter( 'nf/repeater/sort_rows', static fn( array $r ) => array_reverse( $r ) );
		$out = $this->sorter->sort( $rows, new RowSortRule( 'manual', 'callback:price_then_name' ), 'field_x', 7 );
		remove_all_filters( 'nf/repeater/sort_rows' );
		$this->assertSame( array( 'b', 'a', 'z' ), $this->names( $out ) );
	}

	/**
	 * Build a store with a Series and a sorter.
	 *
	 * @param RowSortRule|null $rule Rule for the Series.
	 * @return Store
	 */
	private function store( ?RowSortRule $rule ): Store {
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t27%' ) );
		$fields = new FieldMap( $db );
		$fields->flush();
		$s = $fields->register( 'field_t27_series', 'series', DataTable::Rows );
		$fields->register( 'field_t27_name', 'name', DataTable::Str, false, $s->id );
		$sorter = new RowSorter();
		$sorter->setRule( 'field_t27_series', $rule );
		return new Store( $db, $fields, $sorter );
	}

	/**
	 * Rows as stored names.
	 *
	 * @param Store $store Store.
	 * @return string[]
	 */
	private function stored( Store $store ): array {
		return $this->names( $store->getRows( ObjectType::Post, 271, 'field_t27_series' ) );
	}

	/**
	 * Fixture rows.
	 *
	 * @return array<int,array<string,string>>
	 */
	private function fixture(): array {
		return array(
			array( 'name' => 'Charlie' ),
			array( 'name' => 'alpha' ),
			array( 'name' => 'Bravo' ),
		);
	}

	/**
	 * Manual: save keeps the order, sortRows rewrites row_order once and fires the action.
	 */
	public function test_manual_mode() {
		$store = $this->store( new RowSortRule( 'manual', 'sub_field:name', 'asc', 'natural' ) );
		$store->setRows( ObjectType::Post, 271, 'field_t27_series', $this->fixture() );
		$this->assertSame( array( 'Charlie', 'alpha', 'Bravo' ), $this->stored( $store ) );

		$fired = 0;
		add_action(
			'nf/repeater/sorted',
			static function () use ( &$fired ) {
				++$fired;
			}
		);
		$this->assertTrue( $store->sortRows( ObjectType::Post, 271, 'field_t27_series' ) );
		$this->assertSame( array( 'alpha', 'Bravo', 'Charlie' ), $this->stored( $store ) );
		$this->assertSame( 1, $fired );

		$this->assertTrue( $store->sortRows( ObjectType::Post, 271, 'field_t27_series' ), 'Already sorted is a no-op.' );
		$this->assertSame( 1, $fired );
		remove_all_actions( 'nf/repeater/sorted' );

		$desc = new RowSortRule( 'manual', 'sub_field:name', 'desc', 'natural' );
		$store->sortRows( ObjectType::Post, 271, 'field_t27_series', $desc );
		$this->assertSame( array( 'Charlie', 'Bravo', 'alpha' ), $this->stored( $store ) );
		$store->deleteObject( ObjectType::Post, 271 );
	}

	/**
	 * On save: setRows stores the sorted order.
	 */
	public function test_on_save_mode() {
		$store = $this->store( new RowSortRule( 'on_save', 'sub_field:name', 'asc', 'natural' ) );
		$store->setRows( ObjectType::Post, 271, 'field_t27_series', $this->fixture() );
		$this->assertSame( array( 'alpha', 'Bravo', 'Charlie' ), $this->stored( $store ) );
		$store->deleteObject( ObjectType::Post, 271 );
	}

	/**
	 * On read: the stored order is untouched, reads are sorted.
	 */
	public function test_on_read_mode() {
		$store = $this->store( new RowSortRule( 'on_read', 'sub_field:name', 'asc', 'natural' ) );
		$store->setRows( ObjectType::Post, 271, 'field_t27_series', $this->fixture() );
		$this->assertSame( array( 'alpha', 'Bravo', 'Charlie' ), $this->stored( $store ) );

		$plain = new Store( nf_db(), new FieldMap( nf_db() ) );
		$this->assertSame( array( 'Charlie', 'alpha', 'Bravo' ), $this->names( $plain->getRows( ObjectType::Post, 271, 'field_t27_series' ) ) );
		$store->deleteObject( ObjectType::Post, 271 );
	}
}
