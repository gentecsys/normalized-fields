<?php
/**
 * Deep comparison of two normalized values, reporting every differing path.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

final class Comparator {

	/**
	 * @param string $scenario Scenario name.
	 * @param string $step     Step label.
	 * @param mixed  $left     Result from the left target.
	 * @param mixed  $right    Result from the right target.
	 * @return Diff[]
	 */
	public function compare( string $scenario, string $step, mixed $left, mixed $right ): array {
		$diffs = array();
		$this->walk( $scenario, $step, '', Normalizer::normalize( $left ), Normalizer::normalize( $right ), $diffs );
		return $diffs;
	}

	/**
	 * @param string $scenario Scenario name.
	 * @param string $step     Step label.
	 * @param string $path     Current path.
	 * @param mixed  $left     Left value.
	 * @param mixed  $right    Right value.
	 * @param Diff[] $diffs    Collected differences.
	 */
	private function walk( string $scenario, string $step, string $path, mixed $left, mixed $right, array &$diffs ): void {
		if ( is_array( $left ) && is_array( $right ) ) {
			foreach ( array_unique( array_merge( array_keys( $left ), array_keys( $right ) ) ) as $key ) {
				$is_list = array_is_list( $left ) && array_is_list( $right );
				$child   = $is_list ? $path . '[' . $key . ']' : ( '' === $path ? (string) $key : $path . '.' . $key );
				if ( ! array_key_exists( $key, $left ) || ! array_key_exists( $key, $right ) ) {
					$diffs[] = new Diff( $scenario, $step, $child, $left[ $key ] ?? '<missing>', $right[ $key ] ?? '<missing>' );
					continue;
				}
				$this->walk( $scenario, $step, $child, $left[ $key ], $right[ $key ], $diffs );
			}
			return;
		}
		if ( $left !== $right ) {
			$diffs[] = new Diff( $scenario, $step, $path, $left, $right );
		}
	}
}
