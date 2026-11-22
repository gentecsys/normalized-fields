<?php
/**
 * Slot assignment safety, rebuild re-queue, bitmask overflow, field name scope, term and user deletion (WP 3.0k).
 *
 * @package NF
 */

use NF\Core\Jobs\PivotRebuildJob;
use NF\Pivot\Pivot;
use NF\Pivot\SlotAssigner;
use NF\Query\MaskCondition;
use NF\Query\QueryContext;
use NF\Query\QuerySpec;
use NF\Query\Condition;
use NF\Query\UnsupportedQueryException;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\Lifecycle;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * Review fixes 2.
 */
class PivotFixesTest extends WP_UnitTestCase {

	private const TYPE = 'nf_fix2_a';

	private const TYPE_B = 'nf_fix2_b';

	/**
	 * Services.
	 *
	 * @var FieldMap
	 */
	private FieldMap $fields;

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
	 * Clean state.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		update_option( NF\Install\Upgrader::OPTION, NF\Install\Upgrader::SCHEMA_VERSION );
		register_post_type( self::TYPE );
		register_post_type( self::TYPE_B );
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field\\_t30k%' ) );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot_sets' ) . ' WHERE subtype IN (?, ?)', array( self::TYPE, self::TYPE_B ) );
		$this->fields = new FieldMap( $db );
		$this->fields->flush();
		$this->store = new Store( $db, $this->fields );
		$this->pivot = new Pivot( $db, $this->fields, $this->store );
		NF\Pivot\FieldScope::flush();
	}

	/**
	 * Remove sets.
	 */
	public function tear_down() {
		$db = nf_db();
		foreach ( array( self::TYPE, self::TYPE_B ) as $t ) {
			$id = $this->pivot->findSetId( ObjectType::Post, $t );
			if ( null !== $id ) {
				$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot' ) . ' WHERE pivot_id = ?', array( $id ) );
				$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot_slots' ) . ' WHERE pivot_id = ?', array( $id ) );
				$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot_sets' ) . ' WHERE pivot_id = ?', array( $id ) );
				delete_option( 'nf_pivot_pending_' . $id );
			}
		}
		NF\Pivot\FieldScope::flush();
		parent::tear_down();
	}

	/**
	 * A field whose type changed makes the whole assignment fail before any slot is written.
	 */
	public function test_failed_assignment_writes_no_slot(): void {
		$this->fields->register( 'field_t30k_changed', 'changed', DataTable::Str );
		$assigner = new SlotAssigner( $this->pivot, $this->fields );
		$fields   = array(
			array(
				'key'  => 'field_t30k_first',
				'name' => 'first',
				'type' => 'number',
			),
			array(
				'key'  => 'field_t30k_changed',
				'name' => 'changed',
				'type' => 'number',
			),
		);
		try {
			$assigner->assign( self::TYPE, $fields );
			$this->fail( 'Expected the type change to throw.' );
		} catch ( InvalidArgumentException $e ) {
			$this->assertStringContainsString( 'different storage type', $e->getMessage() );
		}
		$id = $this->pivot->findSetId( ObjectType::Post, self::TYPE );
		$this->assertTrue( null === $id || array() === $this->pivot->slots( $id ) );
	}

	/**
	 * Slots assigned while a rebuild runs are rebuilt: the job starts over for them.
	 */
	public function test_rebuild_job_requeues_for_new_pending_slots(): void {
		$id = $this->pivot->setId( ObjectType::Post, self::TYPE );
		$this->pivot->markPending( $id, array( 'n1' ) );
		$job   = new PivotRebuildJob( $this->pivot );
		$state = $job->init( array( 'pivot_id' => $id ) );
		$this->assertSame( array( 'n1' ), $state['pending'] );
		$this->pivot->markPending( $id, array( 'n2' ) ); // Assigned while the job runs.
		$state = $job->step( $state );
		$this->assertFalse( $state['done'] );
		$this->assertSame( array( 'n2' ), $state['pending'] );
		$state = $job->step( $state );
		$this->assertTrue( $state['done'] );
		$this->assertSame( array(), $this->pivot->pendingSlots( $id ) );
	}

	/**
	 * A value with no bit (65th value) is found through the typed table.
	 */
	public function test_mask_overflow_value_falls_back_to_probe(): void {
		$cat = $this->fields->register( 'field_t30k_fixcat', 'fixcat', DataTable::Str, true );
		$set = $this->pivot->setId( ObjectType::Post, self::TYPE );
		$this->pivot->assignSlot( $set, 'field_t30k_fixcat', 'm1' );
		$all = array();
		for ( $i = 0; $i < 65; $i++ ) {
			$all[] = 'v' . $i;
		}
		$id = self::factory()->post->create( array( 'post_type' => self::TYPE ) );
		$this->store->set( ObjectType::Post, $id, 'field_t30k_fixcat', $all );
		$this->pivot->sync( $set, $id, array( 'status' => 1 ) );
		$ctx = new QueryContext( nf_db(), $this->pivot, $this->pivot->dictionary() );
		$this->assertNull( $this->pivot->dictionary()->findBit( $cat->id, 'v64' ) );
		$spec = new QuerySpec( ObjectType::Post, self::TYPE, array(), array(), array(), 0, 0, array( new MaskCondition( 'fixcat', 'any', array( 'v64' ) ) ) );
		$this->assertSame( array( $id ), $ctx->ids( $spec ) );
		$spec = new QuerySpec( ObjectType::Post, self::TYPE, array(), array(), array(), 0, 0, array( new MaskCondition( 'fixcat', 'any', array( 'missing' ) ) ) );
		$this->assertSame( array(), $ctx->ids( $spec ) );
	}

	/**
	 * Same field name in two collections: the post type's own collection wins; an unattached name is ambiguous.
	 */
	public function test_field_name_scope(): void {
		$this->fields->register( 'field_t30k_price_a', 'price', DataTable::Int );
		$this->fields->register( 'field_t30k_price_b', 'price', DataTable::Int );
		$source = new class() extends NF\Migration\AcfSource {
			/**
			 * Groups.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function groups(): array {
				return array(
					array(
						'active'   => true,
						'location' => array( array( array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'nf_fix2_a',
						) ) ),
						'fields'   => array( array(
							'key'  => 'field_t30k_price_a',
							'name' => 'price',
							'type' => 'number',
						) ),
					),
					array(
						'active'   => true,
						'location' => array( array( array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'nf_fix2_b',
						) ) ),
						'fields'   => array( array(
							'key'  => 'field_t30k_price_b',
							'name' => 'price',
							'type' => 'number',
						) ),
					),
				);
			}
		};
		$scope  = new NF\Pivot\FieldScope( $source );
		$rows   = nf_db()->select( 'SELECT field_key FROM ' . nf_db()->table( 'nf_fields' ) . ' WHERE field_name = ? ORDER BY field_id', array( 'price' ) );
		$this->assertCount( 2, $rows );
		$this->assertSame( 'field_t30k_price_b', $scope->choose( $rows, ObjectType::Post, self::TYPE_B, 'price' )['field_key'] );
		$this->assertSame( 'field_t30k_price_a', $scope->choose( $rows, ObjectType::Post, self::TYPE, 'price' )['field_key'] );
		$this->assertNull( $scope->choose( $rows, ObjectType::Post, 'page', 'price' ) );
		$this->assertSame( 'field_t30k_price_a', $scope->choose( $rows, ObjectType::Term, 'category', 'price' )['field_key'] );
	}

	/**
	 * Ambiguous names throw only when a query uses them.
	 */
	public function test_query_on_ambiguous_name_throws(): void {
		$this->fields->register( 'field_t30k_dup_a', 'dup', DataTable::Int );
		$this->fields->register( 'field_t30k_dup_b', 'dup', DataTable::Int );
		$this->pivot->setId( ObjectType::Post, self::TYPE );
		$group = static fn( string $key ): array => array(
			'active'   => true,
			'location' => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => 'nf_fix2_a',
					),
				),
			),
			'fields'   => array(
				array(
					'key'  => $key,
					'name' => 'dup',
					'type' => 'number',
				),
			),
		);
		NF\Pivot\FieldScope::useSource(
			new class( array( $group( 'field_t30k_dup_a' ), $group( 'field_t30k_dup_b' ) ) ) extends NF\Migration\AcfSource {
				/**
				 * Constructor.
				 *
				 * @param array<int,array<string,mixed>> $groups Groups.
				 */
				public function __construct( private array $groups ) {}

				/**
				 * Groups.
				 *
				 * @return array<int,array<string,mixed>>
				 */
				public function groups(): array {
					return $this->groups;
				}
			}
		);
		$ctx = new QueryContext( nf_db(), $this->pivot, $this->pivot->dictionary() );
		$this->assertSame( array(), $ctx->ids( new QuerySpec( ObjectType::Post, self::TYPE ) ) );
		$this->expectException( UnsupportedQueryException::class );
		$ctx->ids( new QuerySpec( ObjectType::Post, self::TYPE, array(), array( new Condition( 'dup', '=', 1 ) ) ) );
	}

	/**
	 * Deleting a term or user removes its pivot rows.
	 */
	public function test_term_and_user_deletion_remove_pivot_rows(): void {
		$db   = nf_db();
		$life = new Lifecycle( $db, $this->fields, $this->store );
		$tset = $this->pivot->setId( ObjectType::Term, 'nf_fix2_tax' );
		$uset = $this->pivot->setId( ObjectType::User, '' );
		$this->pivot->sync( $tset, 987001, array( 'status' => 1 ) );
		$this->pivot->sync( $uset, 987002, array( 'status' => 1 ) );
		$count = static fn( int $set, int $oid ): int => (int) nf_db()->select( 'SELECT COUNT(*) AS c FROM ' . nf_db()->table( 'nf_pivot' ) . ' WHERE pivot_id = ? AND object_id = ?', array( $set, $oid ) )[0]['c'];
		$this->assertSame( 1, $count( $tset, 987001 ) );
		$life->onTermDeleted( 987001, 0, 'nf_fix2_tax' );
		$life->onUserDeleted( 987002 );
		$this->assertSame( 0, $count( $tset, 987001 ) );
		$this->assertSame( 0, $count( $uset, 987002 ) );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot_sets' ) . ' WHERE pivot_id IN (?, ?)', array( $tset, $uset ) );
	}
}
