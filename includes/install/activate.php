<?php
/**
 * Activation and deactivation. Table creation (dbDelta) arrives in a later WP.
 *
 * @package NF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Activation callback: refuse to activate on unsupported environments.
 *
 * @return void
 */
function nf_activate(): void {
	$errors = nf_requirement_errors();
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
function nf_deactivate(): void {}
