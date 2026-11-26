<?php
/**
 * Count recompute job and its debounced trigger (WP 3.4d).
 *
 * @package NF
 */

use NF\Core\Jobs\CountRecomputeJob;
use NF\Core\Jobs\JobRunner;
use NF\Pivot\Pivot;
use NF\Query\CountCache;
use NF\Query\CountRecompute;
use NF\Query\CountTable;
use NF\Query\QueryContext;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * Declared combinations are counted into the caches by a job, with no logged-in user.
 */
class CountRecomputeTest extends WP_UnitTestCase {

	/**
	 * Post type.
	 */
	private const TYPE = 'nf_rc_event';

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
	 * Query context.
	 *
	 * @var QueryContext
	 */
	private QueryContext $context;

	/**
	 * Created post ids.
	 *
	 * @var int[]
	 */
	private array $ids = array();

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		update_option( NF\Install\Upgrader::OPTION, NF\Install\Upgrader::SCHEMA_VERSION );
		register_post_type( self::TYPE );
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t34d%' ) );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_count_cache' ) );
		$fields = new FieldMap( $db );
		$fields->flush();
		$fields->register( 'field_t34d_price', 'rcprice', DataTable::Int );
		$this->store    = new Store( $db, $fields );
		$this->pivot    = new Pivot( $db, $fields, $this->store );
		$this->pivot_id = $this->pivot->setId( ObjectType::Post, self::TYPE );
		$this->pivot->assignSlot( $this->pivot_id, 'field_t34d_price', 'i1' );
		$this->context = new QueryContext( $db, $this->pivot, $this->pivot->dictionary() );
		add_filter( 'nf/count/combinations', array( $this, 'combos' ), 10, 2 );
	}

	/**
	 * Clean up.
	 */
	public function tear_down() {
		remove_filter( 'nf/count/combinations', array( $this, 'combos' ), 10 );
		foreach ( $this->ids as $id ) {
			$this->store->deleteObject( ObjectType::Post, $id );
			wp_delete_post( $id, true );
		}
		$db = nf_db();
		foreach ( array( 'nf_pivot', 'nf_row_pivot', 'nf_pivot_slots' ) as $t ) {
			$db->execute( 'DELETE FROM ' . $db->table( $t ) . ' WHERE pivot_id = ?', array( $this->pivot_id ) );
		}
		parent::tear_down();
	}

	/**
	 * Declared combinations for the test type: one good, one with an unknown field.
	 *
	 * @param array<int,array<string,mixed>> $declared Combinations.
	 * @param string                         $target Target.
	 * @return array<int,array<string,mixed>>
	 */
	public function combos( array $declared, string $target ): array {
		if ( self::TYPE === $target ) {
			$declared[] = array( 'where' => array( array( 'rcprice', '>=', 20 ) ) );
			$declared[] = array( 'where' => array( array( 'no_such_field', '=', 1 ) ) );
		}
		return $declared;
	}

	/**
	 * Make an object with a price.
	 *
	 * @param int $price Price.
	 */
	private function make( int $price ): void {
		$id          = self::factory()->post->create( array( 'post_type' => self::TYPE ) );
		$this->ids[] = $id;
		$this->store->set( ObjectType::Post, $id, 'field_t34d_price', $price );
		$this->pivot->sync( $this->pivot_id, $id, array( 'status' => 1 ) );
	}

	/**
	 * Job under test.
	 */
	private function job(): CountRecomputeJob {
		return new CountRecomputeJob( nf_db(), fn() => $this->context );
	}

	/**
	 * The job counts a declared combination into the table with no user, and skips a bad one.
	 */
	public function test_job_warms_table_without_user(): void {
		$this->make( 10 );
		$this->make( 30 );
		wp_set_current_user( 0 );
		$job   = $this->job();
		$state = $job->step( $job->init( array( 'pivot_id' => $this->pivot_id ) ) );
		$this->assertTrue( $state['done'] );
		$this->assertSame( 1, $state['counted'], 'The unknown field is skipped.' );
		$db   = nf_db();
		$rows = $db->select( 'SELECT n FROM ' . $db->table( 'nf_count_cache' ) . ' WHERE pivot_id = ?', array( $this->pivot_id ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 1, (int) $rows[0]['n'] );
	}

	/**
	 * Targets resolve from the set.
	 */
	public function test_target(): void {
		$this->assertSame( self::TYPE, $this->job()->target( $this->pivot_id ) );
		$this->assertNull( $this->job()->target( 65000 ) );
	}

	/**
	 * A save queues one job, and a second save while it is queued queues nothing more.
	 */
	public function test_trigger_debounces(): void {
		$jobs = new JobRunner( array( $this->job() ) );
		$svc  = new CountRecompute( $jobs, $this->job() );
		$id   = JobRunner::idFor( 'count_recompute', array( 'pivot_id' => $this->pivot_id ) );
		delete_option( JobRunner::OPTION_PREFIX . sanitize_key( $id ) );
		$svc->onChanged( $this->pivot_id );
		$first = $jobs->record( $id );
		$this->assertSame( 'queued', $first['status'] ?? null );
		$svc->onChanged( $this->pivot_id );
		$this->assertSame( $first['started'], $jobs->record( $id )['started'] );
		delete_option( JobRunner::OPTION_PREFIX . sanitize_key( $id ) );
	}

	/**
	 * A set with no declared combinations queues nothing.
	 */
	public function test_trigger_ignores_undeclared_set(): void {
		remove_filter( 'nf/count/combinations', array( $this, 'combos' ), 10 );
		$jobs = new JobRunner( array( $this->job() ) );
		$id   = JobRunner::idFor( 'count_recompute', array( 'pivot_id' => $this->pivot_id ) );
		delete_option( JobRunner::OPTION_PREFIX . sanitize_key( $id ) ); // Earlier saves in other tests may have queued it.
		( new CountRecompute( $jobs, $this->job() ) )->onChanged( $this->pivot_id );
		$this->assertNull( $jobs->record( $id ) );
	}
}
