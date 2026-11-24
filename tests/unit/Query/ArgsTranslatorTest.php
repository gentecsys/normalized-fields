<?php
/**
 * WP_Query argument translation tests (WP 3.3a).
 *
 * @package NF
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.Arrays.ArrayDeclarationSpacing,WordPress.DB.SlowDBQuery,Generic.Commenting.DocComment.MissingShort

use NF\Query\ArgsTranslator;
use NF\Storage\Status;

/**
 * Pure argument to spec translation.
 *
 * @covers \NF\Query\ArgsTranslator
 */
final class ArgsTranslatorTest extends WP_UnitTestCase {

	private function t( array $args ): ?\NF\Query\QuerySpec {
		$kinds = array(
			'price'       => 'number',
			'start_date'  => 'date',
			'is_featured' => 'text',
			'artists'     => 'related',
		);
		return ( new ArgsTranslator( static fn( string $k ): ?string => $kinds[ $k ] ?? null ) )->translate( $args );
	}

	public function test_basic_paging_and_defaults(): void {
		$s = $this->t( array( 'post_type' => 'event', 'posts_per_page' => 20, 'paged' => 3 ) );
		$this->assertSame( 'event', $s->subtype );
		$this->assertSame( array( Status::Publish ), $s->statuses );
		$this->assertSame( 20, $s->limit );
		$this->assertSame( 40, $s->offset );
		$this->assertSame( array( array( 'date', 'DESC' ) ), $s->orders );
	}

	public function test_offset_overrides_paged_and_nopaging(): void {
		$s = $this->t( array( 'post_type' => 'event', 'posts_per_page' => 10, 'paged' => 5, 'offset' => 3 ) );
		$this->assertSame( 3, $s->offset );
		$this->assertSame( 0, $this->t( array( 'post_type' => 'event', 'posts_per_page' => -1 ) )->limit );
		$this->assertSame( 0, $this->t( array( 'post_type' => 'event', 'nopaging' => true, 'paged' => 4 ) )->offset );
	}

	public function test_offset_zero_takes_precedence_over_paged_and_login_gate(): void {
		$s = $this->t( array( 'post_type' => 'event', 'posts_per_page' => 10, 'paged' => 3, 'offset' => 0 ) );
		$this->assertSame( 0, $s->offset );
		$s = $this->t( array( 'post_type' => 'event', 'posts_per_page' => 10, 'paged' => 3, 'offset' => '' ) );
		$this->assertSame( 20, $s->offset );
		$translator = new ArgsTranslator( static fn(): ?string => null, true );
		$this->assertNull( $translator->translate( array( 'post_type' => 'event' ) ) );
	}

	public function test_meta_query_operators(): void {
		$s = $this->t(
			array(
				'post_type'  => 'event',
				'meta_query' => array(
					'relation' => 'AND',
					array( 'key' => 'is_featured', 'value' => 1 ),
					array( 'key' => 'price', 'value' => array( 10, 50 ), 'compare' => 'BETWEEN', 'type' => 'NUMERIC' ),
					array( 'key' => 'start_date', 'compare' => 'EXISTS' ),
				),
			)
		);
		$this->assertCount( 3, $s->conditions );
		$this->assertSame( '=', $s->conditions[0]->op );
		$this->assertSame( 'between', $s->conditions[1]->op );
		$this->assertSame( 'not_null', $s->conditions[2]->op );
	}

	public function test_review_round_three_pass_through_cases(): void {
		$base = array( 'post_type' => 'event' );
		$this->assertNull( $this->t( $base + array( 'meta_key' => 'start_date', 'meta_value' => false ) ) );
		$this->assertNull( $this->t( $base + array( 'author' => '1.5' ) ) );
		$this->assertNull( $this->t( $base + array( 'post_parent' => '2.5' ) ) );
		$date = static fn( $v ) => array( 'post_type' => 'event', 'meta_query' => array( array( 'key' => 'start_date', 'value' => $v, 'compare' => '>=' ) ) );
		$this->assertNull( $this->t( $date( '2024-01-05' ) ) );
		$this->assertNotNull( $this->t( $date( '20240105' ) ) );
	}

	public function test_order_by_meta_value(): void {
		$s = $this->t( array( 'post_type' => 'event', 'meta_key' => 'start_date', 'orderby' => 'meta_value', 'order' => 'ASC' ) );
		$this->assertSame( array( array( 'start_date', 'ASC' ) ), $s->orders );
		$this->assertSame( 'not_null', $s->conditions[0]->op );
	}

	/**
	 * Untranslatable arguments pass through.
	 *
	 * @dataProvider untranslatable
	 */
	public function test_passes_through( array $args ): void {
		$this->assertNull( $this->t( $args + array( 'post_type' => 'event' ) ) );
	}

	public static function untranslatable(): array {
		return array(
			'or relation'      => array( array( 'meta_query' => array( 'relation' => 'OR', array( 'key' => 'price', 'value' => 1 ) ) ) ),
			'nested'           => array( array( 'meta_query' => array( array( array( 'key' => 'price', 'value' => 1 ) ) ) ) ),
			'unmanaged key'    => array( array( 'meta_query' => array( array( 'key' => 'other', 'value' => 1 ) ) ) ),
			'like'             => array( array( 'meta_query' => array( array( 'key' => 'price', 'value' => 1, 'compare' => 'LIKE' ) ) ) ),
			'search'           => array( array( 's' => 'x' ) ),
			'tax_query'        => array( array( 'tax_query' => array( array( 'taxonomy' => 'genre', 'terms' => 1 ) ) ) ),
			'post__in and not' => array( array( 'post__in' => array( 1 ), 'post__not_in' => array( 3 ) ) ),
			'post__in text'    => array( array( 'post__in' => array( 'abc' ) ) ),
			'post__in zero'    => array( array( 'post__in' => array( 0 ) ) ),
			'post__in scalar'  => array( array( 'post__in' => 5 ) ),
			'author in + not'  => array( array( 'author__in' => array( 1 ), 'author__not_in' => array( 2 ) ) ),
			'author + in'      => array( array( 'author' => 3, 'author__in' => array( 5 ) ) ),
			'author + not in'  => array( array( 'author' => 3, 'author__not_in' => array( 2 ) ) ),
			'date top column'  => array( array( 'date_query' => array( 'column' => 'post_modified', array( 'after' => '2020-01-01 00:00:00' ) ) ) ),
			'date top inclusive' => array( array( 'date_query' => array( 'inclusive' => true, array( 'after' => '2020-01-01 00:00:00' ) ) ) ),
			'date only'        => array( array( 'date_query' => array( array( 'after' => '2020-01-01' ) ) ) ),
			'date year'        => array( array( 'date_query' => array( array( 'year' => 2020 ) ) ) ),
			'date or'          => array( array( 'date_query' => array( 'relation' => 'OR', array( 'after' => '2020-01-01 00:00:00' ) ) ) ),
			'date gmt column'  => array( array( 'date_query' => array( array( 'after' => '2020-01-01 00:00:00', 'column' => 'post_date_gmt' ) ) ) ),
			'date impossible'  => array( array( 'date_query' => array( array( 'after' => '2020-02-31 00:00:00' ) ) ) ),
			'orderby title'    => array( array( 'orderby' => 'title' ) ),
			'status any'       => array( array( 'post_status' => 'any' ) ),
			'post_type any'    => array( array( 'post_type' => 'any' ) ),
			'meta_value 0'     => array( array( 'meta_key' => 'price', 'meta_value' => 0 ) ),
			'menu_order 0'     => array( array( 'menu_order' => 0 ) ),
			'meta_type'        => array( array( 'meta_key' => 'price', 'meta_type' => 'NUMERIC' ) ),
			'untyped number'   => array( array( 'meta_query' => array( array( 'key' => 'price', 'value' => 5, 'compare' => '<' ) ) ) ),
			'typed text'       => array( array( 'meta_query' => array( array( 'key' => 'is_featured', 'value' => 1, 'type' => 'NUMERIC' ) ) ) ),
			'compare_key'      => array( array( 'meta_query' => array( array( 'key' => 'is_featured', 'value' => 1, 'compare_key' => 'LIKE' ) ) ) ),
			'text less than'   => array( array( 'meta_query' => array( array( 'key' => 'is_featured', 'value' => 'b', 'compare' => '<' ) ) ) ),
			'text between'     => array( array( 'meta_query' => array( array( 'key' => 'is_featured', 'value' => array( 'a', 'c' ), 'compare' => 'BETWEEN' ) ) ) ),
			'text meta_value'  => array( array( 'meta_key' => 'is_featured', 'orderby' => 'meta_value' ) ),
			'num sort on text' => array( array( 'meta_key' => 'is_featured', 'orderby' => 'meta_value_num' ) ),
			'archive page'     => array( array( 'posts_per_archive_page' => 5 ) ),
			'negative paged'   => array( array( 'paged' => -3 ) ),
			'bad offset'       => array( array( 'offset' => 'abc' ) ),
			'draft status'     => array( array( 'post_status' => 'draft' ) ),
			'negative parent'  => array( array( 'post_parent' => -5 ) ),
			'has_password'     => array( array( 'has_password' => false ) ),
			'multi post type'  => array( array( 'post_type' => array( 'a', 'b' ) ) ),
		);
	}

	public function test_relationship_like_becomes_membership(): void {
		$s = $this->t( array( 'post_type' => 'event', 'meta_query' => array( array( 'key' => 'artists', 'value' => '"123"', 'compare' => 'LIKE' ) ) ) );
		$this->assertCount( 1, $s->masks );
		$this->assertSame( 'artists', $s->masks[0]->field );
		$this->assertSame( 'any', $s->masks[0]->mode );
		$this->assertSame( array( '123' ), $s->masks[0]->values );
		$this->assertSame( array(), $s->conditions );
	}

	public function test_like_that_is_not_an_id_probe_passes_through(): void {
		foreach ( array( '123', '"12a"', '"0"', '%"123"%', array( '"1"' ) ) as $v ) {
			$this->assertNull( $this->t( array( 'post_type' => 'event', 'meta_query' => array( array( 'key' => 'artists', 'value' => $v, 'compare' => 'LIKE' ) ) ) ) );
		}
		$this->assertNull( $this->t( array( 'post_type' => 'event', 'meta_query' => array( array( 'key' => 'artists', 'value' => '"1"', 'compare' => 'NOT LIKE' ) ) ) ) );
		$this->assertNull( $this->t( array( 'post_type' => 'event', 'meta_query' => array( array( 'key' => 'is_featured', 'value' => '"1"', 'compare' => 'LIKE' ) ) ) ) );
		$this->assertNull( $this->t( array( 'post_type' => 'event', 'meta_query' => array( array( 'key' => 'artists', 'value' => '"1"', 'compare' => 'LIKE', 'type' => 'NUMERIC' ) ) ) ) );
	}

	public function test_id_and_author_lists(): void {
		$spec = $this->t( array( 'post_type' => 'event', 'post__in' => array( '7', 3, 7 ), 'author__not_in' => array( 2 ) ) );
		$this->assertNotNull( $spec );
		$this->assertEquals( array( new \NF\Query\Condition( 'ID', 'in', array( 7, 3 ) ), new \NF\Query\Condition( 'author', 'not_in', array( 2 ) ) ), $spec->conditions );
		$spec = $this->t( array( 'post_type' => 'event', 'post__not_in' => array( 9 ), 'author__in' => array( 1, 4 ) ) );
		$this->assertNotNull( $spec );
		$this->assertEquals( array( new \NF\Query\Condition( 'ID', 'not_in', array( 9 ) ), new \NF\Query\Condition( 'author', 'in', array( 1, 4 ) ) ), $spec->conditions );
		$this->assertNotNull( $this->t( array( 'post_type' => 'event', 'post__in' => array(), 'author__in' => '' ) ) );
	}

	public function test_date_query_full_timestamps(): void {
		$spec = $this->t( array( 'post_type' => 'event', 'date_query' => array( array( 'after' => '2020-01-01 00:00:00', 'before' => '2020-12-31 23:59:59', 'inclusive' => true ) ) ) );
		$this->assertNotNull( $spec );
		$this->assertEquals( array( new \NF\Query\Condition( 'date', '>=', '2020-01-01 00:00:00' ), new \NF\Query\Condition( 'date', '<=', '2020-12-31 23:59:59' ) ), $spec->conditions );
		$spec = $this->t( array( 'post_type' => 'event', 'date_query' => array( 'after' => '2020-01-01 00:00:00' ) ) );
		$this->assertEquals( array( new \NF\Query\Condition( 'date', '>', '2020-01-01 00:00:00' ) ), $spec->conditions );
	}

	public function test_nf_argument(): void {
		$s = $this->t(
			array(
				'post_type' => 'event',
				'nf'        => array(
					'row'     => array( 'sessions' => array( array( 'session_date', 'between', array( '20261103', '20261104' ) ) ) ),
					'related' => array( 'artists' => array( 5, 6 ) ),
					'orderby' => array( 'start_date' => 'asc' ),
					'count'   => 'capped',
				),
			)
		);
		$this->assertSame( 'sessions', $s->rows[0]->series );
		$this->assertSame( 'between', $s->rows[0]->conditions[0]->op );
		$this->assertSame( array( '5', '6' ), $s->masks[0]->values );
		$this->assertSame( array( array( 'start_date', 'ASC' ) ), $s->orders );
	}

	public function test_nf_argument_rejects_what_it_does_not_understand(): void {
		$bad = array(
			array( 'bogus' => 1 ),
			array( 'related' => array( 'price' => 5 ) ),
			array( 'related' => array( 'artists' => 0 ) ),
			array( 'row' => array( 'sessions' => array() ) ),
			array( 'row' => array( 'sessions' => array( array( 'd', 'LIKE', 'x' ) ) ) ),
			array( 'orderby' => array( 'unknown' => 'ASC' ) ),
			array( 'orderby' => array( 'price' => 'SIDEWAYS' ) ),
			array( 'count' => 'estimated' ),
		);
		foreach ( $bad as $nf ) {
			$this->assertNull( $this->t( array( 'post_type' => 'event', 'nf' => $nf ) ) );
		}
		$this->assertNull( $this->t( array( 'post_type' => 'event', 'nf' => 'x' ) ) );
	}
}
