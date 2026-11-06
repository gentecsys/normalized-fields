<?php
/**
 * Read-only scenario over the bench dataset: scalar, checkbox, relationship and Series (sessions) reads.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

namespace NF\Tests\Differential;

final class BenchReadScenario implements ScenarioInterface {

	/**
	 * @param int[]    $post_ids Post IDs to read.
	 * @param string[] $fields   Field names to read with get_field().
	 */
	public function __construct(
		private readonly array $post_ids,
		private readonly array $fields,
	) {}

	public function name(): string {
		return 'bench-read';
	}

	public function steps(): array {
		$steps = array();
		foreach ( $this->post_ids as $post_id ) {
			foreach ( $this->fields as $field ) {
				$steps[] = new Step( 'get_field', array( $field, $post_id ), "get_field:{$field}:{$post_id}" );
				$steps[] = new Step( 'get_field', array( $field, $post_id, false ), "get_field_raw:{$field}:{$post_id}" );
			}
			$steps[] = new Step( 'get_fields', array( $post_id ), "get_fields:{$post_id}" );
		}
		return $steps;
	}
}
