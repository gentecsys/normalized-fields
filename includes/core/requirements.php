<?php
/**
 * Environment requirement checks.
 *
 * @package NF
 */

defined( 'ABSPATH' ) || exit;

/**
 * List the unmet requirements.
 *
 * @return string[] Human-readable problems; empty when all requirements are met.
 */
function nf_requirement_errors(): array {
	global $wp_version;
	$errors = array();
	if ( version_compare( PHP_VERSION, NF_MIN_PHP, '<' ) ) {
		/* translators: 1: required PHP version, 2: current PHP version. */
		$errors[] = sprintf( __( 'NF requires PHP %1$s or higher (running %2$s).', 'nf' ), NF_MIN_PHP, PHP_VERSION );
	}
	if ( isset( $wp_version ) && version_compare( $wp_version, NF_MIN_WP, '<' ) ) {
		/* translators: 1: required WordPress version, 2: current WordPress version. */
		$errors[] = sprintf( __( 'NF requires WordPress %1$s or higher (running %2$s).', 'nf' ), NF_MIN_WP, $wp_version );
	}
	return $errors;
}

/**
 * Whether the environment meets the minimum requirements.
 *
 * @return bool
 */
function nf_requirements_met(): bool {
	return array() === nf_requirement_errors();
}

/**
 * Show an admin notice listing unmet requirements.
 *
 * @return void
 */
function nf_requirements_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	foreach ( nf_requirement_errors() as $error ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error ) );
	}
}
