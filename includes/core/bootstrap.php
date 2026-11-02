<?php
/**
 * Plugin bootstrap. Later WPs hook their loaders in here.
 *
 * @package NF
 */

defined( 'ABSPATH' ) || exit;

/**
 * Boot NF on plugins_loaded.
 *
 * @return void
 */
function nf_bootstrap(): void {
	/**
	 * Fires once NF has loaded.
	 */
	do_action( 'nf_loaded' );
}
