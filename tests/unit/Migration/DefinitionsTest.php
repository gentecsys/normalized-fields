<?php
/**
 * Definition port, orphan and raw SQL scan tests (WP 2.6c2).
 *
 * @package NF
 */

use NF\Migration\DefinitionPorter;
use NF\Migration\DefinitionReader;
use NF\Migration\OrphanCounter;
use NF\Migration\RawSqlScanner;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions, WordPress.WP.AlternativeFunctions

/**
 * Tests for the report-side helpers.
 */
class DefinitionsTest extends WP_UnitTestCase {

	/**
	 * Internals dropped, bad entries skipped, output sorted.
	 */
	public function test_porter_cleans_and_sorts(): void {
		$out = ( new DefinitionPorter() )->port(
			array(
				array(
					'ID'        => 9,
					'post_type' => 'book',
					'active'    => false,
				),
				array( 'post_type' => 'album' ),
				array( 'title' => 'no id' ),
			),
			array( array( 'taxonomy' => 'genre' ) ),
			array( array( 'menu_slug' => 'site-options' ) )
		);
		$this->assertSame( array( 'album', 'book' ), array_column( $out['post_types'], 'post_type' ) );
		$this->assertArrayNotHasKey( 'ID', $out['post_types'][1] );
		$this->assertFalse( $out['post_types'][1]['active'] );
		$this->assertTrue( $out['post_types'][0]['active'] );
		$this->assertSame( 1, $out['counts']['skipped'] );
		$this->assertSame( 1, $out['counts']['taxonomies'] );
		$this->assertSame( 1, $out['counts']['options_pages'] );
	}

	/**
	 * Reader decodes serialized definitions and refuses objects.
	 */
	public function test_reader_decodes_and_refuses_objects(): void {
		$this->assertSame( array( 'a' => 1 ), DefinitionReader::decode( serialize( array( 'a' => 1 ) ) ) );
		$this->assertNull( DefinitionReader::decode( 'not serialized' ) );
		$this->assertNull( DefinitionReader::decode( serialize( new stdClass() ) ) );
	}

	/**
	 * Reader lists published and disabled ACF internal posts.
	 */
	public function test_reader_reads_internal_posts(): void {
		self::factory()->post->create(
			array(
				'post_type'    => 'acf-post-type',
				'post_status'  => 'publish',
				'post_content' => serialize( array( 'post_type' => 'book' ) ),
			)
		);
		self::factory()->post->create(
			array(
				'post_type'    => 'acf-taxonomy',
				'post_status'  => 'acf-disabled',
				'post_content' => serialize( array( 'taxonomy' => 'genre' ) ),
			)
		);
		$read = ( new DefinitionReader() )->read();
		$this->assertSame( 'book', $read['post_types'][0]['post_type'] );
		$this->assertTrue( $read['post_types'][0]['active'] );
		$this->assertFalse( $read['taxonomies'][0]['active'] );
		$this->assertSame( array(), $read['options_pages'] );
	}

	/**
	 * Orphans are references to unknown field keys.
	 */
	public function test_orphan_counter(): void {
		$counter = new OrphanCounter();
		$this->assertSame( 2, $counter->countOrphans( array( 'field_a', 'field_x', 'field_y' ), array( 'field_a' ) ) );

		$id = self::factory()->post->create();
		add_post_meta( $id, 'one', 'v' );
		add_post_meta( $id, '_one', 'field_known' );
		add_post_meta( $id, 'two', 'v' );
		add_post_meta( $id, '_two', 'field_gone' );
		add_post_meta( $id, '_other', 'not a field ref' );
		$this->assertSame( 1, $counter->count( 'post', array( 'field_known' ), 1 ) );
		$this->assertSame( 0, $counter->count( 'nope', array() ) );
	}

	/**
	 * Raw SQL hits are found by line.
	 */
	public function test_raw_sql_scanner(): void {
		$code = "<?php\n\$a = \$wpdb->get_results( \"SELECT * FROM {\$wpdb->postmeta} WHERE 1\" );\n\$b = get_post_meta( 1, 'x' );\n\$c = 'SELECT meta_value FROM wp_postmeta';\n";
		$this->assertSame( array( 2, 4 ), ( new RawSqlScanner() )->scanSource( $code ) );
		$this->assertSame( array(), ( new RawSqlScanner() )->scanSource( "<?php\nupdate_post_meta( 1, 'a', 'b' );\n" ) );
	}

	/**
	 * Directory scan skips vendor and reports relative paths.
	 */
	public function test_raw_sql_scan_directory(): void {
		$dir = trailingslashit( get_temp_dir() ) . 'nf-rawsql-' . wp_generate_password( 6, false );
		wp_mkdir_p( $dir . '/vendor' );
		file_put_contents( $dir . '/a.php', "<?php\n\$wpdb->postmeta;\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $dir . '/vendor/b.php', "<?php\n\$wpdb->postmeta;\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$hits = ( new RawSqlScanner() )->scanDirectory( $dir );
		$this->assertSame( array( 'a.php' => array( 2 ) ), $hits );
		unlink( $dir . '/a.php' );
		unlink( $dir . '/vendor/b.php' );
		rmdir( $dir . '/vendor' );
		rmdir( $dir );
	}
}
