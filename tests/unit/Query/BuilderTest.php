<?php
/**
 * Builder tests (WP 3.2a). Pure: builds specs only.
 *
 * @package NF
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

use NF\Query\Builder;
use NF\Query\RowBuilder;
use NF\Storage\ObjectType;
use NF\Storage\Status;
use PHPUnit\Framework\TestCase;

/**
 * Fluent builder.
 *
 * @covers \NF\Query\Builder
 * @covers \NF\Query\RowBuilder
 */
final class BuilderTest extends TestCase {

	public function test_targets(): void {
		$s = ( new Builder( 'event' ) )->toSpec();
		$this->assertSame( ObjectType::Post, $s->type );
		$this->assertSame( 'event', $s->subtype );
		$s = ( new Builder( 'term:genre' ) )->toSpec();
		$this->assertSame( ObjectType::Term, $s->type );
		$this->assertSame( 'genre', $s->subtype );
		$this->assertSame( ObjectType::User, ( new Builder( 'user' ) )->toSpec()->type );
	}

	public function test_empty_target_throws(): void {
		$this->expectException( InvalidArgumentException::class );
		new Builder( 'term:' );
	}

	public function test_design_example_builds_the_expected_spec(): void {
		$s = ( new Builder( 'event' ) )
			->status( 'publish' )
			->where( 'is_featured', true )
			->where( 'start_date', '>=', '2026-11-01' )
			->where( 'price', '<', 50 )
			->whereAny( 'category', array( 'music', 'food' ) )
			->whereRow(
				'sessions',
				fn( RowBuilder $row ) => $row
					->whereBetween( 'session_date', array( '2026-11-03 18:00', '2026-11-04' ) )
					->where( 'speaker', 42 )
			)
			->orderBy( 'start_date' )->orderBy( 'title', 'desc' )
			->paginate( 20, 3, 'capped' )
			->toSpec();

		$this->assertSame( array( Status::Publish ), $s->statuses );
		$this->assertCount( 3, $s->conditions );
		$this->assertSame( '=', $s->conditions[0]->op );
		$this->assertSame( '>=', $s->conditions[1]->op );
		$this->assertSame( 'any', $s->masks[0]->mode );
		$this->assertSame( 'sessions', $s->rows[0]->series );
		$this->assertSame( 'between', $s->rows[0]->conditions[0]->op );
		$this->assertSame( '=', $s->rows[0]->conditions[1]->op );
		$this->assertSame( array( array( 'start_date', 'ASC' ), array( 'title', 'DESC' ) ), $s->orders );
		$this->assertSame( 20, $s->limit );
		$this->assertSame( 40, $s->offset );
	}

	public function test_where_between_equals_where_with_between_operator(): void {
		$a = ( new Builder( 'event' ) )->whereBetween( 'price', array( 10, 20 ) )->toSpec();
		$b = ( new Builder( 'event' ) )->where( 'price', 'between', array( 10, 20 ) )->toSpec();
		$this->assertEquals( $a, $b );
		$this->assertSame( array( 10, 20 ), $a->conditions[0]->value );
	}

	public function test_row_where_between_equals_row_where_with_between_operator(): void {
		$a = ( new Builder( 'event' ) )->whereRow( 'sessions', fn( RowBuilder $r ) => $r->whereBetween( 'd', array( 'a', 'b' ) ) )->toSpec();
		$b = ( new Builder( 'event' ) )->whereRow( 'sessions', fn( RowBuilder $r ) => $r->where( 'd', 'between', array( 'a', 'b' ) ) )->toSpec();
		$this->assertEquals( $a, $b );
	}

	/**
	 * Ranges that are not exactly two values.
	 *
	 * @dataProvider bad_between
	 */
	public function test_between_needs_exactly_two_values( array $range ): void {
		$this->expectException( InvalidArgumentException::class );
		( new Builder( 'event' ) )->whereBetween( 'price', $range );
	}

	public static function bad_between(): array {
		return array( array( array() ), array( array( 1 ) ), array( array( 1, 2, 3 ) ) );
	}

	public function test_row_between_needs_exactly_two_values(): void {
		$this->expectException( InvalidArgumentException::class );
		( new RowBuilder() )->whereBetween( 'd', array( 1 ) );
	}

	public function test_two_argument_where(): void {
		$c = ( new Builder( 'event' ) )->where( 'a', 5 )->where( 'b', null )->toSpec()->conditions;
		$this->assertSame( '=', $c[0]->op );
		$this->assertSame( 5, $c[0]->value );
		$this->assertSame( 'null', $c[1]->op );
	}

	public function test_invalid_input_throws(): void {
		$bad = array(
			fn( Builder $b ) => $b->where( 'a', 'like', 'x' ),
			fn( Builder $b ) => $b->where( 'a', 'in', array() ),
			fn( Builder $b ) => $b->where( 'a', '=', array( 1 ) ),
			fn( Builder $b ) => $b->where( '', 1 ),
			fn( Builder $b ) => $b->whereAny( 'a', array() ),
			fn( Builder $b ) => $b->whereRow( 'sessions', fn( RowBuilder $r ) => $r ),
			fn( Builder $b ) => $b->status( 'bogus' ),
			fn( Builder $b ) => $b->orderBy( 'a', 'sideways' ),
			fn( Builder $b ) => $b->whereBetween( 'a', array( null, 5 ) ),
			fn( Builder $b ) => $b->where( 'a', 'in', array( array( 1 ), 2 ) ),
			fn( Builder $b ) => $b->where( 'a', '=', null ),
			fn( Builder $b ) => $b->status( array() ),
			fn( Builder $b ) => $b->limit( -1 ),
			fn( Builder $b ) => $b->offset( -1 ),
			fn( Builder $b ) => $b->paginate( 0 ),
			fn( Builder $b ) => $b->paginate( 10, 1, 'approx' ),
		);
		foreach ( $bad as $i => $fn ) {
			try {
				$fn( new Builder( 'event' ) );
				$this->fail( "Case $i did not throw." );
			} catch ( InvalidArgumentException $e ) {
				$this->assertInstanceOf( InvalidArgumentException::class, $e );
			}
		}
	}

	public function test_status_list_and_page_below_one(): void {
		$b = ( new Builder( 'event' ) )->status( array( 'publish', Status::Draft ) )->paginate( 10, 0 );
		$s = $b->toSpec();
		$this->assertSame( array( Status::Publish, Status::Draft ), $s->statuses );
		$this->assertSame( 0, $s->offset );
		$this->assertSame( 'exact', $b->countMode() );
	}

	public function test_membership_modes_and_stringified_values(): void {
		$m = ( new Builder( 'event' ) )->whereAll( 'a', array( 1, 2 ) )->whereNone( 'b', array( 'x' ) )->toSpec()->masks;
		$this->assertSame( 'all', $m[0]->mode );
		$this->assertSame( array( '1', '2' ), $m[0]->values );
		$this->assertSame( 'none', $m[1]->mode );
	}
}
