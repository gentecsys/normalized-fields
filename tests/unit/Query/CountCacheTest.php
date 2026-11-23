<?php
/**
 * Versioned count cache and rollup totals (WP 3.4b).
 *
 * @package NF
 */

use NF\Pivot\Pivot;
use NF\Query\Builder;
use NF\Query\CountCache;
use NF\Query\QueryContext;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * Totals stay exact across writes whether served from the cache or the rollup.
 */
class CountCacheTest extends WP_UnitTestCase {

	private const TYPE = 'nf_cc_event';

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
	 * Field `ccprice` (slotted int).
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		update_option( NF\Install\Upgrader::OPTION, NF\Install\Upgrader::SCHEMA_VERSION );
		register_post_type( self::TYPE );
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t34b%' ) );
		$fields = new FieldMap( $db );
		$fields->flush();
		$fields->register( 'field_t34b_price', 'ccprice', DataTable::Int );
		$this->store    = new Store( $db, $fields );
		$this->pivot    = new Pivot( $db, $fields, $this->store );
		$this->pivot_id = $this->pivot->setId( ObjectType::Post, self::TYPE );
		$this->clean();
		$this->pivot->assignSlot( $this->pivot_id, 'field_t34b_price', 'i1' );
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
		foreach ( array( 'nf_pivot', 'nf_pivot_slots', 'nf_count' ) as $t ) {
			$db->execute( 'DELETE FROM ' . $db->table( $t ) . ' WHERE pivot_id = ?', array( $this->pivot_id ) );
		}
	}

	/**
	 * Create an object with a price and a pivot row.
	 *
	 * @param int $price Price.
	 * @return int
	 */
	private function make( int $price ): int {
		$id          = self::factory()->post->create( array( 'post_type' => self::TYPE ) );
		$this->ids[] = $id;
		$this->store->set( ObjectType::Post, $id, 'field_t34b_price', $price );
		$this->pivot->sync( $this->pivot_id, $id, array( 'status' => 1 ) );
		return $id;
	}

	/**
	 * Builder on the test context.
	 */
	private function q(): Builder {
		return new Builder( self::TYPE, $this->context );
	}

	/**
	 * A filtered total is cached under the write version and refreshed by the next write.
	 */
	public function test_filtered_count_cached_and_versioned(): void {
		$this->make( 5 );
		$this->make( 50 );
		$this->assertSame( 1, $this->q()->where( 'ccprice', '>', 10 )->count() );

		// A hit serves the cached value even if the statement would now differ.
		$cc = $this->q()->where( 'ccprice', '>', 10 )->toSpec();
		$c  = $this->context->compile( $cc );
		$k  = CountCache::key( $this->pivot_id, $this->pivot->writeVersion( $this->pivot_id ), $c->count_sql, $c->count_params );
		$this->assertSame( 1, CountCache::get( $k ) );

		$this->make( 60 );
		$this->assertSame( 2, $this->q()->where( 'ccprice', '>', 10 )->count(), 'write bumped the version' );
	}

	/**
	 * Unfiltered totals come from the rollup once it was built, and follow writes and status moves.
	 */
	public function test_unfiltered_total_from_rollup(): void {
		$a = $this->make( 1 );
		$this->make( 2 );
		$this->pivot->rebuildCounts( $this->pivot_id );
		$this->assertTrue( $this->pivot->countsReady( $this->pivot_id ) );
		$this->assertSame( 2, $this->q()->count() );
		$this->make( 3 );
		$this->assertSame( 3, $this->q()->count() );
		$this->pivot->sync( $this->pivot_id, $a, array( 'status' => 2 ) );
		$this->assertSame( 2, $this->q()->count() );
	}

	/**
	 * Without a built rollup the live statement answers.
	 */
	public function test_live_count_without_rollup(): void {
		$this->make( 1 );
		$this->assertFalse( $this->pivot->countsReady( $this->pivot_id ) );
		$this->assertSame( 1, $this->q()->count() );
	}

	/**
	 * Reading counts writes nothing to NF tables for an anonymous visitor.
	 */
	public function test_read_writes_no_rows(): void {
		$this->make( 1 );
		$this->pivot->rebuildCounts( $this->pivot_id );
		wp_set_current_user( 0 );
		$db     = nf_db();
		$before = $db->select( 'SELECT COALESCE(SUM(n),0) AS c FROM ' . $db->table( 'nf_count' ) . ' WHERE pivot_id = ?', array( $this->pivot_id ) );
		$this->q()->count();
		$this->q()->where( 'ccprice', '>', 0 )->count();
		$after = $db->select( 'SELECT COALESCE(SUM(n),0) AS c FROM ' . $db->table( 'nf_count' ) . ' WHERE pivot_id = ?', array( $this->pivot_id ) );
		$this->assertSame( $before, $after );
	}
}
