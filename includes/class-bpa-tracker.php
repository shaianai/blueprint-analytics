<?php
/**
 * The rules layer: visitor identity, internal exclusion, bot filtering.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BPA_Tracker {

	/**
	 * Registers our REST endpoint.
	 */
	public static function register_routes() {
		register_rest_route(
			'blueprint-analytics/v1',
			'/event',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_event' ),
				// Public endpoint: anonymous visitors must be able to reach it.
				// Protection comes from validation and rate limiting, not login.
				'permission_callback' => '__return_true',
				'args'                => array(
					'consultant_id' => array(
						'required'          => true,
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && absint( $value ) > 0;
						},
						'sanitize_callback' => 'absint',
					),
					'event_type'    => array(
						'required'          => true,
						'validate_callback' => array( __CLASS__, 'is_valid_event_type' ),
						'sanitize_callback' => 'sanitize_key',
					),
					'source_page'   => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Simple rate limit: max 60 events per visitor per minute.
	 * Generous for a real person, restrictive for a script.
	 */
	private static function is_rate_limited() {
		$key   = 'bpa_rl_' . substr( self::visitor_hash(), 0, 32 );
		$count = (int) get_transient( $key );

		if ( $count >= 60 ) {
			return true;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * Handles an incoming event.
	 */
	public static function handle_event( WP_REST_Request $request ) {
		$consultant_id = $request->get_param( 'consultant_id' );
		$event_type    = $request->get_param( 'event_type' );
		$source_page   = $request->get_param( 'source_page' );

		if ( self::is_rate_limited() ) {
			return new WP_REST_Response( null, 429 );
		}

		// The single gate from Step 3.
		if ( ! self::should_record( $consultant_id, $event_type ) ) {
			// Deliberately still a 204. We never reveal WHY something
			// was rejected, because that tells a probing script how
			// to get past the filters.
			return new WP_REST_Response( null, 204 );
		}

		BPA_Database::insert_event(
			$consultant_id,
			$event_type,
			self::visitor_hash(),
			self::today(),
			self::now(),
			$source_page ? substr( $source_page, 0, 255 ) : null,
			self::dedupe_key( $consultant_id, $event_type )
		);

		return new WP_REST_Response( null, 204 );
	}

    /**
	 * Loads the tracking script on single consultant profiles.
	 *
	 * Note: we load this for EVERYONE, including administrators.
	 * See 4.3 above. The exclusion happens at the endpoint,
	 * so the cached HTML stays identical for all visitors.
	 */
	public static function enqueue_assets() {
		if ( ! is_singular( 'business' ) ) {
			return;
		}

		$consultant_id = get_queried_object_id();

		if ( ! $consultant_id ) {
			return;
		}

		wp_enqueue_script(
			'bpa-tracker',
			BPA_URL . 'assets/js/bpa-tracker.js',
			array(),
			BPA_VERSION,
			true // load in the footer, after the page content
		);

		wp_localize_script(
			'bpa-tracker',
			'bpaData',
			array(
				'endpoint'     => rest_url( 'blueprint-analytics/v1/event' ),
				'consultantId' => (int) $consultant_id,
			)
		);
	}

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