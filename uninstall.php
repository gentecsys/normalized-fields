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
delete_option( 'nf_upgrade_failed' );
delete_option( 'nf_needs_resync' );
delete_option( 'nf_count_cleanup_at' );
$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( "DELETE FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE %s", $GLOBALS['wpdb']->esc_like( 'nf_counts_ready_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
