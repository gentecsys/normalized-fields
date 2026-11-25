<?php
/**
 * Core-parity decimal cast rewriting tests (WP 3.3a2).
 *
 * @package NF
 */

declare(strict_types=1);

// phpcs:disable WordPress.DB.SlowDBQuery,Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.FunctionComment.MissingParamTag,WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid,WordPress.Arrays.ArrayDeclarationSpacing,Generic.Commenting.DocComment.MissingShort

use NF\Query\ArgsTranslator;
use NF\Query\Condition;
use NF\Query\DecimalCast;

/**
 * Brute-force check of the cast rewrite against core's cast of each stored value.
 *
 * @covers \NF\Query\DecimalCast
 * @covers \NF\Query\ArgsTranslator
 */
final class DecimalCastTest extends WP_UnitTestCase {

	/** @return string[] Stored values, whole, fractional and negative. */
	private function values(): array {
		$out = array();
		for ( $i = -600; $i <= 600; $i += 5 ) {
			$out[] = number_format( $i / 100, 2, '.', '' );
		}
		return $out;
	}

	private function cast( string $v, string $mode, int $scale ): float {
		$f = (float) $v;
		return 'trunc' === $mode ? (float) (int) $f : round( $f, $scale, PHP_ROUND_HALF_UP );
	}

	private function cond_holds( Condition $c, string $v ): bool {
		$a = (float) $v;
		$b = (float) $c->value;
		return match ( $c->op ) {
			'>'  => $a > $b,
			'>=' => $a >= $b,
			'<'  => $a < $b,
			'<=' => $a <= $b,
			default => false,
		};
	}

	public function test_rewrite_equals_core_cast_for_every_cast_and_operator(): void {
		$clauses = array( '0', '1', '-1', '2', '-2', '2.5', '-2.5', '0.4', '-0.4', '3.01', '-3.01', '1.5', '4.99', '0.5', '-0.5' );
		foreach ( array( array( 'trunc', 0 ), array( 'round', 0 ), array( 'round', 1 ), array( 'round', 2 ) ) as [ $mode, $scale ] ) {
			foreach ( $clauses as $c ) {
				foreach ( array( '=', '<', '<=', '>', '>=' ) as $op ) {
					$conds = DecimalCast::conditions( 'p', $op, $c, $mode, $scale );
					foreach ( $this->values() as $v ) {
						$cv   = $this->cast( $v, $mode, $scale );
						$want = match ( $op ) {
							'='  => abs( $cv - (float) $c ) < 1e-9,
							'<'  => $cv < (float) $c - 1e-9,
							'<=' => $cv <= (float) $c + 1e-9,
							'>'  => $cv > (float) $c + 1e-9,
							'>=' => $cv >= (float) $c - 1e-9,
						};
						if ( null === $conds ) {
							$this->assertSame( '=', $op, "$mode/$scale $op $c" );
							$this->assertFalse( $want, "off-grid = must match nothing: $mode/$scale $c $v" );
							continue;
						}
						$got = true;
						foreach ( $conds as $cond ) {
							$got = $got && $this->cond_holds( $cond, $v );
						}
						$this->assertSame( $want, $got, "$mode/$scale cast($v) $op $c" );
					}
				}
			}
		}
	}

	public function test_between_uses_cast_values(): void {
		foreach ( array( array( 'trunc', 0 ), array( 'round', 0 ), array( 'round', 1 ) ) as [ $mode, $scale ] ) {
			foreach ( array( array( '-2.5', '1.5' ), array( '0.4', '3.6' ), array( '1', '2' ) ) as [ $lo, $hi ] ) {
				$conds = DecimalCast::conditions( 'p', 'between', array( $lo, $hi ), $mode, $scale );
				foreach ( $this->values() as $v ) {
					$cv   = $this->cast( $v, $mode, $scale );
					$want = $cv >= (float) $lo - 1e-9 && $cv <= (float) $hi + 1e-9;
					$got  = true;
					foreach ( $conds as $cond ) {
						$got = $got && $this->cond_holds( $cond, $v );
					}
					$this->assertSame( $want, $got, "$mode/$scale cast($v) between $lo and $hi" );
				}
			}
		}
	}

	public function test_unreproducible_casts_and_operators(): void {
		$this->assertNull( DecimalCast::parseType( 'UNSIGNED' ) );
		$this->assertNull( DecimalCast::parseType( 'BINARY' ) );
		$this->assertSame( array( 'round', 2 ), DecimalCast::parseType( 'DECIMAL(10,2)' ) );
		$this->assertNull( DecimalCast::conditions( 'p', '!=', '1', 'trunc', 0 ) );
		$this->assertNull( DecimalCast::conditions( 'p', 'in', array( '1', '2' ), 'trunc', 0 ) );
	}

	private function t( array $args, ?bool $exact = false ): ?\NF\Query\QuerySpec {
		return ( new ArgsTranslator( static fn( string $k ): ?string => 'price' === $k ? 'decimal' : null, false, $exact ) )->translate( $args );
	}

	public function test_translator_default_is_core_parity(): void {
		$s = $this->t( array( 'post_type' => 'item', 'meta_query' => array( array( 'key' => 'price', 'value' => '250.5', 'compare' => '>=', 'type' => 'NUMERIC' ) ) ) );
		$this->assertSame( '>=', $s->conditions[0]->op );
		$this->assertSame( '251.0', (string) $s->conditions[0]->value ); // trunc(v) >= 251 means v >= 251.
	}

	public function test_translator_exact_mode_keeps_stored_values(): void {
		$s = $this->t( array( 'post_type' => 'item', 'meta_query' => array( array( 'key' => 'price', 'value' => '250.5', 'compare' => '>=', 'type' => 'NUMERIC' ) ) ), true );
		$this->assertSame( '250.5', $s->conditions[0]->value );
		$s = $this->t( array( 'post_type' => 'item', 'meta_query' => array( array( 'key' => 'price', 'value' => array( '1.5', '2' ), 'compare' => 'IN', 'type' => 'DECIMAL(10,2)' ) ) ), true );
		$this->assertSame( 'in', $s->conditions[0]->op );
	}

	public function test_translator_fails_closed_where_cast_is_unreproducible(): void {
		$base = array( 'post_type' => 'item' );
		$q    = static fn( string $type, string $cmp = '>=' ): array => $base + array( 'meta_query' => array( array( 'key' => 'price', 'value' => '5', 'compare' => $cmp, 'type' => $type ) ) );
		$this->assertNull( $this->t( $q( 'UNSIGNED' ) ) );
		$this->assertNull( $this->t( $q( 'BINARY' ) ) );
		$this->assertNull( $this->t( $q( '' ) ) );
		$this->assertNull( $this->t( $q( 'NUMERIC', '!=' ) ) );
		$this->assertNull( $this->t( $q( 'BINARY' ), true ) );
	}

	public function test_meta_value_num_order_is_exact_and_meta_value_is_not(): void {
		$s = $this->t( array( 'post_type' => 'item', 'meta_key' => 'price', 'orderby' => 'meta_value_num', 'order' => 'ASC' ) );
		$this->assertSame( array( array( 'price', 'ASC' ) ), $s->orders );
		$this->assertNull( $this->t( array( 'post_type' => 'item', 'meta_key' => 'price', 'orderby' => 'meta_value' ) ) );
	}
}
