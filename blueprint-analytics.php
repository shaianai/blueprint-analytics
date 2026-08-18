<?php
/**
 * Plugin Name:       Blueprint Analytics
 * Description:       Admin-only consultant engagement analytics for Blueprint Collective.
 * Version:           0.1.1
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
define( 'BPA_VERSION', '0.1.1' );

// The version of our database structure. We bump this when the table changes.
define( 'BPA_DB_VERSION', '1' );

// The folder path on the server, e.g. /app/public/wp-content/plugins/blueprint-analytics/
define( 'BPA_PATH', plugin_dir_path( __FILE__ ) );

// The web address of the folder. We'll need this to load our JavaScript later.
define( 'BPA_URL', plugin_dir_url( __FILE__ ) );

// Load our database class.
require_once BPA_PATH . 'includes/class-bpa-database.php';

/**
 * Runs once, the moment the plugin is activated.
 */
require_once BPA_PATH . 'includes/class-bpa-admin.php';

function bpa_activate() {
	BPA_Database::install();
	BPA_Admin::add_capability();
	BPA_Retention::schedule();
}
register_activation_hook( __FILE__, 'bpa_activate' );

function bpa_deactivate() {
	BPA_Retention::unschedule();
}
register_deactivation_hook( __FILE__, 'bpa_deactivate' );

add_action( 'admin_menu', 'BPA_Admin::register_menu' );

/**
 * Runs on every load, but only acts if the table structure is outdated.
 */
add_action( 'plugins_loaded', 'BPA_Database::maybe_upgrade' );

require_once BPA_PATH . 'includes/class-bpa-tracker.php';

add_action( 'rest_api_init', 'BPA_Tracker::register_routes' );
add_action( 'wp_enqueue_scripts', 'BPA_Tracker::enqueue_assets' );

require_once BPA_PATH . 'includes/class-bpa-retention.php';

add_action( BPA_Retention::HOOK, 'BPA_Retention::cleanup' );