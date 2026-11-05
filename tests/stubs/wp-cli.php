<?php
/**
 * Minimal WP-CLI stubs for PHPStan. php-stubs/wp-cli-stubs does not yet allow
 * php-stubs/wordpress-stubs 7.x, so only the methods NF uses are declared here.
 *
 * @package NF
 */

// phpcs:disable

class WP_CLI {
	/**
	 * @param string   $tag      Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted arguments.
	 */
	public static function add_wp_hook( $tag, $callback, $priority = 10, $args = 1 ): void {}

	/**
	 * @param string          $name     Command name.
	 * @param callable|string $callable Command class or callable.
	 * @param array<string, mixed> $args Registration args.
	 */
	public static function add_command( $name, $callable, $args = array() ): bool {}

	/**
	 * @param string|\WP_Error|\Throwable $message Message.
	 * @param bool|int                    $exit    Exit code, or false to continue.
	 * @return never
	 */
	public static function error( $message, $exit = true ) {}

	/** @param string $message Message. */
	public static function success( $message ): void {}

	/** @param string $message Message. */
	public static function line( $message = '' ): void {}

	/** @param string $message Message. */
	public static function warning( $message ): void {}

	/** @param string $message Message. */
	public static function log( $message ): void {}
}
