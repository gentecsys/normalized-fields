<?php
/**
 * Pagination parity: NF answers WP_Query pages like core does on wp_postmeta (WP 3.3d).
 *
 * @package NF
 */

declare(strict_types=1);

// phpcs:disable WordPress.Arrays.ArrayDeclarationSpacing,WordPress.DB.SlowDBQuery,Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,Generic.Commenting.DocComment.MissingShort,Universal.Operators.DisallowShortTernary

use NF\Pivot\Pivot;
use NF\Query\Builder;
use NF\Query\PreQuery;
use NF\Query\QueryContext;
use NF\Storage\DataTable;
use NF\Storage\FieldMap;
use NF\Storage\ObjectType;
use NF\Storage\Store;

/**
 * Same flag in `wp_postmeta` (key `pgcore`, core's answer) and in NF (field `pgflag`).
 *
 * @covers \NF\Query\PreQuery
 * @covers \NF\Query\ArgsTranslator
 */
final class PaginationParityTest extends WP_UnitTestCase {

	private const TYPE  = 'nf_pg_item';
	private const TOTAL = 26;

	/** @var Store */
	private Store $store;
	/** @var PreQuery */
	private PreQuery $hook;
	/** @var QueryContext */
	private QueryContext $context;
	/** @var int[] Every post ID. */
	private array $ids = array();
	/** @var int Posts carrying the flag. */
	private int $matching = 0;

	public function set_up() {
		parent::set_up();
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		NF\Install\Schema::install();
		update_option( NF\Install\Upgrader::OPTION, NF\Install\Upgrader::SCHEMA_VERSION );
		register_post_type( self::TYPE );
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field_t33d%' ) );
		$fields = new FieldMap( $db );
		$fields->flush();
		$fields->register( 'field_t33d_flag', 'pgflag', DataTable::Int );
		$this->store = new Store( $db, $fields );
		$pivot       = new Pivot( $db, $fields, $this->store );
		$pid         = $pivot->setId( ObjectType::Post, self::TYPE );
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_pivot' ) . ' WHERE pivot_id = ?', array( $pid ) );
		for ( $i = 0; $i < self::TOTAL; $i++ ) {
			$id          = self::factory()->post->create( array( 'post_type' => self::TYPE ) );
			$this->ids[] = $id;
			$flag        = 0 === $i % 4 ? 0 : 1; // 26 posts, 19 flagged.
			$this->matching += $flag;
			update_post_meta( $id, 'pgcore', (string) $flag );
			$this->store->set( ObjectType::Post, $id, 'field_t33d_flag', $flag );
			$pivot->sync( $pid, $id, array( 'status' => 1 ) );
		}
		$this->context = new QueryContext( $db, $pivot, $pivot->dictionary() );
		$this->hook    = new PreQuery( $this->context );
		$this->hook->register();
	}

	public function tear_down() {
		remove_filter( 'posts_pre_query', array( $this->hook, 'answer' ), 10 );
		remove_filter( 'found_posts_query', array( $this->hook, 'foundPostsQuery' ), 10 );
		remove_filter( 'found_posts', array( $this->hook, 'foundPosts' ), 1 );
		remove_all_filters( 'nf/count/cap' );
		foreach ( $this->ids as $id ) {
			$this->store->deleteObject( ObjectType::Post, $id );
			wp_delete_post( $id, true );
		}
		parent::tear_down();
	}

	/**
	 * Run one query on the key and return what pagination exposes.
	 *
	 * @param string              $key  Meta key.
	 * @param array<string,mixed> $args Extra WP_Query arguments.
	 * @return array<string,mixed>
	 */
	private function run( string $key, array $args ): array {
		$q = new WP_Query(
			array_merge(
				array(
					'post_type'  => self::TYPE,
					'fields'     => 'ids',
					'orderby'    => 'ID',
					'order'      => 'ASC',
					'meta_query' => array( array( 'key' => $key, 'value' => '1', 'compare' => '=', 'type' => 'NUMERIC' ) ),
				),
				$args
			)
		);
		return array(
			'ids'   => array_map( 'intval', $q->posts ),
			'found' => (int) $q->found_posts,
			'pages' => (int) $q->max_num_pages,
			'mode'  => $q->get( 'nf_mode' ),
		);
	}

	/** @param array<string,mixed> $args */
	private function assertParity( array $args, string $label ): array {
		$core = $this->run( 'pgcore', $args );
		$nf   = $this->run( 'pgflag', $args );
		$this->assertSame( 'short-circuit', $nf['mode'], "$label: NF answered" );
		unset( $core['mode'], $nf['mode'] );
		$this->assertSame( $core, $nf, $label );
		return $nf;
	}

	/** @return array<string,array{0:array<string,mixed>}> */
	public function paginationCases(): array {
		return array(
			'page 1'                        => array( array( 'posts_per_page' => 5, 'paged' => 1 ) ),
			'page 2'                        => array( array( 'posts_per_page' => 5, 'paged' => 2 ) ),
			'last page'                     => array( array( 'posts_per_page' => 5, 'paged' => 4 ) ),
			'after the last page'           => array( array( 'posts_per_page' => 5, 'paged' => 5 ) ),
			'far past the last page'        => array( array( 'posts_per_page' => 5, 'paged' => 50 ) ),
			'exact multiple'                => array( array( 'posts_per_page' => 19, 'paged' => 1 ) ),
			'one per page'                  => array( array( 'posts_per_page' => 1, 'paged' => 7 ) ),
			'offset alone'                  => array( array( 'posts_per_page' => 5, 'offset' => 7 ) ),
			'offset with paged'             => array( array( 'posts_per_page' => 5, 'offset' => 7, 'paged' => 3 ) ),
			'offset past the end'           => array( array( 'posts_per_page' => 5, 'offset' => 40 ) ),
			'posts_per_page -1'             => array( array( 'posts_per_page' => -1 ) ),
			'nopaging'                      => array( array( 'nopaging' => true ) ),
			'no_found_rows'                 => array( array( 'posts_per_page' => 5, 'paged' => 2, 'no_found_rows' => true ) ),
			'descending, page 3'            => array( array( 'posts_per_page' => 4, 'paged' => 3, 'order' => 'DESC' ) ),
		);
	}

	/**
	 * @dataProvider paginationCases
	 * @param array<string,mixed> $args
	 */
	public function test_pagination_matches_core( array $args ): void {
		$this->assertParity( $args, wp_json_encode( $args ) );
	}

	public function test_default_posts_per_page_option_is_used(): void {
		update_option( 'posts_per_page', 6 );
		$nf = $this->assertParity( array(), 'default posts_per_page' );
		$this->assertCount( 6, $nf['ids'] );
		$this->assertSame( $this->matching, $nf['found'] );
	}

	public function test_page_past_the_end_is_empty_so_core_404s(): void {
		$nf = $this->assertParity( array( 'posts_per_page' => 5, 'paged' => 9 ), 'past the end' );
		$this->assertSame( array(), $nf['ids'] );
	}

	public function test_sticky_home_query_stays_with_core(): void {
		$sticky = $this->ids[1];
		update_option( 'sticky_posts', array( $sticky ) );
		// A bare post_type query parses as the home query, so core adds sticky posts after NF's IDs: NF must hand back.
		$q = new WP_Query( array_merge( $this->base( 'pgflag' ), array( 'posts_per_page' => 5 ) ) );
		$this->assertTrue( $q->is_home() );
		$this->assertNotSame( 'short-circuit', $q->get( 'nf_mode' ) );
		$q = new WP_Query( array_merge( $this->base( 'pgflag' ), array( 'posts_per_page' => 5, 'ignore_sticky_posts' => true ) ) );
		$this->assertSame( 'short-circuit', $q->get( 'nf_mode' ) );
		delete_option( 'sticky_posts' );
	}

	public function test_capped_count_below_at_and_above_the_cap(): void {
		$args = array( 'posts_per_page' => 5, 'paged' => 1, 'nf_count' => 'capped' );
		// Below the cap: the exact total.
		add_filter( 'nf/count/cap', static fn() => 1000 );
		$q = new WP_Query( array_merge( $this->base( 'pgflag' ), $args ) );
		$this->assertSame( $this->matching, (int) $q->found_posts );
		$this->assertEmpty( $q->get( 'nf_count_capped' ) );
		// At the cap: still exact.
		remove_all_filters( 'nf/count/cap' );
		$at = $this->matching;
		add_filter( 'nf/count/cap', static fn() => $at );
		$q = new WP_Query( array_merge( $this->base( 'pgflag' ), $args ) );
		$this->assertSame( $this->matching, (int) $q->found_posts );
		$this->assertEmpty( $q->get( 'nf_count_capped' ) );
		// Above the cap: cap plus one, and the flag.
		remove_all_filters( 'nf/count/cap' );
		add_filter( 'nf/count/cap', static fn() => 10 );
		$q = new WP_Query( array_merge( $this->base( 'pgflag' ), $args ) );
		$this->assertSame( 11, (int) $q->found_posts );
		$this->assertTrue( (bool) $q->get( 'nf_count_capped' ) );
		$this->assertSame( 3, (int) $q->max_num_pages );
		// A deep link past the cap gets the exact count, so no false 404.
		$q = new WP_Query( array_merge( $this->base( 'pgflag' ), $args, array( 'paged' => 4 ) ) );
		$this->assertSame( $this->matching, (int) $q->found_posts );
		$this->assertCount( $this->matching - 15, $q->posts );
	}

	/** @return array<string,mixed> */
	private function base( string $key ): array {
		return array(
			'post_type'  => self::TYPE,
			'fields'     => 'ids',
			'orderby'    => 'ID',
			'order'      => 'ASC',
			'meta_query' => array( array( 'key' => $key, 'value' => '1', 'compare' => '=', 'type' => 'NUMERIC' ) ),
		);
	}

	public function test_cursor_walk_matches_offset_walk(): void {
		$by_offset = array();
		for ( $p = 0; $p < 5; $p++ ) {
			$by_offset = array_merge(
				$by_offset,
				( new Builder( self::TYPE, $this->context ) )->where( 'pgflag', 1 )->orderBy( 'pgflag' )->limit( 4 )->offset( $p * 4 )->ids()
			);
		}
		$by_cursor = array();
		$cursor    = null;
		for ( $guard = 0; $guard < 10; $guard++ ) {
			$b = ( new Builder( self::TYPE, $this->context ) )->where( 'pgflag', 1 )->orderBy( 'pgflag' )->limit( 4 );
			if ( null !== $cursor ) {
				$b->after( $cursor );
			}
			$page = $b->ids();
			if ( array() === $page ) {
				break;
			}
			$by_cursor = array_merge( $by_cursor, $page );
			$cursor    = $b->cursor( (int) end( $page ) );
		}
		$this->assertCount( $this->matching, $by_offset );
		$this->assertSame( $by_offset, $by_cursor );
		$this->assertSame( $by_offset, array_values( array_unique( $by_offset ) ) );
	}
}
