<?php
/**
 * Re-sync after a manual upgrade, through real ACF: values ACF saved while the NF schema was behind are
 * copied into NF when the upgrade finishes (`nf/upgraded`), including Series, groups and clones.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

use NF\Install\Schema;
use NF\Install\Upgrader;
use NF\Plugin;
use NF\Storage\ObjectType;
use PHPUnit\Framework\TestCase;

final class NfResyncDifferentialTest extends TestCase {

	/**
	 * Posts created by the test.
	 *
	 * @var int[]
	 */
	private array $ids = array();

	public static function setUpBeforeClass(): void {
		Schema::install();
		wp_set_current_user( 1 );
		acf_add_local_field_group(
			array(
				'key'      => 'group_nfrs',
				'title'    => 'NF resync differential',
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
						'key'  => 'field_nfrs_title',
						'name' => 'nfrs_title',
						'type' => 'text',
					),
					array(
						'key'        => 'field_nfrs_rows',
						'name'       => 'nfrs_rows',
						'type'       => 'repeater',
						'sub_fields' => array(
							array(
								'key'  => 'field_nfrs_rows_room',
								'name' => 'room',
								'type' => 'text',
							),
						),
					),
					array(
						'key'        => 'field_nfrs_box',
						'name'       => 'nfrs_box',
						'type'       => 'group',
						'sub_fields' => array(
							array(
								'key'  => 'field_nfrs_box_note',
								'name' => 'note',
								'type' => 'text',
							),
						),
					),
					array(
						'key'         => 'field_nfrs_clone',
						'name'        => 'nfrs_clone',
						'type'        => 'clone',
						'clone'       => array( 'field_nfrs_title' ),
						'display'     => 'seamless',
						'prefix_name' => 1,
					),
				),
			)
		);
	}

	public static function tearDownAfterClass(): void {
		$db = nf_db();
		$db->execute( 'DELETE FROM ' . $db->table( 'nf_fields' ) . ' WHERE field_key LIKE ?', array( 'field\\_nfrs%' ) );
		update_option( Upgrader::OPTION, Upgrader::SCHEMA_VERSION );
		delete_option( Upgrader::NEEDS_RESYNC );
		wp_cache_flush();
	}

	protected function tearDown(): void {
		foreach ( $this->ids as $id ) {
			Plugin::store()->deleteObject( ObjectType::Post, $id );
			wp_delete_post( $id, true );
		}
		update_option( Upgrader::OPTION, Upgrader::SCHEMA_VERSION );
		delete_option( Upgrader::NEEDS_RESYNC );
	}

	/**
	 * Save a post's values with ACF while NF is behind.
	 *
	 * @param string $title Title value.
	 * @return int
	 */
	private function save_behind( string $title ): int {
		$id          = (int) wp_insert_post(
			array(
				'post_title'  => 'resync',
				'post_status' => 'draft',
			)
		);
		$this->ids[] = $id;
		Plugin::bridge()->flush(); // Re-syncs queued by earlier tests belong to their own objects.
		update_option( Upgrader::OPTION, Upgrader::SCHEMA_VERSION - 1 );
		update_field( 'nfrs_title', $title, $id );
		update_field(
			'nfrs_rows',
			array(
				array( 'field_nfrs_rows_room' => 'A' ),
				array( 'field_nfrs_rows_room' => 'B' ),
			),
			$id
		);
		update_field( 'nfrs_box', array( 'field_nfrs_box_note' => 'n-' . $title ), $id );
		update_field( 'field_nfrs_clone', array( 'field_nfrs_clone_field_nfrs_title' => 'c-' . $title ), $id );
		Plugin::bridge()->flush();
		return $id;
	}

	/**
	 * NF value of a field.
	 *
	 * @param int    $id  Post.
	 * @param string $key Field key.
	 * @return mixed
	 */
	private function nf( int $id, string $key ): mixed {
		try {
			return Plugin::store()->get( ObjectType::Post, $id, $key );
		} catch ( \InvalidArgumentException $e ) {
			return 'unregistered';
		}
	}

	public function test_values_saved_while_behind_are_copied_after_the_upgrade(): void {
		$id = $this->save_behind( 'T' );
		$this->assertContains( 'unregistered', array( $this->nf( $id, 'field_nfrs_title' ), null ), 'Nothing written while behind' );
		$flag = get_option( Upgrader::NEEDS_RESYNC );
		$this->assertSame( array( (string) $id ), $flag['objects'] );

		update_option( Upgrader::OPTION, Upgrader::SCHEMA_VERSION );
		do_action( 'nf/upgraded', Upgrader::SCHEMA_VERSION - 1, Upgrader::SCHEMA_VERSION ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores

		$this->assertSame( 'T', $this->nf( $id, 'field_nfrs_title' ) );
		$this->assertSame( array( 'A', 'B' ), array_column( Plugin::store()->getRows( ObjectType::Post, $id, 'field_nfrs_rows' ), 'room' ) );
		$this->assertSame( array( 'note' => 'n-T' ), $this->nf( $id, 'field_nfrs_box' ) );
		$this->assertSame( 'c-T', $this->nf( $id, 'field_nfrs_clone_field_nfrs_title' ), 'Seamless clone keeps its own NF field' );
		$this->assertFalse( get_option( Upgrader::NEEDS_RESYNC ), 'Flag cleared' );
	}

	public function test_admin_batch_leaves_the_rest_listed(): void {
		$a = $this->save_behind( 'A' );
		$b = $this->save_behind( 'B' );
		update_option( Upgrader::OPTION, Upgrader::SCHEMA_VERSION );
		$this->assertSame( 1, Plugin::bridge()->resync( 1 ) );
		$this->assertSame( 'A', $this->nf( $a, 'field_nfrs_title' ) );
		$this->assertSame( array( (string) $b ), get_option( Upgrader::NEEDS_RESYNC )['objects'] );
		$this->assertSame( 1, Upgrader::resyncPending()['count'] );
		Plugin::bridge()->resync( 0 );
		$this->assertSame( 'B', $this->nf( $b, 'field_nfrs_title' ) );
		$this->assertNull( Upgrader::resyncPending() );
	}

	public function test_object_with_unloaded_fields_stays_listed(): void {
		$id = $this->save_behind( 'T' );
		// A value from a field group this request doesn't load (registered elsewhere, e.g. only on some screens).
		update_post_meta( $id, 'other_thing', 'x' );
		update_post_meta( $id, '_other_thing', 'field_nfrs_not_loaded' );
		update_option( Upgrader::OPTION, Upgrader::SCHEMA_VERSION );
		$this->assertSame( 0, Plugin::bridge()->resync( 0 ) );
		$this->assertSame( 'T', $this->nf( $id, 'field_nfrs_title' ), 'Loaded fields are still copied' );
		$this->assertSame( array( (string) $id ), get_option( Upgrader::NEEDS_RESYNC )['objects'], 'Kept for wp nf resync' );
	}

	public function test_value_deleted_while_behind_is_removed(): void {
		$id = $this->save_behind( 'T' );
		update_option( Upgrader::OPTION, Upgrader::SCHEMA_VERSION );
		Plugin::bridge()->resync( 0 );
		$this->assertSame( 'T', $this->nf( $id, 'field_nfrs_title' ) );

		update_option( Upgrader::OPTION, Upgrader::SCHEMA_VERSION - 1 );
		delete_field( 'nfrs_title', $id );
		update_option( Upgrader::OPTION, Upgrader::SCHEMA_VERSION );
		Plugin::bridge()->resync( 0 );
		$this->assertNull( $this->nf( $id, 'field_nfrs_title' ) );
	}
}
