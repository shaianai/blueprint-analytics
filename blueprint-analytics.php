<?php
/**
 * Plugin Name:       Blueprint Analytics
 * Description:       Admin-only consultant engagement analytics for Blueprint Collective.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WYN Digital
 * License:           GPL-2.0-or-later
 */

// Stop this file doing anything if someone opens it directly in a browser.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The plugin's own version number.
define( 'BPA_VERSION', '0.1.0' );

// The version of our database structure. We bump this when the table changes.
define( 'BPA_DB_VERSION', '1' );

// The folder path on the server, e.g. /app/public/wp-content/plugins/blueprint-analytics/
define( 'BPA_PATH', plugin_dir_path( __FILE__ ) );

// The web address of the folder. We'll need this to load our JavaScript later.
define( 'BPA_URL', plugin_dir_url( __FILE__ ) );

/**
 * Temporary proof-of-life message in the admin.
 * We delete this in Step 2. It exists only to confirm the plugin is loading.
 */
// Load our database class.
require_once BPA_PATH . 'includes/class-bpa-database.php';

/**
 * Runs once, the moment the plugin is activated.
 */
function bpa_activate() {
	BPA_Database::install();
}
register_activation_hook( __FILE__, 'bpa_activate' );

/**
 * Runs on every load, but only acts if the table structure is outdated.
 */
add_action( 'plugins_loaded', 'BPA_Database::maybe_upgrade' );

/**
 * Temporary confirmation notice. Removed in Step 3.
 */
function bpa_temp_table_notice() {
	global $wpdb;
	$table  = BPA_Database::table_name();
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

	if ( $exists === $table ) {
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		echo '<div class="notice notice-success"><p><strong>Blueprint Analytics:</strong> table <code>' . esc_html( $table ) . '</code> exists. Rows: ' . esc_html( $count ) . '</p></div>';
	} else {
		echo '<div class="notice notice-error"><p><strong>Blueprint Analytics:</strong> table missing. Deactivate and reactivate the plugin.</p></div>';
	}
}
add_action( 'admin_notices', 'bpa_temp_table_notice' );
