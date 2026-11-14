<?php
/**
 * PostPortJob tests (WP 2.6b).
 *
 * @package NF
 */

use NF\Migration\AcfCopier;
use NF\Migration\AcfSource;
use NF\Migration\PostPortJob;

/**
 * Source double: fixed groups, ACF "available".
 */
class NF_Test_Port_Source extends AcfSource {

	/**
	 * Whether ACF is loaded.
	 *
	 * @var bool
	 */
	public bool $loaded = true;

	/**
	 * Groups to return.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $data = array();

	/**
	 * Available.
	 *
	 * @return bool
	 */
	public function available(): bool {
		return $this->loaded;
	}

	/**
	 * Groups.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function groups(): array {
		return $this->data;
	}
}

/**
 * Copier double: records calls.
 */
class NF_Test_Port_Copier extends AcfCopier {

	/**
	 * Calls made.
	 *
	 * @var string[]
	 */
	public array $calls = array();

	/**
	 * Flushes.
	 *
	 * @var int
	 */
	public int $flushes = 0;

	/**
	 * Constructor (no bridge).
	 */
	public function __construct() {}

	/**
	 * Record a copy; ACF "holds a value" for even post IDs only.
	 *
	 * @param int                 $post_id Post.
	 * @param array<string,mixed> $field   Field.
	 * @return bool
	 */
	public function copy( int $post_id, array $field ): bool {
		$this->calls[] = $post_id . ':' . $field['name'];
		return 0 === $post_id % 2;
	}

	/**
	 * Record a flush.
	 *
	 * @return void
	 */
	public function flush(): void {
		++$this->flushes;
	}
}

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

/**
 * AcfCopier reference check and dispatch, with closures instead of ACF.
 */
class AcfCopierTest extends WP_UnitTestCase {

	/**
	 * Calls made to the sink.
	 *
	 * @var string[]
	 */
	private array $calls = array();

	/**
	 * Copier whose ACF reference is fixed.
	 *
	 * @param mixed $ref Reference stored for the field name.
	 * @return AcfCopier
	 */
	private function copier( mixed $ref ): AcfCopier {
		$this->calls = array();
		return new AcfCopier(
			null,
			static fn( int $id, string $name ) => $ref,
			static fn( int $id, array $field ) => 'v' . $id,
			function ( string $kind, mixed $value, int $id, array $field ): void {
				$this->calls[] = $kind . ':' . $value . ':' . $field['name'];
			}
		);
	}

	/**
	 * A matching reference copies, one dispatch per field type.
	 */
	public function test_dispatch_per_type() {
		$copier = $this->copier( 'field_1' );
		foreach ( array(
			'repeater' => 'series',
			'group'    => 'group',
			'clone'    => 'group',
			'text'     => 'scalar',
		) as $type => $kind ) {
			$this->assertTrue(
				$copier->copy(
					4,
					array(
						'key'  => 'field_1',
						'name' => 'n_' . $type,
						'type' => $type,
					)
				)
			);
			$this->assertSame( $kind . ':v4:n_' . $type, end( $this->calls ) );
		}
	}

	/**
	 * A cloned field matches by its source key; any other reference copies nothing.
	 */
	public function test_reference_check() {
		$field = array(
			'key'   => 'field_clone_x',
			'__key' => 'field_src',
			'name'  => 'c',
			'type'  => 'text',
		);
		$this->assertTrue( $this->copier( 'field_src' )->copy( 1, $field ) );
		$this->assertFalse( $this->copier( 'field_other' )->copy( 1, $field ) );
		$this->assertFalse( $this->copier( false )->copy( 1, $field ) );
		$this->assertSame( array(), $this->calls );
	}
}
