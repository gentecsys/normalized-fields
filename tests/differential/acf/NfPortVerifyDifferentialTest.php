<?php
/**
 * Port verify (WP 2.6d) through real ACF: values saved with update_field() and mirrored by the bridge verify
 * with zero mismatches, including date and time pickers, Series rows and a sorted Series; a changed NF value
 * is still reported. An importer that writes arrays into a text field with update_post_meta() ports and
 * verifies clean, and get_field() keeps returning the array (found at G2).
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

use NF\Install\Schema;
use NF\Migration\AcfCopier;
use NF\Migration\PortVerifier;
use NF\Plugin;
use NF\Storage\ObjectType;
use NF\Storage\RowSortRule;
use PHPUnit\Framework\TestCase;

final class NfPortVerifyDifferentialTest extends TestCase {

	private const SERIES = 'field_nfpv_rows';

	/**
	 * Post under test.
	 *
	 * @var int
	 */
	private static int $id = 0;

	public static function setUpBeforeClass(): void {
		Schema::install();
		wp_set_current_user( 1 );
		acf_add_local_field_group(
			array(
				'key'      => 'group_nfpv',
				'title'    => 'NF port verify differential',
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'post',
						),
					),
				),
				'fields'   => array(
					array(
						'key'  => 'field_nfpv_title',
						'name' => 'nfpv_title',
						'type' => 'text',
					),
					array(
						'key'            => 'field_nfpv_date',
						'name'           => 'nfpv_date',
						'type'           => 'date_picker',
						'return_format'  => 'd/m/Y',
						'display_format' => 'd/m/Y',
					),
					array(
						'key'            => 'field_nfpv_time',
						'name'           => 'nfpv_time',
						'type'           => 'time_picker',
						'return_format'  => 'g:i a',
						'display_format' => 'g:i a',
					),
					array(
						'key'        => self::SERIES,
						'name'       => 'nfpv_rows',
						'type'       => 'repeater',
						'sub_fields' => array(
							array(
								'key'  => 'field_nfpv_rows_date',
								'name' => 'date',
								'type' => 'date_picker',
							),
							array(
								'key'  => 'field_nfpv_rows_time',
								'name' => 'time',
								'type' => 'time_picker',
							),
							array(
								'key'  => 'field_nfpv_rows_room',
								'name' => 'room',
								'type' => 'text',
							),
						),
					),
					array(
						'key'        => 'field_nfpv_grp',
						'name'       => 'nfpv_grp',
						'type'       => 'group',
						'sub_fields' => array(
							array(
								'key'  => 'field_nfpv_grp_day',
								'name' => 'day',
								'type' => 'date_picker',
							),
							array(
								'key'  => 'field_nfpv_grp_note',
								'name' => 'note',
								'type' => 'text',
							),
						),
					),
				),
			)
		);
		self::$id = (int) wp_insert_post(
			array(
				'post_title'  => 'nfpv',
				'post_status' => 'publish',
			)
		);
	}

	public static function tearDownAfterClass(): void {
		Plugin::sorter()->setRule( self::SERIES, null );
		wp_delete_post( self::$id, true );
		acf_remove_local_field_group( 'group_nfpv' );
	}

	/**
	 * Saves every field through ACF.
	 */
	private function save(): void {
		update_field( 'field_nfpv_title', 'Opening', self::$id );
		update_field( 'field_nfpv_date', '20260130', self::$id );
		update_field( 'field_nfpv_time', '08:30:00', self::$id );
		update_field(
			self::SERIES,
			array(
				array(
					'field_nfpv_rows_date' => '20260131',
					'field_nfpv_rows_time' => '20:15:00',
					'field_nfpv_rows_room' => 'Room 18',
				),
				array(
					'field_nfpv_rows_date' => '20260130',
					'field_nfpv_rows_time' => '08:30:00',
					'field_nfpv_rows_room' => 'Room 3',
				),
			),
			self::$id
		);
		update_field(
			'field_nfpv_grp',
			array(
				'field_nfpv_grp_day'  => '20260201',
				'field_nfpv_grp_note' => 'Hall B',
			),
			self::$id
		);
	}

	/**
	 * The verifier as the job builds it.
	 */
	private function verifier(): PortVerifier {
		return new PortVerifier( Plugin::store(), Plugin::fields(), null, null, Plugin::sorter() );
	}

	/**
	 * Top-level ACF fields of the group.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function fields(): array {
		return acf_get_fields( 'group_nfpv' );
	}

	public function test_bridged_values_verify_clean(): void {
		$this->save();
		$this->assertSame( array(), $this->verifier()->verify( self::$id, $this->fields() ) );
	}

	public function test_sorted_series_verifies_clean(): void {
		Plugin::sorter()->setRule( self::SERIES, new RowSortRule( RowSortRule::ON_SAVE, 'sub_field:room', 'asc', 'natural' ) );
		try {
			$this->save();
			$rows = Plugin::store()->getRows( ObjectType::Post, self::$id, self::SERIES );
			$this->assertSame( 'Room 3', $rows[0]['room'], 'on_save sort applied' );
			$this->assertSame( array(), $this->verifier()->verify( self::$id, $this->fields() ) );
		} finally {
			Plugin::sorter()->setRule( self::SERIES, null );
		}
	}

	public function test_array_in_text_field_ports_and_verifies(): void {
		$websites = array(
			array(
				'business' => 'https://example.com/',
				'booking'  => '',
			),
		);
		// What such an importer does: raw meta plus ACF's reference, no update_field().
		update_post_meta( self::$id, 'nfpv_title', $websites );
		update_post_meta( self::$id, '_nfpv_title', 'field_nfpv_title' );
		update_post_meta( self::$id, 'nfpv_date', array( array() ) );
		update_post_meta( self::$id, '_nfpv_date', 'field_nfpv_date' );
		// Earlier tests saved through ACF in this request; drop ACF's per-request value cache, as a new request would.
		acf_get_store( 'values' )->reset();

		$copier = new AcfCopier( Plugin::bridge() );
		foreach ( $this->fields() as $field ) {
			$copier->copy( self::$id, $field );
		}
		$copier->flush();

		$this->assertSame( $websites, Plugin::store()->get( ObjectType::Post, self::$id, 'field_nfpv_title' ) );
		$this->assertSame( array( array() ), Plugin::store()->get( ObjectType::Post, self::$id, 'field_nfpv_date' ) );
		$this->assertSame( $websites, get_field( 'nfpv_title', self::$id ) );
		$this->assertSame( array(), $this->verifier()->verify( self::$id, $this->fields() ) );
	}

	public function test_changed_nf_values_are_reported(): void {
		$this->save();
		$rows            = Plugin::store()->getRows( ObjectType::Post, self::$id, self::SERIES );
		$rows[1]['room'] = 'Room 99';
		Plugin::store()->setRows( ObjectType::Post, self::$id, self::SERIES, $rows );
		Plugin::store()->set( ObjectType::Post, self::$id, 'field_nfpv_date', '2026-01-29 00:00:00' );

		$out = $this->verifier()->verify( self::$id, $this->fields() );
		$this->assertSame( array( 'nfpv_date', 'nfpv_rows' ), array_column( $out, 'field' ) );
		$this->assertSame( array( 'nfpv_rows[1].room' ), $out[1]['paths'] );
	}
}
