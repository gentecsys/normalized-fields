<?php
/**
 * WP_Query argument translation tests (WP 3.3a).
 *
 * @package NF
 */

declare(strict_types=1);

// phpcs:disable Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid

use NF\Query\ArgsTranslator;
use NF\Storage\Status;

/**
 * Pure argument to spec translation.
 *
 * @covers \NF\Query\ArgsTranslator
 */
final class ArgsTranslatorTest extends WP_UnitTestCase {

	private function t( array $args ): ?\NF\Query\QuerySpec {
		return ( new ArgsTranslator( static fn( string $k ): bool => in_array( $k, array( 'price', 'start_date', 'is_featured' ), true ) ) )->translate( $args );
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

	public function test_order_by_meta_value(): void {
		$s = $this->t( array( 'post_type' => 'event', 'meta_key' => 'start_date', 'orderby' => 'meta_value', 'order' => 'ASC' ) );
		$this->assertSame( array( array( 'start_date', 'ASC' ) ), $s->orders );
		$this->assertSame( 'not_null', $s->conditions[0]->op );
	}

	/**
	 * @dataProvider untranslatable
	 */
	public function test_passes_through(array $args): void {
		$this->assertNull( $this->t( $args + array( 'post_type' => 'event' ) ) );
	}

	public static function untranslatable(): array {
		return array(
			'or relation'     => array( array( 'meta_query' => array( 'relation' => 'OR', array( 'key' => 'price', 'value' => 1 ) ) ) ),
			'nested'          => array( array( 'meta_query' => array( array( array( 'key' => 'price', 'value' => 1 ) ) ) ) ),
			'unmanaged key'   => array( array( 'meta_query' => array( array( 'key' => 'other', 'value' => 1 ) ) ) ),
			'like'            => array( array( 'meta_query' => array( array( 'key' => 'price', 'value' => 1, 'compare' => 'LIKE' ) ) ) ),
			'search'          => array( array( 's' => 'x' ) ),
			'tax_query'       => array( array( 'tax_query' => array( array( 'taxonomy' => 'genre', 'terms' => 1 ) ) ) ),
			'post__not_in'    => array( array( 'post__not_in' => array( 3 ) ) ),
			'orderby title'   => array( array( 'orderby' => 'title' ) ),
			'status any'      => array( array( 'post_status' => 'any' ) ),
			'post_type any'   => array( array( 'post_type' => 'any' ) ),
			'multi post type' => array( array( 'post_type' => array( 'a', 'b' ) ) ),
		);
	}
}
