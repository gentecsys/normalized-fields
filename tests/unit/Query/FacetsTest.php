<?php
/**
 * Facet counts from the rollup and live (WP 3.4e).
 *
 * @package NF
 */

use NF\Pivot\Pivot;
use NF\Query\Builder;
use NF\Query\QueryContext;
use NF\Query\UnsupportedQueryException;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * Rollup facets equal live facets, and both equal a count taken per value.
 *
 * @covers \NF\Query\Facets
 * @covers \NF\Query\QueryContext
 */
final class FacetsTest extends WP_UnitTestCase {

	private const TYPE = 'nf_fc_item';

	/**
	 * Fixture.
	 *
	 * @var Store
	 */
	private Store $store;
	/**
	 * Fixture.
	 *
	 * @var Pivot
	 */
	private Pivot $pivot;
	/**
	 * Fixture.
	 *
	 * @var QueryContext
	 */
	private QueryContext $context;
	/**
	 * Fixture.
	 *
	 * @var int[] Post IDs.
	 */
	private array $ids = array();
	/**
	 * Fixture.
	 *
	 * @var int Pivot set id.
	 */
	private int $pid;

	/**
	 * Set up: 12 posts, colour cycling red, green, blue; every third post flagged.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		update_option( NF\Install\Upgrader::OPTION, NF\Install\Upgrader::SCHEMA_VERSION );
		register_post_type( self::TYPE );
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t34e%' ) );
		$fields = new FieldMap( $db );
		$fields->flush();
		$fields->register( 'field_t34e_colour', 'fccolour', DataTable::Str );
		$fields->register( 'field_t34e_flag', 'fcflag', DataTable::Int );
		$this->store = new Store( $db, $fields );
		$this->pivot = new Pivot( $db, $fields, $this->store );
		$this->pid   = $this->pivot->setId( ObjectType::Post, self::TYPE );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot' ) . ' WHERE pivot_id = ?', array( $this->pid ) );
		$this->pivot->assignSlot( $this->pid, 'field_t34e_colour', 's1' );
		$this->pivot->assignSlot( $this->pid, 'field_t34e_flag', 'i1' );
		$colours = array( 'red', 'green', 'blue' );
		for ( $i = 0; $i < 12; $i++ ) {
			$id          = self::factory()->post->create( array( 'post_type' => self::TYPE ) );
			$this->ids[] = $id;
			$this->store->set( ObjectType::Post, $id, 'field_t34e_colour', $colours[ $i % 3 ] );
			$this->store->set( ObjectType::Post, $id, 'field_t34e_flag', 0 === $i % 3 ? 1 : 0 );
			$this->pivot->sync( $this->pid, $id, array( 'status' => 1 ) );
		}
		$this->pivot->rebuildCounts( $this->pid );
		$this->context = new QueryContext( $db, $this->pivot, $this->pivot->dictionary() );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		foreach ( $this->ids as $id ) {
			$this->store->deleteObject( ObjectType::Post, $id );
			wp_delete_post( $id, true );
		}
		delete_option( 'nf_counts_ready_' . $this->pid );
		parent::tear_down();
	}

	/**
	 * Facet counts as value => count.
	 *
	 * @param Builder $q Query.
	 * @return array<string,int>
	 */
	private function colours( Builder $q ): array {
		$out = array();
		foreach ( $q->facets( array( 'fccolour' ) )['fccolour'] as $row ) {
			$out[ $row['value'] ] = $row['count'];
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Unfiltered facets come from the rollup and match the data.
	 */
	public function test_rollup_facets(): void {
		$q = new Builder( self::TYPE, $this->context );
		$this->assertSame(
			array(
				'blue'  => 4,
				'green' => 4,
				'red'   => 4,
			),
			$this->colours( $q )
		);
	}

	/**
	 * A filtered query groups live and equals a per-value count.
	 */
	public function test_filtered_facets_match_per_value_counts(): void {
		$flagged = $this->colours( ( new Builder( self::TYPE, $this->context ) )->where( 'fcflag', 1 ) );
		$this->assertSame( array( 'red' => 4 ), $flagged, 'Every third post (index 0, 3, 6, 9) is red and flagged.' );
		foreach ( array( 'red', 'green', 'blue' ) as $c ) {
			$n = ( new Builder( self::TYPE, $this->context ) )->where( 'fccolour', $c )->count();
			$this->assertSame( 4, $n );
		}
	}

	/**
	 * Rollup and live facets agree when a filter matches everything.
	 */
	public function test_rollup_equals_live(): void {
		$rollup = $this->colours( new Builder( self::TYPE, $this->context ) );
		$live   = $this->colours( ( new Builder( self::TYPE, $this->context ) )->where( 'fcflag', '>=', 0 ) );
		$this->assertSame( $rollup, $live );
	}

	/**
	 * Draft posts move out of the published facet counts through the rollup.
	 */
	public function test_status_is_respected(): void {
		$this->pivot->sync( $this->pid, $this->ids[0], array( 'status' => 2 ) );
		$counts = $this->colours( new Builder( self::TYPE, $this->context ) );
		$this->assertSame( 3, $counts['red'] );
	}

	/**
	 * A field without a dictionary or bitmask slot cannot be faceted.
	 */
	public function test_integer_field_is_unsupported(): void {
		$this->expectException( UnsupportedQueryException::class );
		( new Builder( self::TYPE, $this->context ) )->facets( array( 'fcflag' ) );
	}
}
