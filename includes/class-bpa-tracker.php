<?php
/**
 * The rules layer: visitor identity, internal exclusion, bot filtering.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BPA_Tracker {

	/**
	 * The three event types we accept. Anything else is rejected.
	 */
	const EVENT_TYPES = array( 'profile_view', 'phone_click', 'website_click' );

	/**
	 * Today's date in the SITE's timezone (Sydney), as YYYY-MM-DD.
	 * We never use plain PHP date() here, because that uses server time.
	 */
	public static function today() {
		return current_time( 'Y-m-d' );
	}

	/**
	 * The current date and time in the site's timezone.
	 */
	public static function now() {
		return current_time( 'mysql' );
	}

	/**
	 * Best guess at the visitor's IP, accounting for proxies and CDNs.
	 * Used only to build the fingerprint. Never stored.
	 */
	private static function get_ip() {
		$headers = array(
			'HTTP_CF_CONNECTING_IP',   // Cloudflare
			'HTTP_X_FORWARDED_FOR',    // most proxies and CDNs
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',             // the direct connection
		);

		foreach ( $headers as $header ) {
			if ( empty( $_SERVER[ $header ] ) ) {
				continue;
			}

			// X-Forwarded-For can be a list: "real-ip, proxy1, proxy2".
			// The first entry is the original visitor.
			$candidates = explode( ',', wp_unslash( $_SERVER[ $header ] ) );
			$ip         = trim( $candidates[0] );

			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}

		return '0.0.0.0';
	}

	/**
	 * The visitor's browser identifier, trimmed for safety.
	 */
	private static function get_user_agent() {
		if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}
		return substr( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 0, 255 );
	}

	/**
	 * Builds the anonymous visitor fingerprint.
	 *
	 * Same visitor + same day  = same fingerprint (so we spot repeats)
	 * Same visitor + next day  = different fingerprint (no cross-day tracking)
	 * Cannot be reversed to recover the IP.
	 */
	public static function visitor_hash() {
		$ingredients = self::get_ip() . '|' . self::get_user_agent() . '|' . self::today();

		// wp_salt() is a long random value unique to this site.
		return hash_hmac( 'sha256', $ingredients, wp_salt( 'auth' ) );
	}

	/**
	 * The duplicate-prevention key, for profile views only.
	 * Clicks pass null, so the unique constraint ignores them.
	 */
	public static function dedupe_key( $consultant_id, $event_type ) {
		if ( 'profile_view' !== $event_type ) {
			return null;
		}

		$parts = $consultant_id . '|' . self::visitor_hash() . '|' . self::today();

		return hash_hmac( 'sha256', $parts, wp_salt( 'auth' ) );
	}

	/**
	 * Is this internal traffic we should ignore?
	 *
	 * edit_posts covers Administrator and Author on this site.
	 * Customer and Subscriber do NOT have it, so they are counted.
	 *
	 * To exclude ALL logged-in users instead, replace the body with:
	 *   return is_user_logged_in();
	 */
	public static function is_internal_traffic() {
		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	/**
	 * Is this obvious bot traffic?
	 */
	public static function is_bot() {
		$ua = self::get_user_agent();

		// No browser identifier at all is not a real visitor.
		if ( '' === $ua ) {
			return true;
		}

		$signatures = array(
			'bot', 'crawl', 'spider', 'slurp', 'scrape', 'headless',
			'lighthouse', 'pingdom', 'uptime', 'monitor', 'preview',
			'curl', 'wget', 'python', 'java/', 'go-http', 'axios',
			'facebookexternalhit', 'whatsapp', 'telegram',
			'screaming frog', 'semrush', 'ahrefs', 'mj12', 'dotbot',
		);

		$ua_lower = strtolower( $ua );

		foreach ( $signatures as $signature ) {
			if ( false !== strpos( $ua_lower, $signature ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Is this a valid event type?
	 */
	public static function is_valid_event_type( $event_type ) {
		return in_array( $event_type, self::EVENT_TYPES, true );
	}

	/**
	 * Is this a real, published consultant?
	 * Rejects drafts, other content types, and made-up IDs.
	 */
	public static function is_valid_consultant( $consultant_id ) {
		$consultant_id = absint( $consultant_id );

		if ( ! $consultant_id ) {
			return false;
		}

		$post = get_post( $consultant_id );

		if ( ! $post ) {
			return false;
		}

		return 'business' === $post->post_type && 'publish' === $post->post_status;
	}

	/**
	 * The single gate. Everything must pass before anything is recorded.
	 */
	public static function should_record( $consultant_id, $event_type ) {
		if ( ! self::is_valid_event_type( $event_type ) ) {
			return false;
		}
		if ( ! self::is_valid_consultant( $consultant_id ) ) {
			return false;
		}
		if ( self::is_internal_traffic() ) {
			return false;
		}
		if ( self::is_bot() ) {
			return false;
		}
		return true;
	}
}