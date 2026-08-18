<?php
/**
 * Runs only when the plugin is DELETED (not deactivated).
 *
 * We deliberately do NOT drop the events table. Analytics history is
 * client data, and an accidental deletion should not destroy it.
 * To remove the data, drop wp_bpa_events manually.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Remove our capability from all roles.
foreach ( wp_roles()->get_names() as $role_name => $label ) {
	$role = get_role( $role_name );
	if ( $role ) {
		$role->remove_cap( 'view_bpa_analytics' );
	}
}

delete_option( 'bpa_db_version' );