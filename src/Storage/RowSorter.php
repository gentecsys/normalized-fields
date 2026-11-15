<?php
/**
 * Series row sorting.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Sorts Series rows by a sub-field, a linked post's post_date or a registered callback.
 *
 * Pure apart from the optional post-date lookup and the `nf/repeater/sort_rows` filter.
 * Ties keep the previous row order, so sorting is stable.
 */
final class RowSorter {

	/**
	 * Rules by field key.
	 *
	 * @var array<string,RowSortRule>
	 */
	private array $rules = array();

	/**
	 * Registered custom sorters.
	 *
	 * @var array<string,array{label:string,compare:callable}>
	 */
	private array $sorters = array();

	/**
	 * Post date lookup: post IDs in, post_date strings by ID out. Null uses wp_posts.
	 *
	 * @var (callable(int[]):array<int,string>)|null
	 */
	private $dates;

	/**
	 * Constructor.
	 *
	 * @param (callable(int[]):array<int,string>)|null $dates Post date lookup.
	 */
	public function __construct( ?callable $dates = null ) {
		$this->dates = $dates;
	}

	/**
	 * Set the rule of a Series (fed from the field's `nf_sort` setting).
	 *
	 * @param string           $fieldKey Series field key.
	 * @param RowSortRule|null $rule     Rule, or null to clear it.
	 * @return void
	 */
	public function setRule( string $fieldKey, ?RowSortRule $rule ): void {
		if ( null === $rule ) {
			unset( $this->rules[ $fieldKey ] );
			return;
		}
		$this->rules[ $fieldKey ] = $rule;
	}

	/**
	 * Rule of a Series.
	 *
	 * @param string $fieldKey Series field key.
	 * @return RowSortRule|null
	 */
	public function rule( string $fieldKey ): ?RowSortRule {
		return $this->rules[ $fieldKey ] ?? null;
	}

	/**
	 * Register a custom sorter (nf_register_row_sorter()).
	 *
	 * @param string              $name Sorter name; use it as `callback:<name>`.
	 * @param array<string,mixed> $args `label` and `compare` (callable( row, row ): int).
	 * @return bool False when the name is empty or `compare` is not callable.
	 */
	public function register( string $name, array $args ): bool {
		$name = sanitize_key( $name );
		if ( '' === $name || ! isset( $args['compare'] ) || ! is_callable( $args['compare'] ) ) {
			return false;
		}
		$this->sorters[ $name ] = array(
			'label'   => isset( $args['label'] ) ? (string) $args['label'] : $name,
			'compare' => $args['compare'],
		);
		return true;
	}

	/**
	 * Registered sorters, name => label.
	 *
	 * @return array<string,string>
	 */
	public function sorters(): array {
		return array_map( static fn( array $s ): string => $s['label'], $this->sorters );
	}

	/**
	 * Sort rows.
	 *
	 * @param array<int,array<string,mixed>> $rows     Rows keyed by sub-field name.
	 * @param RowSortRule                    $rule     Rule.
	 * @param string                         $fieldKey Series field key (for the filter).
	 * @param int                            $objectId Object ID (for the filter).
	 * @return array<int,array<string,mixed>> Sorted list.
	 */
	public function sort( array $rows, RowSortRule $rule, string $fieldKey = '', int $objectId = 0 ): array {
		$rows = array_values( $rows );
		if ( count( $rows ) > 1 ) {
			$rows = $this->apply( $rows, $rule );
		}
		/**
		 * Change or replace the sort of any Series.
		 *
		 * @param array<int,array<string,mixed>> $rows     Sorted rows.
		 * @param array<string,mixed>            $field    Field info (key).
		 * @param int                            $objectId Object ID.
		 * @param array<string,string>           $sort     The rule.
		 */
		$filtered = apply_filters( // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
			'nf/repeater/sort_rows',
			$rows,
			array( 'key' => $fieldKey ),
			$objectId,
			array(
				'mode'    => $rule->mode,
				'by'      => $rule->by,
				'order'   => $rule->order,
				'compare' => $rule->compare,
				'empty'   => $rule->blanks,
			)
		);
		return is_array( $filtered ) ? array_values( $filtered ) : $rows;
	}

	/**
	 * Apply the rule's comparison.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows (list).
	 * @param RowSortRule                    $rule Rule.
	 * @return array<int,array<string,mixed>>
	 */
	private function apply( array $rows, RowSortRule $rule ): array {
		$desc = 'desc' === $rule->order;
		if ( str_starts_with( $rule->by, 'callback:' ) ) {
			$name = sanitize_key( substr( $rule->by, 9 ) );
			if ( ! isset( $this->sorters[ $name ] ) ) {
				return $rows;
			}
			$cmp = $this->sorters[ $name ]['compare'];
			return $this->decorateSort(
				$rows,
				static fn( $a, $b ): int => (int) $cmp( $a, $b ),
				$desc
			);
		}
		if ( str_starts_with( $rule->by, 'post_date:' ) ) {
			$sub   = substr( $rule->by, 10 );
			$ids   = array_map( static fn( array $row ): int => self::firstId( $row[ $sub ] ?? null ), $rows );
			$dates = $this->postDates( array_values( array_unique( array_filter( $ids ) ) ) );
			$keys  = array();
			foreach ( $rows as $i => $row ) {
				$keys[ $i ] = $dates[ $ids[ $i ] ] ?? null;
			}
			return $this->sortByKeys( $rows, $keys, 'binary', $desc, $rule->blanks );
		}
		if ( str_starts_with( $rule->by, 'sub_field:' ) ) {
			$sub  = substr( $rule->by, 10 );
			$keys = array();
			foreach ( $rows as $i => $row ) {
				$keys[ $i ] = $row[ $sub ] ?? null;
			}
			return $this->sortByKeys( $rows, $keys, $rule->compare, $desc, $rule->blanks );
		}
		return $rows;
	}

	/**
	 * Sort with a row comparator, stable.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 * @param callable                       $cmp  Comparator.
	 * @param bool                           $desc Descending.
	 * @return array<int,array<string,mixed>>
	 */
	private function decorateSort( array $rows, callable $cmp, bool $desc ): array {
		$pairs = array();
		foreach ( $rows as $i => $row ) {
			$pairs[] = array( $i, $row );
		}
		usort(
			$pairs,
			static function ( array $a, array $b ) use ( $cmp, $desc ): int {
				$r = $cmp( $a[1], $b[1] );
				if ( $desc ) {
					$r = -$r;
				}
				return 0 !== $r ? $r : $a[0] <=> $b[0];
			}
		);
		return array_map( static fn( array $p ): array => $p[1], $pairs );
	}

	/**
	 * Sort rows by one key per row. Empty keys go first or last whatever the direction.
	 *
	 * @param array<int,array<string,mixed>> $rows    Rows.
	 * @param array<int,mixed>               $keys    Sort key per row index.
	 * @param string                         $compare locale | natural | binary.
	 * @param bool                           $desc    Descending.
	 * @param string                         $blanks  first | last.
	 * @return array<int,array<string,mixed>>
	 */
	private function sortByKeys( array $rows, array $keys, string $compare, bool $desc, string $blanks ): array {
		$collator = 'locale' === $compare && class_exists( \Collator::class ) ? new \Collator( determine_locale() ) : null;
		if ( null !== $collator ) {
			$collator->setAttribute( \Collator::NUMERIC_COLLATION, \Collator::ON );
		}
		$order = array_keys( $rows );
		usort(
			$order,
			function ( int $a, int $b ) use ( $keys, $compare, $desc, $blanks, $collator ): int {
				$ea = self::isEmpty( $keys[ $a ] );
				$eb = self::isEmpty( $keys[ $b ] );
				if ( $ea || $eb ) {
					if ( $ea && $eb ) {
						return $a <=> $b;
					}
					$first = 'first' === $blanks;
					return $ea === $first ? -1 : 1;
				}
				$r = self::compareValues( self::scalar( $keys[ $a ] ), self::scalar( $keys[ $b ] ), $compare, $collator );
				if ( $desc ) {
					$r = -$r;
				}
				return 0 !== $r ? $r : $a <=> $b;
			}
		);
		return array_map( static fn( int $i ): array => $rows[ $i ], $order );
	}

	/**
	 * Compare two non-empty scalars.
	 *
	 * @param string         $a        First.
	 * @param string         $b        Second.
	 * @param string         $compare  Mode.
	 * @param \Collator|null $collator Collator for locale mode.
	 * @return int
	 */
	private static function compareValues( string $a, string $b, string $compare, ?\Collator $collator ): int {
		if ( 'binary' === $compare ) {
			return strcmp( $a, $b ) <=> 0;
		}
		if ( 'locale' === $compare && null !== $collator ) {
			$r = $collator->compare( $a, $b );
			return false === $r ? 0 : $r;
		}
		return strnatcasecmp( $a, $b ) <=> 0;
	}

	/**
	 * Whether a sort key counts as empty.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function isEmpty( mixed $value ): bool {
		if ( is_array( $value ) ) {
			return array() === $value;
		}
		return null === $value || '' === $value;
	}

	/**
	 * Scalar form of a key (multi-valued sub-fields sort by their first value).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function scalar( mixed $value ): string {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * First post ID of a post object / relationship value.
	 *
	 * @param mixed $value Value.
	 * @return int
	 */
	private static function firstId( mixed $value ): int {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		return is_numeric( $value ) ? absint( $value ) : 0;
	}

	/**
	 * Post dates of the given posts in one query.
	 *
	 * @param int[] $ids Post IDs.
	 * @return array<int,string>
	 */
	private function postDates( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}
		if ( null !== $this->dates ) {
			return ( $this->dates )( $ids );
		}
		global $wpdb;
		$holders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- core table, placeholders built from the ID count.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_date FROM {$wpdb->posts} WHERE ID IN ($holders)", ...$ids ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['ID'] ] = (string) $row['post_date'];
		}
		return $out;
	}
}
