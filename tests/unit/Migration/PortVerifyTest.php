<?php
/**
 * Port verify tests (WP 2.6d).
 *
 * @package NF
 */

use NF\Migration\PortVerifier;
use NF\Migration\PortVerifyJob;
use NF\Migration\ValueComparer;

require_once __DIR__ . '/class-nf-test-port-source.php';

// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- Closure doubles must match the reader signatures.

/**
 * Comparer, verifier and job with doubles: no ACF needed.
 */
class PortVerifyTest extends WP_UnitTestCase {

	/**
	 * Types differ, values do not.
	 */
	public function test_comparer_ignores_storage_types() {
		$this->assertSame( array(), ValueComparer::diff( '5', 5 ) );
		$this->assertSame( array(), ValueComparer::diff( '1.50', 1.5 ) );
		$this->assertSame( array(), ValueComparer::diff( '', null ) );
		$this->assertSame( array(), ValueComparer::diff( array(), null ) );
		$this->assertSame( array(), ValueComparer::diff( array( '3', '4' ), array( 3, 4 ) ) );
		$this->assertSame( array(), ValueComparer::diff( true, '1' ) );
	}

	/**
	 * Real differences are found, with paths into Series rows.
	 */
	public function test_comparer_reports_paths() {
		$this->assertSame( array( 'f' ), ValueComparer::diff( 'a', 'b', 'f' ) );
		$this->assertSame( array( 'f[0]' ), ValueComparer::diff( array( 'a', 'b' ), array( 'b', 'b' ), 'f' ) );
		$this->assertSame( array( 'f[1]' ), ValueComparer::diff( array( '1', '2' ), array( '1' ), 'f' ) );
		$acf = array(
			array(
				'title' => 'x',
				'n'     => '1',
			),
			array(
				'title' => 'y',
				'n'     => '',
			),
		);
		$nf  = array(
			array(
				'title' => 'x',
				'n'     => 1,
			),
			array( 'title' => 'z' ),
		);
		$this->assertSame( array( 'rows[1].title' ), ValueComparer::diff( $acf, $nf, 'rows' ) );
	}

	/**
	 * Fields ACF holds nothing for are not mismatches.
	 */
	public function test_verifier_skips_fields_acf_lacks() {
		$verifier = new PortVerifier(
			null,
			null,
			static fn( $id, $field ) => 'one' === $field['name'] ? array( true, 'a' ) : array( false, null ),
			static fn( $id, $field ) => 'zzz'
		);
		$fields   = array(
			array(
				'key'  => 'field_1',
				'name' => 'one',
			),
			array(
				'key'  => 'field_2',
				'name' => 'two',
			),
		);
		$out      = $verifier->verify( 7, $fields );
		$this->assertCount( 1, $out );
		$this->assertSame( 'one', $out[0]['field'] );
		$this->assertSame( 7, $out[0]['object'] );
	}

	/**
	 * The job chunks, counts and caps the list.
	 */
	public function test_job_counts_mismatches_across_chunks() {
		$source       = new NF_Test_Port_Source();
		$source->data = array(
			array(
				'key'    => 'group_a',
				'title'  => 'A',
				'fields' => array(
					array(
						'key'  => 'field_1',
						'name' => 'one',
						'type' => 'text',
					),
				),
			),
		);
		// Odd posts mismatch.
		$verifier = new PortVerifier( null, null, static fn( $id, $f ) => array( true, 'a' ), static fn( $id, $f ) => 1 === $id % 2 ? 'b' : 'a' );
		$all      = array( 1, 2, 3, 4, 5 );
		$lister   = static fn( string $t, int $after, int $limit ): array => array_slice( array_values( array_filter( $all, static fn( $i ) => $i > $after ) ), 0, $limit );
		$job      = new PortVerifyJob( $source, $verifier, $lister );
		$state    = $job->init(
			array(
				'post_type' => 'event',
				'chunk'     => 2,
			)
		);
		while ( ! $state['done'] ) {
			$state = $job->step( $state );
		}
		$this->assertSame( 5, $state['posts'] );
		$this->assertSame( 3, $state['mismatches'] );
		$this->assertSame( array( 1, 3, 5 ), array_column( $state['list'], 'object' ) );
	}

	/**
	 * No post type, or no ACF, stops with an error.
	 */
	public function test_job_errors() {
		$source = new NF_Test_Port_Source();
		$job    = new PortVerifyJob( $source, new PortVerifier() );
		$this->assertSame( 'no_post_type', $job->step( $job->init( array() ) )['error'] );
		$source->loaded = false;
		$this->assertSame( 'acf_unavailable', $job->step( $job->init( array( 'post_type' => 'event' ) ) )['error'] );
	}
}
