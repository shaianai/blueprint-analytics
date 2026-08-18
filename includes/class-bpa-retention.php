<?php
/**
 * Deletes event rows older than the retention period.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BPA_Retention {

	/**
	 * How many months of event data to keep.
	 * Change this value to adjust retention.
	 */
	const RETENTION_MONTHS = 12;

	const HOOK = 'bpa_daily_cleanup';

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}

	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * Deletes anything older than the cutoff.
	 * Capped per run so a large backlog cannot time out the site.
	 */
	public static function cleanup() {
		global $wpdb;

		$table  = BPA_Database::table_name();
		$cutoff = gmdate( 'Y-m-d', strtotime( '-' . self::RETENTION_MONTHS . ' months' ) );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table WHERE event_day < %s LIMIT 5000",
				$cutoff
			)
		);
	}
}