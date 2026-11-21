<?php
/**
 * Query parity after a rebuild, and anonymous reads write nothing (WP 3.0i).
 *
 * @package NF
 */

use NF\Pivot\Pivot;
use NF\Query\Builder;
use NF\Query\QueryContext;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * Queries answer the same from a rebuilt pivot as from write-through; logged-out reads leave NF tables untouched.
 */
class RebuildParityTest extends WP_UnitTestCase {

	private const TYPE = 'nf_par_event';

	/**
	 * NF tables checked for writes.
	 *
	 * @var string[]
	 */
	private const TABLES = array( 'nf_fields', 'nf_rows', 'nf_int', 'nf_str', 'nf_json', 'nf_pivot_sets', 'nf_pivot_slots', 'nf_pivot', 'nf_row_pivot', 'nf_dict', 'nf_count', 'nf_count_cache', 'nf_archive' );

	/**
	 * Store.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Pivot.
	 *
	 * @var Pivot
	 */
	private Pivot $pivot;

	/**
	 * Pivot set id.
	 *
	 * @var int
	 */
	private int $pivot_id;

	/**
	 * Context.
	 *
	 * @var QueryContext
	 */
	private QueryContext $context;

	/**
	 * Post IDs created.
	 *
	 * @var int[]
	 */
	private array $ids = array();

	/**
	 * Fields `price` (slotted int) and `city` (slotted string).
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		register_post_type( self::TYPE );
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t30i%' ) );
		$fields = new FieldMap( $db );
		$fields->flush();
		$fields->register( 'field_t30i_price', 'parprice', DataTable::Int );
		$fields->register( 'field_t30i_city', 'parcity', DataTable::Str );
		$this->store    = new Store( $db, $fields );
		$this->pivot    = new Pivot( $db, $fields, $this->store );
		$this->pivot_id = $this->pivot->setId( ObjectType::Post, self::TYPE );
		$this->clean();
		$this->pivot->assignSlot( $this->pivot_id, 'field_t30i_price', 'i1' );
		$this->pivot->assignSlot( $this->pivot_id, 'field_t30i_city', 's1' );
		$this->context = new QueryContext( $db, $this->pivot, $this->pivot->dictionary() );
	}

	/**
	 * Remove test data.
	 */
	public function tear_down() {
		foreach ( $this->ids as $id ) {
			$this->store->deleteObject( ObjectType::Post, $id );
			wp_delete_post( $id, true );
		}
		$this->clean();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Delete pivot data of the test set.
	 */
	private function clean(): void {
		$db = nf_db();
		foreach ( array( 'nf_pivot', 'nf_row_pivot', 'nf_pivot_slots' ) as $t ) {
			$db->execute( 'DELETE FROM ' . $db->table( $t ) . ' WHERE pivot_id = ?', array( $this->pivot_id ) );
		}
	}

	/**
	 * A builder bound to the test context.
	 */
	private function q(): Builder {
		return new Builder( self::TYPE, $this->context );
	}

	/**
	 * Create a published post with values and a pivot row.
	 *
	 * @param int    $price Price.
	 * @param string $city  City.
	 * @return int
	 */
	private function make( int $price, string $city ): int {
		$id          = self::factory()->post->create( array( 'post_type' => self::TYPE ) );
		$this->ids[] = $id;
		$this->store->set( ObjectType::Post, $id, 'field_t30i_price', $price );
		$this->store->set( ObjectType::Post, $id, 'field_t30i_city', $city );
		$this->pivot->sync( $this->pivot_id, $id, array( 'status' => 1 ) );
		return $id;
	}

	/**
	 * Content checksums of the NF tables (catches in-place updates).
	 *
	 * @return array<string,string>
	 */
	private function counts(): array {
		$db  = nf_db();
		$out = array();
		foreach ( self::TABLES as $t ) {
			$out[ $t ] = md5( (string) wp_json_encode( $db->select( 'SELECT * FROM ' . $db->table( $t ) ) ) );
		}
		return $out;
	}

	/**
	 * After the pivot is wiped and rebuilt from core plus the typed tables, every query answers as before.
	 */
	public function test_queries_are_identical_after_rebuild(): void {
		$this->make( 30, 'Oslo' );
		$this->make( 10, 'Rome' );
		$this->make( 20, 'Oslo' );
		$this->make( 40, 'Bern' );
		$run    = fn(): array => array(
			$this->q()->orderBy( 'parprice' )->ids(),
			$this->q()->where( 'parcity', 'Oslo' )->orderBy( 'parprice', 'DESC' )->ids(),
			$this->q()->whereBetween( 'parprice', array( 15, 35 ) )->orderBy( 'parprice' )->ids(),
			$this->q()->where( 'parprice', '>', 10 )->count(),
		);
		$before = $run();
		$this->assertCount( 4, $before[0] );
		$this->clean_pivot_rows();
		$this->assertSame( array(), $this->q()->ids(), 'wiped pivot has no rows' );
		$this->pivot->rebuildFromCore( $this->pivot_id );
		$this->assertSame( $before, $run() );
	}

	/**
	 * Delete only the pivot rows, keeping the set and its slots.
	 */
	private function clean_pivot_rows(): void {
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot' ) . ' WHERE pivot_id = ?', array( $this->pivot_id ) );
	}

	/**
	 * A logged-out read (ids, count, SQL text, explain) writes to no NF table.
	 */
	public function test_anonymous_read_writes_nothing(): void {
		$this->make( 5, 'Oslo' );
		$this->make( 6, 'Rome' );
		wp_set_current_user( 0 );
		$before = $this->counts();
		$this->q()->where( 'parcity', 'Oslo' )->ids();
		$this->q()->where( 'parprice', '>', 1 )->count();
		$this->q()->where( 'parprice', '>', 1 )->toSql();
		$this->q()->where( 'parprice', '>', 1 )->explain();
		$this->assertSame( $before, $this->counts() );
	}
}
