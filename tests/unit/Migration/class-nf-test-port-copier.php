<?php
/**
 * Test double: value copier.
 *
 * @package NF
 */

use NF\Migration\AcfCopier;

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
	 * @param int|string          $post_id Object.
	 * @param array<string,mixed> $field   Field.
	 * @return bool
	 */
	public function copy( int|string $post_id, array $field ): bool {
		$this->calls[] = $post_id . ':' . $field['name'];
		return 0 === ( (int) preg_replace( '/\D/', '', (string) $post_id ) ) % 2;
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
