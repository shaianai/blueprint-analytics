<?php
/**
 * Creates and maintains the analytics logbook table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BPA_Database {

	/**
	 * Returns the full table name, including this site's prefix.
	 * Always use this instead of writing the table name by hand.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'bpa_events';
	}

	/**
	 * Creates the table, or updates it if the structure has changed.
	 */
	public static function install() {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		// Formatting below matters. dbDelta() is strict about it.
		$sql = "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			consultant_id bigint(20) unsigned NOT NULL,
			event_type varchar(20) NOT NULL,
			visitor_hash char(64) NOT NULL,
			event_day date NOT NULL,
			created_at datetime NOT NULL,
			source_page varchar(255) DEFAULT NULL,
			dedupe_key char(64) DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY dedupe_key (dedupe_key),
			KEY consultant_lookup (consultant_id,event_type,event_day),
			KEY day_lookup (event_day)
		) $charset;";

		// dbDelta() lives in a file WordPress doesn't load by default.
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Remember which structure version is installed.
		update_option( 'bpa_db_version', BPA_DB_VERSION );
	}

	/**
	 * Runs on every load, but only acts if the installed version is out of date.
	 * This is how future structure changes get applied without manual steps.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'bpa_db_version' ) !== BPA_DB_VERSION ) {
			self::install();
		}
	}
    	/**
         * Writes one event. Returns true if a row was created,
         * false if it was a duplicate the database rejected.
         */
        public static function insert_event( $consultant_id, $event_type, $visitor_hash, $event_day, $created_at, $source_page, $dedupe_key ) {
            global $wpdb;

            $table = self::table_name();

            /*
            * IMPORTANT: dedupe_key must be a genuine NULL for clicks.
            *
            * $wpdb->prepare() turns a PHP null into an empty string ''.
            * An empty string is a REAL value, so the unique constraint would
            * apply to it, and the second phone click would be rejected.
            *
            * So when there is no dedupe key, we write the word NULL directly
            * into the query instead of passing it as a value.
            */
            if ( null === $dedupe_key ) {
                $sql = $wpdb->prepare(
                    "INSERT IGNORE INTO $table
                    (consultant_id, event_type, visitor_hash, event_day, created_at, source_page, dedupe_key)
                    VALUES (%d, %s, %s, %s, %s, %s, NULL)",
                    $consultant_id,
                    $event_type,
                    $visitor_hash,
                    $event_day,
                    $created_at,
                    $source_page
                );
            } else {
                $sql = $wpdb->prepare(
                    "INSERT IGNORE INTO $table
                    (consultant_id, event_type, visitor_hash, event_day, created_at, source_page, dedupe_key)
                    VALUES (%d, %s, %s, %s, %s, %s, %s)",
                    $consultant_id,
                    $event_type,
                    $visitor_hash,
                    $event_day,
                    $created_at,
                    $source_page,
                    $dedupe_key
                );
            }

            $wpdb->query( $sql );

            // 1 = a row was inserted. 0 = a duplicate was ignored.
            return $wpdb->rows_affected > 0;
        }
}