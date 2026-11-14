<?php
/**
 * PostPortJob tests (WP 2.6b).
 *
 * @package NF
 */

use NF\Migration\PostPortJob;

require_once __DIR__ . '/class-nf-test-port-source.php';
require_once __DIR__ . '/class-nf-test-port-copier.php';

/**
 * Job tests with doubles: no ACF needed.
 */
class PostPortJobTest extends WP_UnitTestCase {

	/**
	 * Source double.
	 *
	 * @var NF_Test_Port_Source
	 */
	private NF_Test_Port_Source $source;

	/**
	 * Copier double.
	 *
	 * @var NF_Test_Port_Copier
	 */
	private NF_Test_Port_Copier $copier;

	/**
	 * Build doubles with one portable and one flexible collection.
	 */
	public function set_up() {
		parent::set_up();
		$this->source       = new NF_Test_Port_Source();
		$this->copier       = new NF_Test_Port_Copier();
		$this->source->data = array(
			array(
				'key'    => 'group_a',
				'title'  => 'A',
				'fields' => array(
					array(
						'key'  => 'field_1',
						'name' => 'one',
						'type' => 'text',
					),
					array(
						'key'  => 'field_2',
						'name' => 'two',
						'type' => 'text',
					),
				),
			),
			array(
				'key'    => 'group_b',
				'title'  => 'B',
				'fields' => array(
					array(
						'key'  => 'field_3',
						'name' => 'flex',
						'type' => 'flexible_content',
					),
				),
			),
		);
	}

	/**
	 * Job over a fixed ID list.
	 *
	 * @param int[] $all Post IDs.
	 * @return PostPortJob
	 */
	private function job( array $all ): PostPortJob {
		$lister = static function ( string $type, int $after, int $limit ) use ( $all ): array {
			unset( $type );
			return array_slice( array_values( array_filter( $all, static fn( $id ) => $id > $after ) ), 0, $limit );
		};
		return new PostPortJob( $this->source, $this->copier, $lister );
	}

	/**
	 * Chunks resume from the last ID; flexible collections are skipped; counts add up.
	 */
	public function test_chunks_resume_and_skip_flexible() {
		$job   = $this->job( array( 2, 3, 4, 7, 8 ) );
		$state = $job->init(
			array(
				'post_type' => 'event',
				'chunk'     => 2,
			)
		);
		$state = $job->step( $state );
		$this->assertSame( 3, $state['after'] );
		$this->assertFalse( $state['done'] );
		$state = $job->step( $state );
		$state = $job->step( $state );
		$this->assertTrue( $state['done'] );
		$this->assertSame( 5, $state['posts'] );
		// Even posts (2, 4, 8) count both fields; odd posts none.
		$this->assertSame( 6, $state['values'] );
		$this->assertNotContains( '2:flex', $this->copier->calls );
		$this->assertCount( 10, $this->copier->calls );
		$this->assertSame( 3, $this->copier->flushes );
	}

	/**
	 * An empty post type finishes at once.
	 */
	public function test_empty_type_done() {
		$job   = $this->job( array() );
		$state = $job->step( $job->init( array( 'post_type' => 'event' ) ) );
		$this->assertTrue( $state['done'] );
		$this->assertSame( 0, $state['posts'] );
	}

	/**
	 * No post type, or no ACF, stops with an error and copies nothing.
	 */
	public function test_errors() {
		$job   = $this->job( array( 2 ) );
		$state = $job->step( $job->init( array() ) );
		$this->assertSame( 'no_post_type', $state['error'] );
		$this->source->loaded = false;
		$state                = $job->step( $job->init( array( 'post_type' => 'event' ) ) );
		$this->assertSame( 'acf_unavailable', $state['error'] );
		$this->assertTrue( $state['done'] );
		$this->assertSame( array(), $this->copier->calls );
	}

	/**
	 * Portable fields come only from portable collections.
	 */
	public function test_portable_fields() {
		$names = array_column( $this->source->portableFields( $this->source->data ), 'name' );
		$this->assertSame( array( 'one', 'two' ), $names );
	}
}
