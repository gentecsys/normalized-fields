<?php
/**
 * Group gaps through real ACF: a group sub-field saved on its own (update_field( 'group_sub' ))
 * reaches NF, and clones inside a group (seamless and group-display) are stored with the group.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

use NF\Install\Schema;
use NF\Plugin;
use NF\Storage\ObjectType;
use PHPUnit\Framework\TestCase;

final class NfGroupGapsDifferentialTest extends TestCase {

	/**
	 * Post under test.
	 *
	 * @var int
	 */
	private int $id = 0;

	/**
	 * Bridge errors seen during a test.
	 *
	 * @var string[]
	 */
	private array $errors = array();

	public static function setUpBeforeClass(): void {
		Schema::install();
		wp_set_current_user( 1 );
		acf_add_local_field_group(
			array(
				'key'      => 'group_nfggsrc',
				'title'    => 'NF group gaps source',
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => 'nf_never',
						),
					),
				),
				'fields'   => array(
					array(
						'key'  => 'field_nfggsrc_label',
						'name' => 'label',
						'type' => 'text',
					),
				),
			)
		);
		acf_add_local_field_group(
			array(
				'key'      => 'group_nfgg',
				'title'    => 'NF group gaps',
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
						'key'        => 'field_nfgg_box',
						'name'       => 'nfgg_box',
						'type'       => 'group',
						'sub_fields' => array(
							array(
								'key'  => 'field_nfgg_box_note',
								'name' => 'note',
								'type' => 'text',
							),
							array(
								'key'        => 'field_nfgg_box_inner',
								'name'       => 'inner',
								'type'       => 'group',
								'sub_fields' => array(
									array(
										'key'  => 'field_nfgg_box_inner_deep',
										'name' => 'deep',
										'type' => 'text',
									),
								),
							),
							array(
								'key'         => 'field_nfgg_box_seam',
								'name'        => 'seam',
								'type'        => 'clone',
								'clone'       => array( 'group_nfggsrc' ),
								'display'     => 'seamless',
								'prefix_name' => 1,
							),
							array(
								'key'         => 'field_nfgg_box_gclone',
								'name'        => 'gclone',
								'type'        => 'clone',
								'clone'       => array( 'group_nfggsrc' ),
								'display'     => 'group',
								'prefix_name' => 0,
							),
						),
					),
				),
			)
		);
	}

	public static function tearDownAfterClass(): void {
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field\\_nfgg%' ) );
		wp_cache_flush();
	}

	protected function setUp(): void {
		Plugin::bridge()->flush(); // Re-syncs queued by earlier test classes are not this test's.
		$this->errors = array();
		add_action( 'nf/bridge/error', array( $this, 'record' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->id = (int) wp_insert_post(
			array(
				'post_title'  => 'group gaps',
				'post_status' => 'draft',
			)
		);
	}

	protected function tearDown(): void {
		wp_delete_post( $this->id, true );
		remove_action( 'nf/bridge/error', array( $this, 'record' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->assertSame( array(), $this->errors, 'Bridge errors' );
	}

	/**
	 * Collect bridge errors.
	 *
	 * @param \Throwable $e Error.
	 * @return void
	 */
	public function record( \Throwable $e ): void {
		$this->errors[] = $e->getMessage();
	}

	/**
	 * NF group value.
	 *
	 * @return array<string,mixed>
	 */
	private function box(): array {
		return (array) Plugin::store()->get( ObjectType::Post, $this->id, 'field_nfgg_box' );
	}

	/**
	 * Save the whole group once.
	 *
	 * @return void
	 */
	private function save_group(): void {
		update_field(
			'nfgg_box',
			array(
				'field_nfgg_box_note'                     => 'N',
				'field_nfgg_box_inner'                    => array( 'field_nfgg_box_inner_deep' => 'D' ),
				'field_nfgg_box_seam_field_nfggsrc_label' => 'S',
				'field_nfgg_box_gclone'                   => array( 'field_nfggsrc_label' => 'G' ),
			),
			$this->id
		);
		Plugin::bridge()->flush();
	}

	public function test_clones_inside_a_group(): void {
		$this->save_group();
		$nf = $this->box();
		$this->assertSame( 'N', $nf['note'] );
		$this->assertSame( array( 'deep' => 'D' ), $nf['inner'] );
		$this->assertSame( 'S', get_field( 'nfgg_box', $this->id )['seam_label'] ?? null, 'ACF returns the seamless clone flattened with its prefix' );
		$this->assertSame( 'S', $nf['seam_label'] ?? null, 'NF matches' );
		$this->assertSame( array( 'label' => 'G' ), $nf['gclone'] );
	}

	public function test_sub_field_saved_on_its_own(): void {
		$this->save_group();
		// update_sub_field() outside a have_rows() loop returns false for groups in ACF PRO 6; the full meta name is
		// ACF's documented way to save one group sub-field.
		$this->assertTrue( (bool) update_field( 'nfgg_box_inner_deep', 'D2', $this->id ), 'ACF saved' );
		Plugin::bridge()->flush();
		$this->assertSame( array( 'deep' => 'D2' ), $this->box()['inner'] );

		$this->assertTrue( (bool) update_field( 'nfgg_box_note', 'N3', $this->id ), 'ACF saved' );
		Plugin::bridge()->flush();
		$this->assertSame( 'N3', $this->box()['note'] );
		$this->assertSame( array( 'deep' => 'D2' ), $this->box()['inner'], 'Other parts kept' );
		$this->assertSame( 'S', $this->box()['seam_label'], 'Clone part kept' );
	}
}
