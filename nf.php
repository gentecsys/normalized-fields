<?php
/**
 * Plugin Name:       NF (Normalized Fields)
 * Description:       ACF-compatible custom fields stored in typed, pivot and lookup tables instead of wp_postmeta.
 * Version:           0.1.0
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * Text Domain:       nf
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

require_once NF_PATH . 'includes/core/requirements.php';
require_once NF_PATH . 'includes/install/activate.php';

register_activation_hook( __FILE__, 'nf_activate' );
register_deactivation_hook( __FILE__, 'nf_deactivate' );

if ( nf_requirements_met() ) {
	require_once NF_PATH . 'includes/core/bootstrap.php';
	add_action( 'plugins_loaded', 'nf_bootstrap' );
} else {
	add_action( 'admin_notices', 'nf_requirements_notice' );
}
