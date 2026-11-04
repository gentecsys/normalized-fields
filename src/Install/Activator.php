<?php
/**
 * Deactivation and, later, install/upgrade (dbDelta). Activation refusal lives in Requirements so it works without the autoloader.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Activation and deactivation callbacks.
 */
final class Activator {

	/**
	 * Deactivation callback. Data is kept.
	 *
	 * @return void
	 */
	public static function deactivate(): void {}
}
