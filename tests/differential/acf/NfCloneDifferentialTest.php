<?php
/**
 * Clone fields (WP 2.1d) through real ACF: one source group cloned seamlessly (plain and with
 * `prefix_name`) and as a group. Each clone must get its own NF fields; ACF saves every cloned
 * value under the source field's key, so keying NF by that key alone lost all but the last clone.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

use NF\Install\Schema;
use NF\Plugin;
use NF\Storage\ObjectType;
use PHPUnit\Framework\TestCase;

final class NfCloneDifferentialTest extends TestCase {

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
				'key'      => 'group_nfclsrc',
				'title'    => 'NF clone source',
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
						'key'  => 'field_nfclsrc_title',
						'name' => 'title',
						'type' => 'text',
					),
					array(
						'key'  => 'field_nfclsrc_num',
						'name' => 'num',
						'type' => 'number',
					),
				),
			)
		);
		acf_add_local_field_group(
			array(
				'key'      => 'group_nfcl',
				'title'    => 'NF clone differential',
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
						'key'         => 'field_nfcl_seam',
						'name'        => 'seam',
						'type'        => 'clone',
						'clone'       => array( 'field_nfclsrc_title', 'field_nfclsrc_num' ),
						'display'     => 'seamless',
						'prefix_name' => 0,
					),
					array(
						'key'         => 'field_nfcl_pref',
						'name'        => 'pref',
						'type'        => 'clone',
						'clone'       => array( 'group_nfclsrc' ),
						'display'     => 'seamless',
						'prefix_name' => 1,
					),
					array(
						'key'         => 'field_nfcl_grp',
						'name'        => 'grp',
						'type'        => 'clone',
						'clone'       => array( 'group_nfclsrc' ),
						'display'     => 'group',
						'prefix_name' => 1,
					),
				),
			)
		);
	}

	public static function tearDownAfterClass(): void {
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field\\_nfcl%' ) );
		wp_cache_flush();
	}

	protected function setUp(): void {
		$this->errors = array();
		add_action( 'nf/bridge/error', array( $this, 'record' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
		$this->id = (int) wp_insert_post(
			array(
				'post_title'  => 'clone differential',
				'post_status' => 'draft',
			)
		);
	}

	protected function tearDown(): void {
		Plugin::store()->deleteObject( ObjectType::Post, $this->id );
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
	 * NF value of a field.
	 *
	 * @param string $key Field key.
	 * @return mixed
	 */
	private function nf( string $key ): mixed {
		return Plugin::store()->get( ObjectType::Post, $this->id, $key );
	}

	public function test_seamless_clones_of_one_source_keep_separate_values(): void {
		update_field(
			'field_nfcl_seam',
			array(
				'field_nfcl_seam_field_nfclsrc_title' => 'S',
				'field_nfcl_seam_field_nfclsrc_num'   => 1,
			),
			$this->id
		);
		update_field(
			'field_nfcl_pref',
			array(
				'field_nfcl_pref_field_nfclsrc_title' => 'P',
				'field_nfcl_pref_field_nfclsrc_num'   => 2,
			),
			$this->id
		);
		$this->assertSame( 'S', get_field( 'title', $this->id ) );
		$this->assertSame( 'P', get_field( 'pref_title', $this->id ) );

		$this->assertSame( 'S', $this->nf( 'field_nfcl_seam_field_nfclsrc_title' ) );
		$this->assertSame( 'P', $this->nf( 'field_nfcl_pref_field_nfclsrc_title' ) );
		$this->assertEquals( 1, $this->nf( 'field_nfcl_seam_field_nfclsrc_num' ) );
		$this->assertEquals( 2, $this->nf( 'field_nfcl_pref_field_nfclsrc_num' ) );

		$fields = Plugin::fields();
		$this->assertSame( 'title', $fields->byKey( 'field_nfcl_seam_field_nfclsrc_title' )->name );
		$this->assertSame( 'pref_title', $fields->byKey( 'field_nfcl_pref_field_nfclsrc_title' )->name );
		$this->assertSame( 0, $fields->byKey( 'field_nfcl_pref_field_nfclsrc_title' )->parentId );
		$this->assertNull( $fields->byKey( 'field_nfclsrc_title' ), 'The source field is not stored on its own' );
	}

	public function test_group_clone(): void {
		update_field(
			'field_nfcl_grp',
			array(
				'field_nfclsrc_title' => 'G',
				'field_nfclsrc_num'   => 3,
			),
			$this->id
		);
		$this->assertSame( 'G', get_field( 'grp', $this->id )['title'] );
		$nf = $this->nf( 'field_nfcl_grp' );
		$this->assertSame( 'G', $nf['title'] );
		$this->assertEquals( 3, $nf['num'] );

		// A second save replaces the values; ACF's '' after its own handler must not wipe them.
		update_field(
			'field_nfcl_grp',
			array(
				'field_nfclsrc_title' => 'H',
				'field_nfclsrc_num'   => 4,
			),
			$this->id
		);
		$nf = $this->nf( 'field_nfcl_grp' );
		$this->assertSame( 'H', $nf['title'] );
		$this->assertEquals( 4, $nf['num'] );
		$this->assertNull( Plugin::fields()->byKey( 'field_nfclsrc_title' ), 'The source field is not stored on its own' );
	}
}
