<?php
/**
 * Reads ACF's post type, taxonomy and options page definitions (WP 2.6c2).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Migration;

defined( 'ABSPATH' ) || exit;

/**
 * ACF 6.1+ keeps these definitions as `acf-post-type`, `acf-taxonomy` and `acf-ui-options-page` posts whose
 * content is a serialized array. Core tables, so `$wpdb` (ADR-0009). Nothing is written.
 */
class DefinitionReader {

	/**
	 * Internal post types, by section.
	 *
	 * @var array<string,string>
	 */
	public const TYPES = array(
		'post_types'    => 'acf-post-type',
		'taxonomies'    => 'acf-taxonomy',
		'options_pages' => 'acf-ui-options-page',
	);

	/**
	 * Read all three lists.
	 *
	 * @return array{post_types:array<int,array<string,mixed>>,taxonomies:array<int,array<string,mixed>>,options_pages:array<int,array<string,mixed>>}
	 */
	public function read(): array {
		return array(
			'post_types'    => $this->section( self::TYPES['post_types'] ),
			'taxonomies'    => $this->section( self::TYPES['taxonomies'] ),
			'options_pages' => $this->section( self::TYPES['options_pages'] ),
		);
	}

	/**
	 * Definitions of one internal post type.
	 *
	 * @param string $type Internal post type.
	 * @return array<int,array<string,mixed>>
	 */
	private function section( string $type ): array {
		global $wpdb;
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT ID, post_content, post_status FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','acf-disabled') ORDER BY ID ASC", $type ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $row ) {
			$data = self::decode( (string) $row['post_content'] );
			if ( null === $data ) {
				continue;
			}
			$data['active'] = 'publish' === $row['post_status'] && ( ! isset( $data['active'] ) || (bool) $data['active'] );
			$out[]          = $data;
		}
		return $out;
	}

	/**
	 * Decode ACF's serialized post content, refusing objects.
	 *
	 * @param string $content Post content.
	 * @return array<string,mixed>|null Null when it is not a serialized array.
	 */
	public static function decode( string $content ): ?array {
		if ( '' === $content || ! is_serialized( $content ) ) {
			return null;
		}
		$data = @unserialize( $content, array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
		return is_array( $data ) ? $data : null;
	}
}
