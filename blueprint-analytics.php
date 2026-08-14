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

require_once BPA_PATH . 'includes/class-bpa-tracker.php';

/**
 * TEMPORARY diagnostic panel. Removed in Step 4.
 * Shows how the rules evaluate for whoever is looking at it.
 */
function bpa_temp_rules_panel() {
	$is_internal = BPA_Tracker::is_internal_traffic();
	$is_bot      = BPA_Tracker::is_bot();
	$hash        = BPA_Tracker::visitor_hash();

	echo '<div class="notice notice-info"><p><strong>Blueprint Analytics diagnostics</strong></p><ul style="margin-left:1em">';
	echo '<li>Site date (Sydney): <code>' . esc_html( BPA_Tracker::today() ) . '</code></li>';
	echo '<li>Site time: <code>' . esc_html( BPA_Tracker::now() ) . '</code></li>';
	echo '<li>Your fingerprint: <code>' . esc_html( substr( $hash, 0, 16 ) ) . '...</code></li>';
	echo '<li>Internal traffic (excluded)? <strong>' . ( $is_internal ? 'YES' : 'no' ) . '</strong></li>';
	echo '<li>Detected as bot? <strong>' . ( $is_bot ? 'YES' : 'no' ) . '</strong></li>';
	echo '<li>Would a view be recorded? <strong>' . ( ( ! $is_internal && ! $is_bot ) ? 'yes' : 'NO' ) . '</strong></li>';
	echo '</ul></div>';
    echo '<li>REMOTE_ADDR: <code>' . esc_html( $_SERVER['REMOTE_ADDR'] ?? 'none' ) . '</code></li>';
    echo '<li>X-Forwarded-For: <code>' . esc_html( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'none' ) . '</code></li>';
}
add_action( 'admin_notices', 'bpa_temp_rules_panel' );
