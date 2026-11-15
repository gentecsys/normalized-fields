<?php
/**
 * Converts ACF internal-post definitions to NF JSON sections (WP 2.6c2).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Migration;

/**
 * Pure: takes the arrays ACF stores for its post types, taxonomies and options pages, and returns the
 * `post_types`, `taxonomies` and `options_pages` sections of NF JSON (`contracts/nf-json.schema.json`).
 * Database ids and timestamps are dropped; inactive definitions are kept with `active` false so a re-port is stable.
 */
final class DefinitionPorter {

	/**
	 * ACF-internal keys that carry no definition.
	 *
	 * @var string[]
	 */
	private const DROP = array( 'ID', 'menu_order', 'modified', 'post_status', 'local', 'local_file' );

	/**
	 * Convert the three definition lists.
	 *
	 * @param array<int,mixed> $post_types    ACF post type definitions.
	 * @param array<int,mixed> $taxonomies    ACF taxonomy definitions.
	 * @param array<int,mixed> $options_pages ACF UI options page definitions.
	 * @return array{post_types:array<int,array<string,mixed>>,taxonomies:array<int,array<string,mixed>>,options_pages:array<int,array<string,mixed>>,counts:array<string,int>}
	 */
	public function port( array $post_types, array $taxonomies, array $options_pages ): array {
		$sections = array(
			'post_types'    => $this->clean( $post_types, 'post_type' ),
			'taxonomies'    => $this->clean( $taxonomies, 'taxonomy' ),
			'options_pages' => $this->clean( $options_pages, 'menu_slug' ),
		);
		$counts   = array(
			'post_types'    => count( $sections['post_types'] ),
			'taxonomies'    => count( $sections['taxonomies'] ),
			'options_pages' => count( $sections['options_pages'] ),
			'skipped'       => ( count( $post_types ) - count( $sections['post_types'] ) )
				+ ( count( $taxonomies ) - count( $sections['taxonomies'] ) )
				+ ( count( $options_pages ) - count( $sections['options_pages'] ) ),
		);
		return $sections + array( 'counts' => $counts );
	}

	/**
	 * Drop internals, skip entries with no identifier, sort by identifier.
	 *
	 * @param array<int,mixed> $items Definitions.
	 * @param string           $id    Identifier key.
	 * @return array<int,array<string,mixed>>
	 */
	private function clean( array $items, string $id ): array {
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || empty( $item[ $id ] ) || ! is_string( $item[ $id ] ) ) {
				continue;
			}
			$item['active'] = ! isset( $item['active'] ) || (bool) $item['active'];
			$out[]          = array_diff_key( $item, array_flip( self::DROP ) );
		}
		usort(
			$out,
			static fn( array $a, array $b ): int => strcmp( (string) $a[ $id ], (string) $b[ $id ] )
		);
		return $out;
	}
}
