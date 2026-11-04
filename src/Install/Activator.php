<?php
/**
 * Activation and deactivation. Table creation (dbDelta) arrives in a later WP.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Install;

use NF\Core\Requirements;

defined( 'ABSPATH' ) || exit;

/**
 * Activation and deactivation callbacks.
 */
final class Activator {

	/**
	 * Activation callback: refuse to activate on unsupported environments.
	 *
	 * @return void
	 */
	public static function activate(): void {
		$errors = Requirements::errors();
		if ( $errors ) {
			deactivate_plugins( plugin_basename( NF_FILE ) );
			wp_die( esc_html( implode( ' ', $errors ) ), '', array( 'back_link' => true ) );
		}
		update_option( 'nf_version', NF_VERSION, false );
	}

	/**
	 * Deactivation callback. Data is kept.
	 *
	 * @return void
	 */
	public static function deactivate(): void {}
}
