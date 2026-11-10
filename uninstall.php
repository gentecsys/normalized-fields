<?php
/**
 * Uninstall. Never drops field data; only removes the version option.
 *
 * @package NF
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'nf_version' );
delete_option( 'nf_db_version' );
delete_option( 'nf_upgrade_lock' );
