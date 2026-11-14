<?php
/**
 * Dry-run scan of ACF field groups (WP 2.6a).
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Migration;

/**
 * Turns ACF field-group arrays into a per-collection report. Pure: it reads nothing from WordPress, so callers pass
 * what `acf_get_field_groups()` and `acf_get_fields()` returned. Nothing is written.
 */
final class Scanner {

	/**
	 * ACF types NF knows how to store.
	 *
	 * @var string[]
	 */
	public const KNOWN_TYPES = array(
		'text',
		'textarea',
		'number',
		'range',
		'email',
		'url',
		'password',
		'image',
		'file',
		'wysiwyg',
		'oembed',
		'gallery',
		'select',
		'checkbox',
		'radio',
		'button_group',
		'true_false',
		'link',
		'post_object',
		'page_link',
		'relationship',
		'taxonomy',
		'user',
		'google_map',
		'date_picker',
		'date_time_picker',
		'time_picker',
		'color_picker',
		'message',
		'accordion',
		'tab',
		'group',
		'repeater',
		'clone',
		'icon_picker',
	);

	/**
	 * Scan field groups.
	 *
	 * @param array<int,array<string,mixed>> $groups ACF field groups, each with a `fields` list (nested `sub_fields` allowed).
	 * @return array{collections:array<int,array<string,mixed>>,totals:array<string,int>}
	 */
	public function scan( array $groups ): array {
		$collections = array();
		$totals      = array(
			'collections' => 0,
			'portable'    => 0,
			'skipped'     => 0,
			'fields'      => 0,
		);
		foreach ( $groups as $group ) {
			$stats  = array(
				'types'    => array(),
				'fields'   => 0,
				'unknown'  => array(),
				'flexible' => array(),
				'series'   => 0,
				'clones'   => 0,
				'depth'    => 0,
			);
			$fields = isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : array();
			$this->walk( $fields, 1, $stats );
			ksort( $stats['types'] );

			$skipped       = array() !== $stats['flexible'];
			$collections[] = array(
				'key'      => (string) ( $group['key'] ?? '' ),
				'title'    => (string) ( $group['title'] ?? '' ),
				'status'   => $skipped ? 'skipped' : 'portable',
				'reason'   => $skipped ? 'flexible_content' : '',
				'fields'   => $stats['fields'],
				'types'    => $stats['types'],
				'unknown'  => array_values( array_unique( $stats['unknown'] ) ),
				'flexible' => $stats['flexible'],
				'series'   => $stats['series'],
				'clones'   => $stats['clones'],
				'depth'    => $stats['depth'],
			);
			++$totals['collections'];
			++$totals[ $skipped ? 'skipped' : 'portable' ];
			$totals['fields'] += $stats['fields'];
		}
		return array(
			'collections' => $collections,
			'totals'      => $totals,
		);
	}

	/**
	 * Walk a field list.
	 *
	 * @param array<int,mixed>    $fields Fields.
	 * @param int                 $depth  Nesting depth, 1 at the top.
	 * @param array<string,mixed> $stats  Accumulator.
	 */
	private function walk( array $fields, int $depth, array &$stats ): void {
		$stats['depth'] = max( $stats['depth'], $depth );
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$type = (string) ( $field['type'] ?? '' );
			if ( '' === $type ) {
				$type = 'invalid';
			}
			++$stats['fields'];
			$stats['types'][ $type ] = ( $stats['types'][ $type ] ?? 0 ) + 1;

			if ( 'flexible_content' === $type ) {
				$stats['flexible'][] = (string) ( $field['name'] ?? $field['key'] ?? '' );
			} elseif ( 'invalid' !== $type && ! in_array( $type, self::KNOWN_TYPES, true ) ) {
				$stats['unknown'][] = $type;
			}
			if ( 'repeater' === $type ) {
				++$stats['series'];
			}
			if ( 'clone' === $type ) {
				++$stats['clones'];
			}
			if ( isset( $field['sub_fields'] ) && is_array( $field['sub_fields'] ) ) {
				$this->walk( $field['sub_fields'], $depth + 1, $stats );
			}
			if ( isset( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
				foreach ( $field['layouts'] as $layout ) {
					if ( is_array( $layout ) && isset( $layout['sub_fields'] ) && is_array( $layout['sub_fields'] ) ) {
						$this->walk( $layout['sub_fields'], $depth + 1, $stats );
					}
				}
			}
		}
	}
}
