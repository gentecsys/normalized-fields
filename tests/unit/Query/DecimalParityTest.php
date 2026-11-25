<?php
/**
 * Decimal fields: NF answers a meta_query like core does on wp_postmeta (WP 3.3a2).
 *
 * @package NF
 */

declare(strict_types=1);

// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing,WordPress.DB.SlowDBQuery,Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,Generic.Commenting.DocComment.MissingShort

use NF\Pivot\Pivot;
use NF\Query\PreQuery;
use NF\Query\QueryContext;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * Same values in `wp_postmeta` (key `dccore`, core's answer) and in NF (field `dcprice`).
 *
 * @covers \NF\Query\ArgsTranslator
 * @covers \NF\Query\DecimalCast
 * @covers \NF\Query\QueryContext
 */
final class DecimalParityTest extends WP_UnitTestCase {

	private const TYPE   = 'nf_dc_item';
	private const VALUES = array( '-2.675', '-1.5', '-0.5', '-0.4', '0', '0.4', '0.5', '1', '1.5', '2', '2.675', '3.99', '250' );

	/** @var Store */
	private Store $store;
	/** @var PreQuery */
	private PreQuery $hook;
	/** @var int[] Post IDs by value index. */
	private array $ids = array();

	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		update_option( NF\Install\Upgrader::OPTION, NF\Install\Upgrader::SCHEMA_VERSION );
		register_post_type( self::TYPE );
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t33a2%' ) );
		$fields = new FieldMap( $db );
		$fields->flush();
		$fields->register( 'field_t33a2_price', 'dcprice', DataTable::Dec );
		$this->store = new Store( $db, $fields );
		$pivot       = new Pivot( $db, $fields, $this->store );
		$pid         = $pivot->setId( ObjectType::Post, self::TYPE );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot' ) . ' WHERE pivot_id = ?', array( $pid ) );
		foreach ( self::VALUES as $i => $v ) {
			$id              = self::factory()->post->create( array( 'post_type' => self::TYPE ) );
			$this->ids[ $i ] = $id;
			update_post_meta( $id, 'dccore', $v );
			$this->store->set( ObjectType::Post, $id, 'field_t33a2_price', $v );
			$pivot->sync( $pid, $id, array( 'status' => 1 ) );
		}
		$this->hook = new PreQuery( new QueryContext( $db, $pivot, $pivot->dictionary() ) );
		$this->hook->register();
	}

	public function tear_down() {
		remove_filter( 'posts_pre_query', array( $this->hook, 'answer' ), 10 );
		remove_filter( 'found_posts_query', array( $this->hook, 'foundPostsQuery' ), 10 );
		remove_filter( 'found_posts', array( $this->hook, 'foundPosts' ), 1 );
		remove_all_filters( 'nf/query/exact_decimals' );
		foreach ( $this->ids as $id ) {
			$this->store->deleteObject( ObjectType::Post, $id );
			wp_delete_post( $id, true );
		}
		parent::tear_down();
	}

	/** @return int[] Sorted post IDs of one query. */
	private function run_query( string $key, array $clause ): array {
		$q   = new WP_Query(
			array(
				'post_type'      => self::TYPE,
				'fields'         => 'ids',
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array( array_merge( array( 'key' => $key ), $clause ) ),
			)
		);
		$ids = array_map( 'intval', $q->posts );
		sort( $ids );
		return $ids;
	}

	public function test_default_matches_core_for_every_cast_and_operator(): void {
		$clauses = array( '0', '1', '-1', '2', '2.5', '-2.5', '0.4', '-0.4', '2.675', '2.68', '3.99', '250', '-2.675' );
		foreach ( array( 'NUMERIC', 'SIGNED', 'DECIMAL', 'DECIMAL(10,2)', 'DECIMAL(10,1)' ) as $type ) {
			foreach ( $clauses as $c ) {
				foreach ( array( '=', '<', '<=', '>', '>=' ) as $op ) {
					$clause = array( 'value' => $c, 'compare' => $op, 'type' => $type );
					$this->assertSame( $this->run_query( 'dccore', $clause ), $this->run_query( 'dcprice', $clause ), "$type value $op $c" );
				}
				$clause = array( 'value' => array( '-1.5', $c ), 'compare' => 'BETWEEN', 'type' => $type );
				$this->assertSame( $this->run_query( 'dccore', $clause ), $this->run_query( 'dcprice', $clause ), "$type between -1.5 and $c" );
			}
		}
	}

	public function test_exact_filter_compares_stored_values(): void {
		add_filter( 'nf/query/exact_decimals', '__return_true' );
		$got  = $this->run_query( 'dcprice', array( 'value' => '2.5', 'compare' => '>=', 'type' => 'NUMERIC' ) );
		$want = array();
		foreach ( self::VALUES as $i => $v ) {
			if ( (float) $v >= 2.5 ) {
				$want[] = $this->ids[ $i ];
			}
		}
		sort( $want );
		$this->assertSame( $want, $got );
		$in = $this->run_query( 'dcprice', array( 'value' => array( '1.5', '2.675' ), 'compare' => 'IN', 'type' => 'DECIMAL(10,3)' ) );
		$this->assertSame( array( $this->ids[8], $this->ids[10] ), $in );
	}
}
