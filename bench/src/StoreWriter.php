<?php
/**
 * A target store for the dataset generator.
 *
 * @package NF\Bench
 */

declare(strict_types=1);

namespace NF\Bench;

/**
 * Implemented by the ACF store (WP 0.3a) and the NF store (WP 0.3b, after the
 * schema and store exist).
 */
interface StoreWriter {

	/**
	 * Number of dataset posts already present (0 when empty).
	 *
	 * @param Dataset $dataset Dataset.
	 */
	public function existing( Dataset $dataset ): int;

	/**
	 * Remove every post and field value of the dataset's ID range.
	 *
	 * @param Dataset $dataset Dataset.
	 */
	public function clear( Dataset $dataset ): void;

	/**
	 * Write the whole dataset.
	 *
	 * @param Dataset $dataset Dataset.
	 */
	public function write( Dataset $dataset ): void;

	/**
	 * Read the store back and return the number of mismatching posts.
	 *
	 * @param Dataset $dataset Dataset.
	 */
	public function verify( Dataset $dataset ): int;
}
