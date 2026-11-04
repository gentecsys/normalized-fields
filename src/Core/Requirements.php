<?php
/**
 * Environment requirement checks.
 *
 * Deliberately avoids PHP 8.1 syntax: it loads before the autoloader on any PHP version.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Checks PHP and WordPress minimums and shows admin notices.
 */
final class Requirements {

	/**
	 * List the unmet requirements.
	 *
	 * @return string[] Human-readable problems; empty when all requirements are met.
	 */
	public static function errors(): array {
		global $wp_version;
		$errors = array();
		if ( version_compare( PHP_VERSION, NF_MIN_PHP, '<' ) ) {
			/* translators: 1: required PHP version, 2: current PHP version. */
			$errors[] = sprintf( __( 'Normalized Fields requires PHP %1$s or higher (running %2$s).', 'normalized-fields' ), NF_MIN_PHP, PHP_VERSION );
		}
		// Strip pre-release suffixes (7.0-beta1, 7.1-alpha-123) so nightly builds pass.
		$wp_core_version = isset( $wp_version ) ? preg_replace( '/-.*$/', '', (string) $wp_version ) : '';
		if ( '' !== $wp_core_version && version_compare( $wp_core_version, NF_MIN_WP, '<' ) ) {
			/* translators: 1: required WordPress version, 2: current WordPress version. */
			$errors[] = sprintf( __( 'Normalized Fields requires WordPress %1$s or higher (running %2$s).', 'normalized-fields' ), NF_MIN_WP, $wp_version );
		}
		return $errors;
	}

	/**
	 * Whether the environment meets the minimum requirements.
	 *
	 * @return bool
	 */
	public static function met(): bool {
		return array() === self::errors();
	}

	/**
	 * Show an admin notice listing unmet requirements.
	 *
	 * @return void
	 */
	public static function notice(): void {
		foreach ( self::errors() as $error ) {
			self::print_notice( $error );
		}
	}

	/**
	 * Show an admin notice when the Composer autoloader is missing.
	 *
	 * @return void
	 */
	public static function autoload_notice(): void {
		self::print_notice( __( 'Normalized Fields could not start: vendor/autoload.php is missing. Install a release build or run "composer install".', 'normalized-fields' ) );
	}

	/**
	 * Print one error notice to users who can manage plugins.
	 *
	 * @param string $message Message text.
	 * @return void
	 */
	private static function print_notice( string $message ): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
	}
}
