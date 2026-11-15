<?php
/**
 * ObjectPortJob tests (WP 2.6c).
 *
 * @package NF
 */

use NF\Migration\ObjectPortJob;

require_once __DIR__ . '/class-nf-test-port-source.php';
require_once __DIR__ . '/class-nf-test-port-copier.php';

/**
 * Job tests with doubles: no ACF needed.
 */
class ObjectPortJobTest extends WP_UnitTestCase {

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
	 * One portable collection.
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
				),
			),
		);
	}

	/**
	 * Job over fixed IDs.
	 *
	 * @param int[]    $all     IDs.
	 * @param string[] $options Option page IDs.
	 * @return ObjectPortJob
	 */
	private function job( array $all, array $options = array( 'options' ) ): ObjectPortJob {
		$lister = static function ( string $kind, int $after, int $limit ) use ( $all, $options ): array {
			if ( 'option' === $kind ) {
				return $options;
			}
			return array_slice( array_values( array_filter( $all, static fn( $id ) => $id > $after ) ), 0, $limit );
		};
		return new ObjectPortJob( $this->source, $this->copier, $lister );
	}

	/**
	 * Terms use ACF's term_ IDs, resume from the last ID and count empty objects.
	 */
	public function test_terms_chunk_and_count() {
		$job   = $this->job( array( 2, 3, 4 ) );
		$state = $job->init(
			array(
				'kind'  => 'term',
				'chunk' => 2,
			)
		);
		$state = $job->step( $state );
		$this->assertFalse( $state['done'] );
		$this->assertSame( 3, $state['after'] );
		$state = $job->step( $state );
		$this->assertTrue( $state['done'] );
		$this->assertSame( array( 'term_2:one', 'term_3:one', 'term_4:one' ), $this->copier->calls );
		$this->assertSame( 3, $state['objects'] );
		$this->assertSame( 2, $state['values'] );
		$this->assertSame( 1, $state['empty'] );
	}

	/**
	 * Users and comments get their own prefixes.
	 */
	public function test_user_and_comment_ids() {
		foreach ( array( 'user', 'comment' ) as $kind ) {
			$job = $this->job( array( 2 ) );
			$job->step( $job->init( array( 'kind' => $kind ) ) );
		}
		$this->assertSame( array( 'user_2:one', 'comment_2:one' ), $this->copier->calls );
	}

	/**
	 * Options pages are ported in a single step.
	 */
	public function test_options_one_step() {
		$job   = $this->job( array(), array( 'options', 'site_settings' ) );
		$state = $job->step( $job->init( array( 'kind' => 'option' ) ) );
		$this->assertTrue( $state['done'] );
		$this->assertSame( 1, $state['objects'] );
		$this->assertSame( 1, $state['unsupported'] );
		$this->assertSame( array( 'options:one' ), $this->copier->calls );
	}

	/**
	 * Bad kind or no ACF stops with an error and copies nothing.
	 */
	public function test_errors() {
		$job   = $this->job( array( 2 ) );
		$state = $job->step( $job->init( array( 'kind' => 'post' ) ) );
		$this->assertSame( 'bad_kind', $state['error'] );
		$this->source->loaded = false;
		$state                = $job->step( $job->init( array( 'kind' => 'term' ) ) );
		$this->assertSame( 'acf_unavailable', $state['error'] );
		$this->assertSame( array(), $this->copier->calls );
	}

	/**
	 * The real core-table listing: keyset boundary and ACF IDs for terms, users and comments.
	 */
	public function test_default_lister_uses_core_tables() {
		$term    = self::factory()->term->create();
		$user    = self::factory()->user->create();
		$comment = self::factory()->comment->create();
		$expect  = array(
			'term'    => 'term_' . $term,
			'user'    => 'user_' . $user,
			'comment' => 'comment_' . $comment,
		);
		$ids     = array(
			'term'    => $term,
			'user'    => $user,
			'comment' => $comment,
		);
		foreach ( $expect as $kind => $acf_id ) {
			$this->copier->calls = array();
			$job                 = new ObjectPortJob( $this->source, $this->copier );
			$state               = $job->init(
				array(
					'kind'  => $kind,
					'chunk' => 1000,
				)
			);
			$state               = $job->step( $state );
			$this->assertContains( $acf_id . ':one', $this->copier->calls );
			$this->assertSame( $ids[ $kind ], $state['after'] );
			// Resuming after the last ID visits nothing.
			$this->copier->calls = array();
			$state               = $job->step( $state );
			$this->assertSame( array(), $this->copier->calls );
			$this->assertTrue( $state['done'] );
		}
	}

	/**
	 * Every ID the job builds decodes to the matching object type in the bridge.
	 */
	public function test_ids_decode_in_bridge() {
		$this->assertSame( \NF\Storage\ObjectType::Term, \NF\Acf\Bridge::decode( 'term_5' )[0] );
		$this->assertSame( \NF\Storage\ObjectType::User, \NF\Acf\Bridge::decode( 'user_5' )[0] );
		$this->assertSame( \NF\Storage\ObjectType::Comment, \NF\Acf\Bridge::decode( 'comment_5' )[0] );
		$this->assertSame( \NF\Storage\ObjectType::Option, \NF\Acf\Bridge::decode( 'options' )[0] );
		$this->assertNull( \NF\Acf\Bridge::decode( 'site_settings' ) );
	}
}
