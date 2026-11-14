<?php
/**
 * Pivot rebuild job tests.
 *
 * @package NF
 */

use NF\Core\Jobs\PivotRebuildJob;
use NF\Pivot\Pivot;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * The job walks the set in chunks, resumes from its state and finishes with a prune.
 */
class PivotRebuildJobTest extends WP_UnitTestCase {

	private const TYPE = 'nf_job_evt';

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
	 * Set up a one-field pivot set.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		register_post_type( self::TYPE );
		$db     = nf_db();
		$fields = new FieldMap( $db );
		$fields->flush();
		$fields->register( 'field_tjob_city', 'city', DataTable::Str );
		$this->pivot    = new Pivot( $db, $fields, new Store( $db, $fields ) );
		$this->pivot_id = $this->pivot->setId( ObjectType::Post, self::TYPE );
		$this->pivot->assignSlot( $this->pivot_id, 'field_tjob_city', 's1' );
	}

	/**
	 * Remove the set's rows.
	 */
	public function tear_down() {
		$db = nf_db();
		foreach ( array( 'nf_pivot', 'nf_row_pivot', 'nf_pivot_slots' ) as $t ) {
			$db->execute( 'DELETE FROM ' . $db->table( $t ) . ' WHERE pivot_id = ?', array( $this->pivot_id ) );
		}
		parent::tear_down();
	}

	/**
	 * Chunks of two over five objects: three steps, the total counts every object, then done.
	 */
	public function test_steps_through_chunks_then_finishes(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$id = self::factory()->post->create( array( 'post_type' => self::TYPE ) );
			$this->pivot->sync( $this->pivot_id, $id, array( 'status' => 1 ) );
		}
		$job   = new PivotRebuildJob( $this->pivot );
		$state = $job->init(
			array(
				'pivot_id' => $this->pivot_id,
				'chunk'    => 2,
			)
		);
		$this->assertSame( 2, $state['chunk'] );
		$state = $job->step( $state );
		$this->assertFalse( $state['done'] );
		$this->assertSame( 2, $state['total'] );
		$state = $job->step( $state );
		$this->assertFalse( $state['done'] );
		$state = $job->step( $state );
		$this->assertTrue( $state['done'] );
		$this->assertSame( 5, $state['total'] );
	}

	/**
	 * Without a pivot id the job finishes at once and touches nothing.
	 */
	public function test_missing_pivot_id_is_done(): void {
		$job   = new PivotRebuildJob( $this->pivot );
		$state = $job->step( $job->init( array() ) );
		$this->assertTrue( $state['done'] );
		$this->assertSame( 0, $state['total'] );
	}
}
