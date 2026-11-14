<?php
/**
 * Test double: ACF source.
 *
 * @package NF
 */

use NF\Migration\AcfSource;

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
