<?php
/**
 * Registers `wp nf-bench`. Use: wp --require=bench/bootstrap.php nf-bench generate --posts=10000
 *
 * Never loaded by the plugin itself.
 *
 * @package NF\Bench
 */

declare(strict_types=1);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/src/Dataset.php';
	require_once __DIR__ . '/src/AcfMeta.php';
	require_once __DIR__ . '/src/AcfSchema.php';
	require_once __DIR__ . '/src/StoreWriter.php';
	require_once __DIR__ . '/src/AcfStore.php';
	require_once __DIR__ . '/src/NfLayout.php';
	require_once __DIR__ . '/src/NfStore.php';
	require_once __DIR__ . '/src/DatasetCommand.php';
	WP_CLI::add_command( 'nf-bench', NF\Bench\DatasetCommand::class );

	// WP-CLI queues these into $wp_filter before WordPress loads, so they are in place
	// before `init` and before ACF reads Local JSON on `acf/init`.
	WP_CLI::add_wp_hook(
		'init',
		static function (): void {
			register_post_type(
				NF\Bench\Dataset::POST_TYPE,
				array(
					'label'  => 'NF Bench Events',
					'public' => true,
				)
			);
		}
	);
	WP_CLI::add_wp_hook(
		'acf/settings/load_json',
		static function ( array $paths ): array {
			$paths[] = __DIR__ . '/acf-json';
			return $paths;
		}
	);
}
