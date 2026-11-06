<?php
/**
 * Differential harness bootstrap: a tiny PSR-4 loader, so the harness runs without WordPress.
 *
 * @package NormalizedFields
 */

declare(strict_types=1);

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'NF\\Tests\\Differential\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
