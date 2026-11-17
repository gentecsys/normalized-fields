<?php
/**
 * Planner tests (WP 3.1d). Pure: statistics in, SQL and plan out.
 *
 * @package NF
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

use NF\Backend\BackendInterface;
use NF\Backend\DialectInterface;
use NF\Backend\MysqlDialect;
use NF\Query\Compiler;
use NF\Query\Condition;
use NF\Query\QuerySpec;
use NF\Storage\DataTable;
use NF\Storage\ObjectType;
use NF\Storage\Status;
use PHPUnit\Framework\TestCase;

/**
 * Selectivity ordering, drivers, deferred join and explain data.
 *
 * @covers \NF\Query\Compiler
 */
final class PlannerTest extends TestCase {

	private const SLOTS = array(
		'i1' => array(
			'name'  => 'is_featured',
			'table' => DataTable::Int,
		),
		'i2' => array(
			'name'  => 'venue',
			'table' => DataTable::Int,
		),
		't1' => array(
			'name'  => 'start_date',
			'table' => DataTable::Date,
		),
	);

	private const STATS = array(
		'*'           => array(
			'rows'     => 100000,
			'distinct' => 0,
		),
		'is_featured' => array(
			'rows'     => 100000,
			'distinct' => 2,
		),
		'venue'       => array(
			'rows'     => 100000,
			'distinct' => 500,
		),
	);

	private function compiler( int $defer = Compiler::DEFER_OFFSET ): Compiler {
		$backend = new class() implements BackendInterface {
			public function name(): string {
				return 'mysql';
			}
			public function dialect(): DialectInterface {
				return new MysqlDialect();
			}
			public function table( string $logical ): string {
				return 'wp_' . $logical;
			}
			public function select( string $sql, array $params = array() ): array {
				return array();
			}
			public function execute( string $sql, array $params = array() ): int {
				return 0;
			}
			public function insertId(): int {
				return 0;
			}
			public function transaction( callable $callback ): mixed {
				return $callback();
			}
			public function ping(): bool {
				return true;
			}
			public function tryLock( string $name ): bool {
				return true;
			}
			public function unlock( string $name ): void {}
		};
		return new Compiler( $backend, null, $defer );
	}

	public function test_selective_condition_goes_first_and_drives_the_scan(): void {
		$spec = new QuerySpec(
			ObjectType::Post,
			'event',
			array( Status::Publish ),
			array( new Condition( 'is_featured', '=', 1 ), new Condition( 'venue', '=', 7 ) ),
			array( array( 'start_date', 'ASC' ) ),
			20
		);
		$q    = $this->compiler()->compile( $spec, 1, self::SLOTS, array(), array(), self::STATS );
		$this->assertStringContainsString( 'FROM wp_nf_pivot FORCE INDEX (`i2`) WHERE', $q->sql );
		$this->assertLessThan( strpos( $q->sql, '`i1` =' ), strpos( $q->sql, '`i2` =' ) );
		$this->assertSame( array( 1, 1, 7, 1 ), $q->params );
		$this->assertSame( 'i2 (venue)', $q->plan['driver'] );
		$this->assertSame( array( 'venue', 'is_featured' ), $q->plan['clause_order'] );
		$this->assertSame( 100, $q->plan['estimated_rows'] );
		$this->assertSame( 0, $q->plan['joins'] );
	}

	public function test_low_selectivity_uses_the_sort_index_with_limit(): void {
		$spec = new QuerySpec( ObjectType::Post, 'event', array(), array( new Condition( 'is_featured', '=', 1 ) ), array( array( 'start_date', 'ASC' ) ), 20 );
		$q    = $this->compiler()->compile( $spec, 1, self::SLOTS, array(), array(), self::STATS );
		$this->assertStringContainsString( 'FORCE INDEX (`t1`)', $q->sql );
		$this->assertSame( 't1 (sort: start_date)', $q->plan['driver'] );
	}

	public function test_default_date_sort_uses_published_index(): void {
		$spec = new QuerySpec( ObjectType::Post, 'event', array(), array(), array(), 10 );
		$q    = $this->compiler()->compile( $spec, 1, self::SLOTS );
		$this->assertStringContainsString( 'FORCE INDEX (`by_published`)', $q->sql );
	}

	public function test_no_index_hint_without_a_limit(): void {
		$spec = new QuerySpec( ObjectType::Post, 'event', array(), array(), array( array( 'start_date', 'ASC' ) ) );
		$q    = $this->compiler()->compile( $spec, 1, self::SLOTS );
		$this->assertStringNotContainsString( 'FORCE INDEX', $q->sql );
	}

	public function test_deep_offset_uses_a_deferred_join(): void {
		$spec = new QuerySpec( ObjectType::Post, 'event', array(), array( new Condition( 'is_featured', '=', 1 ) ), array( array( 'start_date', 'ASC' ) ), 20, 2000 );
		$q    = $this->compiler()->compile( $spec, 1, self::SLOTS, array(), array(), self::STATS );
		$this->assertTrue( $q->plan['deferred_join'] );
		$this->assertSame( 1, $q->plan['joins'] );
		$this->assertStringContainsString( 'INNER JOIN (SELECT `pivot_id` AS kp, `object_id` AS ko FROM wp_nf_pivot FORCE INDEX', $q->sql );
		$this->assertStringContainsString( 'LIMIT 20 OFFSET 2000) AS x ON `pivot_id` = x.kp AND `object_id` = x.ko ORDER BY', $q->sql );
		$this->assertSame( array( 1, 1, 1 ), $q->params );
		$this->assertStringNotContainsString( 'LIMIT', $q->count_sql );
	}

	public function test_offset_at_the_threshold_is_not_deferred(): void {
		$spec = new QuerySpec( ObjectType::Post, 'event', array(), array(), array(), 20, 1000 );
		$q    = $this->compiler()->compile( $spec, 1, self::SLOTS );
		$this->assertFalse( $q->plan['deferred_join'] );
		$spec = new QuerySpec( ObjectType::Post, 'event', array(), array(), array(), 20, 51 );
		$q    = $this->compiler( 50 )->compile( $spec, 1, self::SLOTS );
		$this->assertTrue( $q->plan['deferred_join'] );
	}

	public function test_without_statistics_clause_order_is_the_callers(): void {
		$spec = new QuerySpec( ObjectType::Post, 'event', array(), array( new Condition( 'is_featured', '=', 1 ), new Condition( 'venue', '=', 7 ) ) );
		$q    = $this->compiler()->compile( $spec, 1, self::SLOTS );
		$this->assertSame( array( 'is_featured', 'venue' ), $q->plan['clause_order'] );
		$this->assertNull( $q->plan['estimated_rows'] );
	}
}
