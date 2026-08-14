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
function bpa_temporary_hello_notice() {
	echo '<div class="notice notice-success"><p><strong>Blueprint Analytics</strong> is active. Version ' . esc_html( BPA_VERSION ) . '</p></div>';
}
add_action( 'admin_notices', 'bpa_temporary_hello_notice' );