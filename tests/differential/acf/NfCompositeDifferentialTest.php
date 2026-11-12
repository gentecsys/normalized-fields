<?php
/**
 * Composite types (WP 2.1c) through real ACF: link, google_map and group (with a nested group)
 * saved with update_field() must reach the NF tables.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

use NF\Install\Schema;
use NF\Plugin;
use NF\Storage\ObjectType;
use PHPUnit\Framework\TestCase;

final class NfCompositeDifferentialTest extends TestCase {

	/**
	 * Post under test.
	 *
	 * @var int
	 */
	private static int $id = 0;

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
				'key'      => 'group_nfcomp',
				'title'    => 'NF composite differential',
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
						'key'           => 'field_nfcomp_link',
						'name'          => 'nfcomp_link',
						'type'          => 'link',
						'return_format' => 'array',
					),
					array(
						'key'  => 'field_nfcomp_map',
						'name' => 'nfcomp_map',
						'type' => 'google_map',
					),
					array(
						'key'        => 'field_nfcomp_grp',
						'name'       => 'nfcomp_grp',
						'type'       => 'group',
						'sub_fields' => array(
							array(
								'key'  => 'field_nfcomp_grp_title',
								'name' => 'title',
								'type' => 'text',
							),
							array(
								'key'  => 'field_nfcomp_grp_num',
								'name' => 'num',
								'type' => 'number',
							),
							array(
								'key'        => 'field_nfcomp_grp_inner',
								'name'       => 'inner',
								'type'       => 'group',
								'sub_fields' => array(
									array(
										'key'  => 'field_nfcomp_grp_inner_note',
										'name' => 'note',
										'type' => 'text',
									),
								),
							),
						),
					),
				),
			)
		);
		self::$id = (int) wp_insert_post(
			array(
				'post_title'  => 'composite differential',
				'post_status' => 'draft',
			)
		);
	}

	public static function tearDownAfterClass(): void {
		Plugin::store()->deleteObject( ObjectType::Post, self::$id );
		wp_delete_post( self::$id, true );
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field\\_nfcomp\\_%' ) );
		wp_cache_flush();
	}

	protected function setUp(): void {
		$this->errors = array();
		add_action( 'nf/bridge/error', array( $this, 'record' ) ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores
	}

	protected function tearDown(): void {
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
	 * NF value of a top-level field.
	 *
	 * @param string $key Field key.
	 * @return mixed
	 */
	private function nf( string $key ): mixed {
		return Plugin::store()->get( ObjectType::Post, self::$id, $key );
	}

	public function test_link(): void {
		$link = array(
			'url'    => 'https://example.com/a',
			'title'  => 'Tickets',
			'target' => '_blank',
		);
		update_field( 'nfcomp_link', $link, self::$id );
		$this->assertSame( $link, $this->nf( 'field_nfcomp_link' ) );
	}

	public function test_google_map(): void {
		update_field(
			'nfcomp_map',
			array(
				'address' => '1 Main St',
				'lat'     => 42.6977082,
				'lng'     => 23.3218689,
				'zoom'    => 14,
			),
			self::$id
		);
		$nf = $this->nf( 'field_nfcomp_map' );
		$this->assertSame( '1 Main St', $nf['address'] );
		$this->assertSame( 14, $nf['zoom'] );
		$this->assertEqualsWithDelta( 42.6977082, (float) $nf['lat'], 1e-10 ); // DECIMAL(24,10): exact for 7-decimal coordinates.
		$this->assertEqualsWithDelta( 23.3218689, (float) $nf['lng'], 1e-10 );
	}

	public function test_group_with_nested_group(): void {
		update_field(
			'nfcomp_grp',
			array(
				'title' => 'Hello',
				'num'   => 7,
				'inner' => array( 'note' => 'deep' ),
			),
			self::$id
		);
		$nf = $this->nf( 'field_nfcomp_grp' );
		$this->assertSame( 'Hello', $nf['title'] );
		$this->assertEquals( 7, $nf['num'] );
		$this->assertSame( array( 'note' => 'deep' ), $nf['inner'] );

		// ACF's group handler passes '' on to later filters; a second save must not wipe the mirror.
		update_field(
			'nfcomp_grp',
			array(
				'title' => 'Hi',
				'num'   => 8,
				'inner' => array( 'note' => '' ),
			),
			self::$id
		);
		$nf = $this->nf( 'field_nfcomp_grp' );
		$this->assertSame( 'Hi', $nf['title'] );
		$this->assertEquals( 8, $nf['num'] );
		$this->assertNull( $nf['inner']['note'] ?? null );
	}
}
