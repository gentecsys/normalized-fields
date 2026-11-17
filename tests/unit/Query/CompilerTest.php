<?php
/**
 * Compiler tests, pivot tier (WP 3.1a). Pure: no database, compares the compiled SQL and parameters.
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
use NF\Query\UnsupportedQueryException;
use NF\Storage\DataTable;
use NF\Storage\FieldDef;
use NF\Storage\ObjectType;
use NF\Storage\Status;
use PHPUnit\Framework\TestCase;

/**
 * Pivot-tier compiler.
 *
 * @covers \NF\Query\Compiler
 * @covers \NF\Query\CompiledQuery
 * @covers \NF\Query\Condition
 */
final class CompilerTest extends TestCase {

	/**
	 * Slots used by the tests.
	 *
	 * @var array<string,array{name:string,table:DataTable}>
	 */
	private const SLOTS = array(
		'i1' => array(
			'name'  => 'is_featured',
			'table' => DataTable::Int,
		),
		'n1' => array(
			'name'  => 'price',
			'table' => DataTable::Dec,
		),
		't1' => array(
			'name'  => 'start_date',
			'table' => DataTable::Date,
		),
		's1' => array(
			'name'  => 'title_text',
			'table' => DataTable::Str,
		),
	);

	/**
	 * Compiler on a stub MySQL backend.
	 *
	 * @return Compiler
	 */
	private function compiler(): Compiler {
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
		return new Compiler( $backend );
	}

	/**
	 * Filters, sort and page become one statement with bound values and a stable tie-break.
	 */
	public function test_filters_sort_and_limit(): void {
		$spec = new QuerySpec(
			ObjectType::Post,
			'event',
			array( Status::Publish ),
			array(
				new Condition( 'is_featured', '=', true ),
				new Condition( 'price', '<', '50' ),
				new Condition( 'start_date', '>=', '20261101' ),
			),
			array( array( 'start_date', 'ASC' ) ),
			20,
			40
		);
		$q    = $this->compiler()->compile( $spec, 7, self::SLOTS );
		$this->assertSame(
			'SELECT `object_id` FROM wp_nf_pivot WHERE `pivot_id` = ? AND `status` IN (?) AND `i1` = ? AND `n1` < ? AND `t1` >= ? ORDER BY `t1` IS NULL DESC, `t1` ASC, `object_id` ASC LIMIT 20 OFFSET 40',
			$q->sql
		);
		$this->assertSame( array( 7, 1, 1, '50', '2026-11-01 00:00:00' ), $q->params );
		$this->assertStringStartsWith( 'SELECT COUNT(*) AS c FROM wp_nf_pivot WHERE', $q->count_sql );
		$this->assertStringNotContainsString( 'LIMIT', $q->count_sql );
		$this->assertSame( 'pivot', $q->plan['mode'] );
		$this->assertStringContainsString( "'2026-11-01 00:00:00'", $q->toSql() );
	}

	/**
	 * Default order is date descending with nulls last and an object_id tie-break.
	 */
	public function test_default_order(): void {
		$q = $this->compiler()->compile( new QuerySpec( ObjectType::Post, 'event' ), 1, self::SLOTS );
		$this->assertStringContainsString( 'ORDER BY `published` IS NULL ASC, `published` DESC, `object_id` DESC', $q->sql );
		$this->assertSame( array( 1, 1 ), $q->params );
	}

	/**
	 * Set and null operators.
	 */
	public function test_set_operators(): void {
		$spec = new QuerySpec(
			ObjectType::Post,
			'event',
			array( Status::Publish, Status::Private ),
			array(
				new Condition( 'price', 'between', array( 10, '20.5' ) ),
				new Condition( 'author', 'in', array( 3, 4 ) ),
				new Condition( 'parent', 'not_in', array() ),
				new Condition( 'menu_order', 'in', array() ),
				new Condition( 'start_date', 'null' ),
				new Condition( 'is_featured', 'not_null' ),
			)
		);
		$q    = $this->compiler()->compile( $spec, 2, self::SLOTS );
		$this->assertStringContainsString( '`status` IN (?, ?) AND `n1` BETWEEN ? AND ? AND `author_id` IN (?, ?) AND 1 = 0 AND `t1` IS NULL AND `i1` IS NOT NULL', $q->sql );
		$this->assertSame( array( 2, 1, 4, '10', '20.5', 3, 4 ), $q->params );
	}

	/**
	 * Fields outside the pivot are refused, not guessed.
	 */
	public function test_unsupported_field_and_value(): void {
		$cases = array(
			new Condition( 'missing', '=', 1 ),
			new Condition( 'title_text', '=', 'a' ),
			new Condition( 'price', '=', 'abc' ),
			new Condition( 'is_featured', '=', '1.5' ),
			new Condition( 'start_date', '=', 'tomorrow' ),
			new Condition( 'price', '=', array( 1 ) ),
		);
		foreach ( $cases as $c ) {
			try {
				$this->compiler()->compile( new QuerySpec( ObjectType::Post, 'event', array(), array( $c ) ), 1, self::SLOTS );
				$this->fail( 'Expected UnsupportedQueryException for ' . $c->field );
			} catch ( UnsupportedQueryException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	/**
	 * Unknown operators are rejected at construction.
	 */
	public function test_unknown_operator(): void {
		$this->expectException( InvalidArgumentException::class );
		new Condition( 'price', 'LIKE', 1 );
	}

	/**
	 * Probe fields by name.
	 *
	 * @return array<string,FieldDef>
	 */
	private function fields(): array {
		return array(
			'venue'      => new FieldDef( 11, 'field_venue', 'venue', 0, DataTable::Int, true ),
			'tagline'    => new FieldDef( 12, 'field_tagline', 'tagline', 0, DataTable::Str, false ),
			'title_text' => new FieldDef( 13, 'field_tt', 'title_text', 0, DataTable::Str, false ),
			'bio'        => new FieldDef( 14, 'field_bio', 'bio', 0, DataTable::Text, false ),
		);
	}

	/**
	 * A reverse relationship is one semi-join probe by primary key, next to pivot conditions.
	 */
	public function test_probe_reverse_relationship(): void {
		$spec = new QuerySpec(
			ObjectType::Post,
			'event',
			array(),
			array( new Condition( 'venue', '=', 123 ), new Condition( 'is_featured', '=', 1 ) ),
			array( array( 'start_date', 'ASC' ) ),
			20
		);
		$q    = $this->compiler()->compile( $spec, 7, self::SLOTS, $this->fields() );
		$this->assertStringContainsString( 'AND `object_id` IN (SELECT `object_id` FROM wp_nf_int WHERE `object_type` = ? AND `field_id` = ? AND `value` = ?) AND `i1` = ?', $q->sql );
		$this->assertSame( array( 7, 1, 1, 11, 123, 1 ), $q->params );
		$this->assertSame( 'pivot+probe', $q->plan['mode'] );
		$this->assertSame( array( 'venue' ), $q->plan['probes'] );
		$this->assertSame( $q->params, $q->count_params );
	}

	/**
	 * Negations on multi-valued fields mean "no value matches"; null means no row.
	 */
	public function test_probe_negation_and_null(): void {
		$spec = new QuerySpec(
			ObjectType::Post,
			'event',
			array(),
			array( new Condition( 'venue', 'not_in', array( 1, 2 ) ), new Condition( 'tagline', 'null' ), new Condition( 'tagline', '!=', 'x' ) )
		);
		$q    = $this->compiler()->compile( $spec, 7, self::SLOTS, $this->fields() );
		$this->assertStringContainsString( '`object_id` NOT IN (SELECT `object_id` FROM wp_nf_int WHERE `object_type` = ? AND `field_id` = ? AND `value` IN (?, ?))', $q->sql );
		$this->assertStringContainsString( '`object_id` NOT IN (SELECT `object_id` FROM wp_nf_str WHERE `object_type` = ? AND `field_id` = ?)', $q->sql );
		$this->assertStringContainsString( '`object_id` IN (SELECT `object_id` FROM wp_nf_str WHERE `object_type` = ? AND `field_id` = ? AND `value` != ?)', $q->sql );
		$this->assertSame( array( 7, 1, 1, 11, 1, 2, 1, 12, 1, 12, 'x' ), $q->params );
	}

	/**
	 * A dictionary-slotted string field falls back to the str table; text and long strings are unsupported.
	 */
	public function test_probe_str_and_limits(): void {
		$c = $this->compiler();
		$q = $c->compile( new QuerySpec( ObjectType::Post, 'event', array(), array( new Condition( 'title_text', '=', 'Hi' ) ) ), 7, self::SLOTS, $this->fields() );
		$this->assertStringContainsString( 'FROM wp_nf_str', $q->sql );
		foreach ( array( new Condition( 'bio', '=', 'x' ), new Condition( 'tagline', '=', str_repeat( 'a', 192 ) ), new Condition( 'nope', '=', 1 ) ) as $cond ) {
			try {
				$c->compile( new QuerySpec( ObjectType::Post, 'event', array(), array( $cond ) ), 7, self::SLOTS, $this->fields() );
				$this->fail( 'Expected UnsupportedQueryException for ' . $cond->field );
			} catch ( UnsupportedQueryException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}
}
