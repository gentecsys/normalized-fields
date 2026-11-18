<?php
/**
 * Query context and nf_query() against real NF tables (WP 3.2b).
 *
 * @package NF
 */

use NF\Pivot\Pivot;
use NF\Query\QueryContext;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * ids(), count(), toSql() and explain() on a slotted field and a probed field.
 */
class QueryContextTest extends WP_UnitTestCase {

	private const TYPE = 'nf_ctx_event';

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
	 * Context under test.
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
	 * Fields `price` (slotted int) and `seats` (int without a slot).
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		register_post_type( self::TYPE );
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t32b%' ) );
		$fields = new FieldMap( $db );
		$fields->flush();
		$fields->register( 'field_t32b_price', 'price', DataTable::Int );
		$fields->register( 'field_t32b_seats', 'seats', DataTable::Int );
		$this->store    = new Store( $db, $fields );
		$this->pivot    = new Pivot( $db, $fields, $this->store );
		$this->pivot_id = $this->pivot->setId( ObjectType::Post, self::TYPE );
		$this->clean();
		$this->pivot->assignSlot( $this->pivot_id, 'field_t32b_price', 'i1' );
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
	 * Create an object with values and a pivot row.
	 *
	 * @param int $price Price.
	 * @param int $seats Seats.
	 * @return int
	 */
	private function make( int $price, int $seats ): int {
		$id          = self::factory()->post->create( array( 'post_type' => self::TYPE ) );
		$this->ids[] = $id;
		$this->store->set( ObjectType::Post, $id, 'field_t32b_price', $price );
		$this->store->set( ObjectType::Post, $id, 'field_t32b_seats', $seats );
		$this->pivot->sync( $this->pivot_id, $id, array( 'status' => 1 ) );
		return $id;
	}

	/**
	 * A builder bound to the test context.
	 */
	private function q(): NF\Query\Builder {
		return new NF\Query\Builder( self::TYPE, $this->context );
	}

	/**
	 * Slotted condition, order, limit and count.
	 */
	public function test_ids_and_count_on_slot(): void {
		$a = $this->make( 30, 1 );
		$b = $this->make( 10, 2 );
		$c = $this->make( 20, 3 );
		$this->assertSame( array( $b, $c, $a ), $this->q()->orderBy( 'price' )->ids() );
		$this->assertSame( array( $c, $a ), $this->q()->where( 'price', '>=', 20 )->orderBy( 'price' )->ids() );
		$this->assertSame( array( $b ), $this->q()->orderBy( 'price' )->limit( 1 )->ids() );
		$this->assertSame( 3, $this->q()->limit( 1 )->count(), 'count ignores limit' );
		$this->assertSame( 2, $this->q()->whereBetween( 'price', array( 10, 20 ) )->count() );
	}

	/**
	 * A field without a slot is answered by a typed-table probe.
	 */
	public function test_ids_on_probed_field(): void {
		$this->make( 1, 5 );
		$b = $this->make( 2, 7 );
		$this->assertSame( array( $b ), $this->q()->where( 'seats', 7 )->ids() );
	}

	/**
	 * Publish only by default; other statuses on request.
	 */
	public function test_status_filter(): void {
		$a = $this->make( 1, 1 );
		$this->pivot->sync( $this->pivot_id, $a, array( 'status' => 2 ) );
		$this->assertSame( array(), $this->q()->ids() );
		$this->assertCount( 1, $this->q()->status( array( 'publish', 'draft', 'pending', 'private' ) )->ids() );
	}

	/**
	 * Unknown type: no pivot set, no results, no error.
	 */
	public function test_type_without_set_is_empty(): void {
		$q = new NF\Query\Builder( 'nf_ctx_never_stored', $this->context );
		$this->assertSame( array(), $q->ids() );
		$this->assertSame( 0, $q->count() );
		$this->assertSame( '', $q->toSql() );
	}

	/**
	 * toSql() inlines values; explain() carries the plan and the database's rows.
	 */
	public function test_to_sql_and_explain(): void {
		$this->make( 5, 1 );
		$q = $this->q()->where( 'price', '>', 3 );
		$this->assertStringContainsString( '> 3', $q->toSql() );
		$e = $q->explain();
		$this->assertArrayHasKey( 'plan', $e );
		$this->assertNotEmpty( $e['db'] );
	}

	/**
	 * nf_query() is bound to the plugin's context.
	 */
	public function test_nf_query_function(): void {
		$this->assertInstanceOf( NF\Query\Builder::class, nf_query( self::TYPE ) );
	}

	/**
	 * A spec-only builder refuses to run.
	 */
	public function test_builder_without_context_throws(): void {
		$this->expectException( LogicException::class );
		( new NF\Query\Builder( 'event' ) )->ids();
	}
}
