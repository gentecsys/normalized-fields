<?php
/**
 * AcfCopier tests (WP 2.6b).
 *
 * @package NF
 */

use NF\Migration\AcfCopier;

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
			static function ( int $id, string $name ) use ( $ref ) {
				unset( $id, $name );
				return $ref;
			},
			static function ( int $id, array $field ) {
				unset( $field );
				return 'v' . $id;
			},
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
