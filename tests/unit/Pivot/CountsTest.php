<?php
/**
 * Count rollup (nf_count): write-through equals a rebuild and a live COUNT(*) (WP 3.4).
 *
 * @package NF
 */

use NF\Pivot\Pivot;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * The rollup stays exact through inserts, status changes, value changes and deletes.
 */
class CountsTest extends WP_UnitTestCase {

	private const TYPE = 'nf_counts_evt';

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
	 * Created ids.
	 *
	 * @var int[]
	 */
	private array $ids = array();

	/**
	 * Fields (city str s1, cats multi str m1, featured int i1) and set.
	 */
	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		register_post_type( self::TYPE );
		add_filter( 'nf/count/int_dimensions', fn() => array( 'i1' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t34%' ) );
		$fields = new FieldMap( $db );
		$fields->flush();
		$fields->register( 'field_t34_city', 'city', DataTable::Str );
		$fields->register( 'field_t34_cats', 'cats', DataTable::Str, true );
		$fields->register( 'field_t34_feat', 'feat', DataTable::Int );
		$this->store    = new Store( $db, $fields );
		$this->pivot    = new Pivot( $db, $fields, $this->store );
		$this->pivot_id = $this->pivot->setId( ObjectType::Post, self::TYPE );
		$this->clean();
		$this->pivot->assignSlot( $this->pivot_id, 'field_t34_city', 's1' );
		$this->pivot->assignSlot( $this->pivot_id, 'field_t34_cats', 'm1' );
		$this->pivot->assignSlot( $this->pivot_id, 'field_t34_feat', 'i1' );
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
	 * Delete pivot and count data of the test set.
	 */
	private function clean(): void {
		$db = nf_db();
		foreach ( array( 'nf_pivot', 'nf_row_pivot', 'nf_pivot_slots', 'nf_count' ) as $t ) {
			$db->execute( 'DELETE FROM ' . $db->table( $t ) . ' WHERE pivot_id = ?', array( $this->pivot_id ) );
		}
	}

	/**
	 * Create an object, store values, sync.
	 *
	 * @param string   $city  City.
	 * @param string[] $cats  Categories.
	 * @param int      $feat  Featured flag.
	 * @param int      $stat  Status code.
	 * @param string   $date  Published date.
	 * @return int
	 */
	private function make( string $city, array $cats, int $feat, int $stat = 1, string $date = '2026-11-03 10:00:00' ): int {
		$id          = self::factory()->post->create( array( 'post_type' => self::TYPE ) );
		$this->ids[] = $id;
		$this->store->set( ObjectType::Post, $id, 'field_t34_city', $city );
		$this->store->set( ObjectType::Post, $id, 'field_t34_cats', $cats );
		$this->store->set( ObjectType::Post, $id, 'field_t34_feat', $feat );
		$this->pivot->sync(
			$this->pivot_id,
			$id,
			array(
				'status'    => $stat,
				'published' => $date,
			)
		);
		return $id;
	}

	/**
	 * Core columns for a sync.
	 *
	 * @param int $status Status code.
	 * @return array<string,mixed>
	 */
	private function core( int $status ): array {
		return array(
			'status'    => $status,
			'published' => '2026-11-03 10:00:00',
		);
	}

	/**
	 * Every cell of the rollup, sorted.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function cells(): array {
		$db = nf_db();
		return $db->select( 'SELECT status, dim, val, day, n FROM ' . $db->table( 'nf_count' ) . ' WHERE pivot_id = ? ORDER BY status, dim, val, day', array( $this->pivot_id ) );
	}

	/**
	 * Live count of pivot rows.
	 *
	 * @param string $where Extra condition (trusted test literal).
	 * @return int
	 */
	private function live( string $where = '1=1' ): int {
		$db   = nf_db();
		$rows = $db->select( 'SELECT COUNT(*) AS c FROM ' . $db->table( 'nf_pivot' ) . " WHERE pivot_id = ? AND {$where}", array( $this->pivot_id ) );
		return (int) $rows[0]['c'];
	}

	/**
	 * Write-through counts equal live counts, including a status move, a value change and a delete.
	 */
	public function test_write_through_equals_live_counts(): void {
		$a = $this->make( 'Oslo', array( 'music', 'art' ), 1 );
		$b = $this->make( 'Oslo', array( 'music' ), 0 );
		$this->make( 'Rome', array( 'art' ), 1, 1, '2026-11-04 09:00:00' );
		$c      = $this->make( 'Rome', array(), 1, 2 );
		$counts = $this->pivot->counts();
		$this->assertSame( 3, $counts->count( $this->pivot_id, array( 1 ) ) );
		$this->assertSame( 1, $counts->count( $this->pivot_id, array( 2 ) ) );
		$this->assertSame( 2, $counts->count( $this->pivot_id, array( 1 ), 'i1', 1 ) );
		$this->assertSame( 1, $counts->count( $this->pivot_id, array( 1 ), '*', 0, '2026-11-04', '2026-11-04' ) );
		$this->assertSame( $this->live( 'status = 1' ), $counts->count( $this->pivot_id, array( 1 ) ) );

		// Move $c to publish, change $b's flag, trash $a, delete $b: cells stay equal to a rebuild.
		$this->pivot->sync( $this->pivot_id, $c, $this->core( 1 ) );
		$this->store->set( ObjectType::Post, $b, 'field_t34_feat', 1 );
		$this->pivot->sync( $this->pivot_id, $b, $this->core( 1 ) );
		$this->pivot->sync( $this->pivot_id, $a, $this->core( 6 ) );
		$this->pivot->remove( $this->pivot_id, $b );
		$through = $this->cells();
		$this->pivot->rebuildCounts( $this->pivot_id );
		$this->assertEquals( $through, $this->cells() );
		$this->assertSame( 2, $counts->count( $this->pivot_id, array( 1 ) ) );
		$this->assertSame( 1, $counts->count( $this->pivot_id, array( 6 ) ) );
		$this->assertSame( 0, $counts->count( $this->pivot_id, array( 2 ) ) );
		$this->assertSame( $this->live( 'status = 1' ), $counts->count( $this->pivot_id, array( 1 ) ) );
	}

	/**
	 * Bit cells match the bitmask column and no zero cells are left behind.
	 */
	public function test_bit_cells_and_no_zero_rows(): void {
		$a = $this->make( 'Oslo', array( 'music', 'art' ), 0 );
		$this->make( 'Oslo', array( 'art' ), 0 );
		$db   = nf_db();
		$rows = $db->select( 'SELECT m1 FROM ' . $db->table( 'nf_pivot' ) . ' WHERE pivot_id = ? AND object_id = ?', array( $this->pivot_id, $a ) );
		$mask = (int) $rows[0]['m1'];
		$bits = array_values( array_filter( range( 0, 63 ), fn( $b ) => 0 !== ( $mask & ( 1 << $b ) ) ) );
		$this->assertCount( 2, $bits );
		foreach ( $bits as $bit ) {
			$this->assertSame( $this->live( '(m1 & ' . ( 1 << $bit ) . ') <> 0' ), $this->pivot->counts()->count( $this->pivot_id, array( 1 ), 'm1', $bit ) );
		}
		$this->pivot->remove( $this->pivot_id, $a );
		foreach ( $this->cells() as $cell ) {
			$this->assertGreaterThan( 0, (int) $cell['n'] );
		}
	}

	/**
	 * Seeded fuzz: random writes keep the rollup equal to a rebuild.
	 */
	public function test_fuzz_rollup_equals_rebuild(): void {
		$seed   = 34;
		$rnd    = function ( int $lo, int $hi ) use ( &$seed ): int {
			$seed = ( $seed * 1103515245 + 12345 ) % 2147483648;
			return $lo + intdiv( $seed >> 8, 1 ) % ( $hi - $lo + 1 );
		};
		$cities = array( 'Oslo', 'Rome', 'Lima' );
		$cats   = array( 'a', 'b', 'c', 'd' );
		$made   = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$pick   = array_values( array_filter( $cats, fn() => 1 === $rnd( 0, 1 ) ) );
			$made[] = $this->make( $cities[ $rnd( 0, 2 ) ], $pick, $rnd( 0, 1 ), $rnd( 1, 3 ), '2026-11-0' . $rnd( 1, 5 ) . ' 08:00:00' );
		}
		foreach ( $made as $id ) {
			$roll = $rnd( 0, 2 );
			if ( 0 === $roll ) {
				$this->pivot->remove( $this->pivot_id, $id );
			} elseif ( 1 === $roll ) {
				$this->store->set( ObjectType::Post, $id, 'field_t34_city', $cities[ $rnd( 0, 2 ) ] );
				$core = array(
					'status'    => $rnd( 1, 3 ),
					'published' => '2026-11-0' . $rnd( 1, 5 ) . ' 08:00:00',
				);
				$this->pivot->sync( $this->pivot_id, $id, $core );
			}
		}
		$through = $this->cells();
		$this->pivot->rebuildCounts( $this->pivot_id );
		$this->assertEquals( $through, $this->cells() );
		$this->assertSame( $this->live( 'status = 1' ), $this->pivot->counts()->count( $this->pivot_id, array( 1 ) ) );
	}

	/**
	 * A failure inside the write transaction leaves the rollup unchanged.
	 */
	public function test_rollback_leaves_counts_unchanged(): void {
		$a      = $this->make( 'Oslo', array( 'art' ), 1 );
		$before = $this->cells();
		try {
			nf_db()->transaction(
				function () use ( $a ): void {
					$this->pivot->sync( $this->pivot_id, $a, $this->core( 6 ) );
					throw new RuntimeException( 'abort' );
				}
			);
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'abort', $e->getMessage() );
		}
		$this->assertEquals( $before, $this->cells() );
	}
}
