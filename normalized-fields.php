<?php
/**
 * Plugin Name:       Normalized Fields
 * Description:       ACF-compatible custom fields stored in typed, pivot and lookup tables instead of wp_postmeta.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * Text Domain:       normalized-fields
 *
 * @package NF
 */

defined( 'ABSPATH' ) || exit;

define( 'NF_VERSION', '0.1.0' );
define( 'NF_MIN_WP', '7.0' );
define( 'NF_MIN_PHP', '8.1' );
define( 'NF_FILE', __FILE__ );
define( 'NF_PATH', plugin_dir_path( __FILE__ ) );
define( 'NF_URL', plugin_dir_url( __FILE__ ) );

// Requirements is kept free of PHP 8.1 syntax so it loads on old PHP and can report the problem.
require_once NF_PATH . 'src/Core/Requirements.php';

if ( ! NF\Core\Requirements::met() ) {
	add_action( 'admin_notices', array( 'NF\Core\Requirements', 'notice' ) );
	return;
}

if ( ! is_readable( NF_PATH . 'vendor/autoload.php' ) ) {
	add_action( 'admin_notices', array( 'NF\Core\Requirements', 'autoload_notice' ) );
	return;
}

require_once NF_PATH . 'vendor/autoload.php';

register_activation_hook( __FILE__, array( 'NF\Install\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NF\Install\Activator', 'deactivate' ) );

NF\Plugin::boot();
