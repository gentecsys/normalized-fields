<?php
/**
 * Plugin service wiring.
 *
 * @package NF
 */

declare(strict_types=1);

namespace NF;

defined( 'ABSPATH' ) || exit;

/**
 * Wires services. Later WPs add their services to services() and each registers its own hooks.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Boot NF once.
	 *
	 * @return Plugin
	 */
	public static function boot(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
			add_action( 'plugins_loaded', array( self::$instance, 'register' ) );
		}
		return self::$instance;
	}

	/**
	 * Services to register. Each exposes a register() method that adds its hooks.
	 *
	 * @return object[]
	 */
	private function services(): array {
		return array();
	}

	/**
	 * Register every service, then announce that NF has loaded.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( $this->services() as $service ) {
			$service->register();
		}
		/**
		 * Fires once NF has loaded.
		 */
		do_action( 'nf_loaded' );
	}
}
