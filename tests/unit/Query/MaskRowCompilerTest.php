<?php
/**
 * Compiler tests, bitmask and same-row conditions (WP 3.1c). Pure: no database.
 *
 * @package NF
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

use NF\Backend\BackendInterface;
use NF\Backend\DialectInterface;
use NF\Backend\MysqlDialect;
use NF\Pivot\Dictionary;
use NF\Query\Compiler;
use NF\Query\Condition;
use NF\Query\MaskCondition;
use NF\Query\QuerySpec;
use NF\Query\RowCondition;
use NF\Query\UnsupportedQueryException;
use NF\Storage\DataTable;
use NF\Storage\FieldDef;
use NF\Storage\ObjectType;
use PHPUnit\Framework\TestCase;

/**
 * Bitmask slots, probe fallback and nf_row_pivot EXISTS.
 *
 * @covers \NF\Query\Compiler
 * @covers \NF\Query\MaskCondition
 * @covers \NF\Query\RowCondition
 */
final class MaskRowCompilerTest extends TestCase {

	private const SLOTS = array(
		'i1' => array(
			'name'  => 'is_featured',
			'table' => DataTable::Int,
			'id'    => 1,
		),
		'm1' => array(
			'name'  => 'category',
			'table' => DataTable::Str,
			'id'    => 20,
		),
	);

	/**
	 * Dictionary entries: "field_id|value" => id, bit.
	 *
	 * @var array<string,array{0:int,1:int|null}>
	 */
	public const DICT = array(
		'20|music' => array( 1, 0 ),
		'20|food'  => array( 2, 1 ),
		'20|top'   => array( 3, 63 ),
		'30|main'  => array( 4, null ),
		'30|side'  => array( 5, null ),
	);

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
				if ( str_starts_with( $sql, 'SELECT COUNT(*)' ) ) {
					return array( array( 'c' => 2 ) ); // Bits in use for the field: far below 64.
				}
				$e = MaskRowCompilerTest::DICT[ $params[0] . '|' . $params[1] ] ?? null;
				if ( null === $e ) {
					return array();
				}
				if ( str_starts_with( $sql, 'SELECT bit' ) ) {
					return null === $e[1] ? array() : array( array( 'bit' => $e[1] ) );
				}
				return array( array( 'dict_id' => $e[0] ) );
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
		return new Compiler( $backend, new Dictionary( $backend ) );
	}

	/**
	 * Row-pivot slots of the Series.
	 *
	 * @return array<string,array{id:int,slots:array<string,array{name:string,table:DataTable,id:int}>}>
	 */
	private function rowSlots(): array {
		return array(
			'sessions' => array(
				'id'    => 9,
				'slots' => array(
					't1' => array(
						'name'  => 'starts',
						'table' => DataTable::Date,
						'id'    => 31,
					),
					's1' => array(
						'name'  => 'venue',
						'table' => DataTable::Str,
						'id'    => 30,
					),
					'i1' => array(
						'name'  => 'seats',
						'table' => DataTable::Int,
						'id'    => 32,
					),
				),
			),
		);
	}

	private function spec( array $masks = array(), array $rows = array() ): QuerySpec {
		return new QuerySpec( ObjectType::Post, 'event', array(), array(), array(), 0, 0, $masks, $rows );
	}

	/**
	 * Any, all and none are one bitwise test on the slot; unknown values are handled per mode.
	 */
	public function test_bitmask_modes(): void {
		$c = $this->compiler();
		$q = $c->compile( $this->spec( array( new MaskCondition( 'category', 'any', array( 'music', 'food', 'nope' ) ) ) ), 7, self::SLOTS );
		$this->assertStringContainsString( 'AND (`m1` & 3) <> 0 ORDER BY', $q->sql );
		$this->assertSame( array( 7, 1 ), $q->params );
		$q = $c->compile( $this->spec( array( new MaskCondition( 'category', 'all', array( 'music', 'food' ) ) ) ), 7, self::SLOTS );
		$this->assertStringContainsString( '(`m1` & 3) = 3', $q->sql );
		$q = $c->compile( $this->spec( array( new MaskCondition( 'category', 'none', array( 'food', 'nope' ) ) ) ), 7, self::SLOTS );
		$this->assertStringContainsString( '(`m1` & 2) = 0', $q->sql );
		$q = $c->compile( $this->spec( array( new MaskCondition( 'category', 'all', array( 'music', 'nope' ) ) ) ), 7, self::SLOTS );
		$this->assertStringContainsString( '1 = 0', $q->sql );
		$q = $c->compile( $this->spec( array( new MaskCondition( 'category', 'any', array( 'nope' ) ) ) ), 7, self::SLOTS );
		$this->assertStringContainsString( '1 = 0', $q->sql );
		$q = $c->compile( $this->spec( array( new MaskCondition( 'category', 'none', array( 'nope' ) ) ) ), 7, self::SLOTS );
		$this->assertStringNotContainsString( '&', $q->sql );
		$q = $c->compile( $this->spec( array( new MaskCondition( 'category', 'all', array() ) ) ), 7, self::SLOTS );
		$this->assertStringNotContainsString( '&', $q->sql );
		$this->assertSame( 'pivot', $q->plan['mode'] );
	}

	/**
	 * Bit 63 works for any/none; all-of on it is refused (signed literal vs unsigned column).
	 */
	public function test_bit_63(): void {
		$c = $this->compiler();
		$q = $c->compile( $this->spec( array( new MaskCondition( 'category', 'any', array( 'top' ) ) ) ), 7, self::SLOTS );
		$this->assertStringContainsString( '& ' . PHP_INT_MIN . ')', $q->sql );
		$this->expectException( UnsupportedQueryException::class );
		$c->compile( $this->spec( array( new MaskCondition( 'category', 'all', array( 'top' ) ) ) ), 7, self::SLOTS );
	}

	/**
	 * Without a bitmask slot a multi-valued field falls back to typed-table probes.
	 */
	public function test_mask_probe_fallback(): void {
		$fields = array(
			'venue' => new FieldDef( 11, 'field_venue', 'venue', 0, DataTable::Int, true ),
			'one'   => new FieldDef( 12, 'field_one', 'one', 0, DataTable::Int, false ),
		);
		$c      = $this->compiler();
		$q      = $c->compile( $this->spec( array( new MaskCondition( 'venue', 'any', array( 1, 2 ) ) ) ), 7, self::SLOTS, $fields );
		$this->assertStringContainsString( '`object_id` IN (SELECT `object_id` FROM wp_nf_int WHERE `object_type` = ? AND `field_id` = ? AND `value` IN (?, ?))', $q->sql );
		$this->assertSame( 'pivot+probe', $q->plan['mode'] );
		$q = $c->compile( $this->spec( array( new MaskCondition( 'venue', 'all', array( 1, 2 ) ) ) ), 7, self::SLOTS, $fields );
		$this->assertSame( 2, substr_count( $q->sql, 'IN (SELECT' ) );
		$this->assertSame( array( 7, 1, 1, 11, 1, 1, 11, 2 ), $q->params );
		$q = $c->compile( $this->spec( array( new MaskCondition( 'venue', 'none', array( 1 ) ) ) ), 7, self::SLOTS, $fields );
		$this->assertStringContainsString( '`object_id` NOT IN (SELECT', $q->sql );
		$this->expectException( UnsupportedQueryException::class );
		$c->compile( $this->spec( array( new MaskCondition( 'one', 'any', array( 1 ) ) ) ), 7, self::SLOTS, $fields );
	}

	/**
	 * Same-row conditions are one correlated EXISTS on nf_row_pivot; strings go through the dictionary.
	 */
	public function test_where_row(): void {
		$row = new RowCondition(
			'sessions',
			array( new Condition( 'starts', '>=', '2026-11-03 18:00' ), new Condition( 'starts', '<', '2026-11-04' ), new Condition( 'venue', '=', 'main' ), new Condition( 'seats', '>', 0 ) )
		);
		$q   = $this->compiler()->compile( $this->spec( array(), array( $row ) ), 7, self::SLOTS, array(), $this->rowSlots() );
		$this->assertStringContainsString(
			'AND EXISTS (SELECT 1 FROM wp_nf_row_pivot WHERE `pivot_id` = ? AND `field_id` = ? AND `object_id` = wp_nf_pivot.`object_id` AND `t1` >= ? AND `t1` < ? AND `s1` IN (?) AND `i1` > ?) ORDER BY',
			$q->sql
		);
		$this->assertSame( array( 7, 1, 7, 9, '2026-11-03 18:00:00', '2026-11-04 00:00:00', 4, 0 ), $q->params );
		$this->assertSame( $q->params, $q->count_params );
		$this->assertSame( array( 'sessions' ), $q->plan['rows'] );
	}

	/**
	 * Unknown dictionary values match no row for equality, and every row with a value for negation.
	 */
	public function test_row_unknown_strings_and_errors(): void {
		$c = $this->compiler();
		$q = $c->compile( $this->spec( array(), array( new RowCondition( 'sessions', array( new Condition( 'venue', '=', 'ghost' ) ) ) ) ), 7, self::SLOTS, array(), $this->rowSlots() );
		$this->assertStringContainsString( 'AND 1 = 0)', $q->sql );
		$q = $c->compile( $this->spec( array(), array( new RowCondition( 'sessions', array( new Condition( 'venue', 'not_in', array( 'ghost', 'main' ) ) ) ) ) ), 7, self::SLOTS, array(), $this->rowSlots() );
		$this->assertStringContainsString( '`s1` NOT IN (?)', $q->sql );
		foreach ( array(
			new RowCondition( 'nope', array( new Condition( 'starts', '=', '2026-01-01' ) ) ),
			new RowCondition( 'sessions', array( new Condition( 'missing', '=', 1 ) ) ),
			new RowCondition( 'sessions', array( new Condition( 'venue', '>', 'a' ) ) ),
		) as $bad ) {
			try {
				$c->compile( $this->spec( array(), array( $bad ) ), 7, self::SLOTS, array(), $this->rowSlots() );
				$this->fail( 'Expected UnsupportedQueryException.' );
			} catch ( UnsupportedQueryException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		try {
			$c->compile( $this->spec( array(), array( new RowCondition( 'sessions', array( new Condition( 'venue', 'in', array_fill( 0, 1001, 'x' ) ) ) ) ) ), 7, self::SLOTS, array(), $this->rowSlots() );
			$this->fail( 'Expected a list-length error.' );
		} catch ( UnsupportedQueryException $e ) {
			$this->assertSame( 'List too long.', $e->getMessage() );
		}
		$this->expectException( InvalidArgumentException::class );
		new RowCondition( 'sessions', array() );
	}
}
