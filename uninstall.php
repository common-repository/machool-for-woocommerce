<?php
/**
 * @package Machool
 */

// If uninstall not called from WordPress, then exit.
if (!defined( 'WP_UNINSTALL_PLUGIN')) {
	exit;
}

/**
 * To be used when running clean up for uninstalls or store disconnection.
 */
function machool_woocommerce_uninstall() {
	global $wpdb;

	// delete plugin options
	$plugin_options = $wpdb->get_results( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE '%machool%'" );

	foreach( $plugin_options as $option ) {
		delete_option( $option->option_name );
	}
}
if (!is_multisite()) {
	machool_woocommerce_uninstall();
} else {
	global $wpdb;
	try {
		foreach ($wpdb->get_col("SELECT blog_id FROM $wpdb->blogs") as $machool_current_wp_id) {
			switch_to_blog($machool_current_wp_id);
			machool_woocommerce_uninstall();
		}
		restore_current_blog();
	} catch (\Exception $e) {}
}