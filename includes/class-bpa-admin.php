<?php
/**
 * Admin menu, dashboard page, and the reporting queries.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BPA_Admin {

	const CAPABILITY = 'view_bpa_analytics';

	/**
	 * Grants our capability to Administrator. Runs on activation.
	 */
	public static function add_capability() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( self::CAPABILITY );
		}
	}

	/**
	 * Adds the top-level admin menu item.
	 */
	public static function register_menu() {
		add_menu_page(
			'Blueprint Analytics',        // browser tab title
			'Blueprint Analytics',        // sidebar label
			self::CAPABILITY,             // who can see it
			'blueprint-analytics',        // page slug
			array( __CLASS__, 'render_page' ),
			'dashicons-chart-bar',
			30
		);
	}

	/**
	 * Reads and validates the filters from the address bar.
	 */
	private static function get_filters() {
		// Defaults: the last 30 days, in the site's timezone.
		$default_to   = current_time( 'Y-m-d' );
		$default_from = gmdate( 'Y-m-d', strtotime( $default_to . ' -29 days' ) );

		$from = isset( $_GET['bpa_from'] ) ? sanitize_text_field( wp_unslash( $_GET['bpa_from'] ) ) : '';
		$to   = isset( $_GET['bpa_to'] ) ? sanitize_text_field( wp_unslash( $_GET['bpa_to'] ) ) : '';

		if ( ! self::is_valid_date( $from ) ) {
			$from = $default_from;
		}
		if ( ! self::is_valid_date( $to ) ) {
			$to = $default_to;
		}

		// If the dates are backwards, swap them rather than showing nothing.
		if ( $from > $to ) {
			$swap = $from;
			$from = $to;
			$to   = $swap;
		}

		$consultant = isset( $_GET['bpa_consultant'] ) ? absint( $_GET['bpa_consultant'] ) : 0;

		return array(
			'from'       => $from,
			'to'         => $to,
			'consultant' => $consultant,
		);
	}

	/**
	 * Is this a real YYYY-MM-DD date?
	 */
	private static function is_valid_date( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		$date = DateTime::createFromFormat( 'Y-m-d', $value );
		return $date && $date->format( 'Y-m-d' ) === $value;
	}

	/**
	 * Builds the shared WHERE clause for all our queries.
	 * Returns the SQL fragment and its values, kept separate for prepare().
	 */
	private static function build_where( $filters ) {
		$sql    = 'WHERE event_day BETWEEN %s AND %s';
		$values = array( $filters['from'], $filters['to'] );

		if ( $filters['consultant'] > 0 ) {
			$sql     .= ' AND consultant_id = %d';
			$values[] = $filters['consultant'];
		}

		return array( $sql, $values );
	}

	/**
	 * One page of consultant rows.
	 */
	public static function get_rows( $filters, $per_page, $offset ) {
		global $wpdb;

		$table = BPA_Database::table_name();
		list( $where, $values ) = self::build_where( $filters );

		$sql = "SELECT
					consultant_id,
					SUM(event_type = 'profile_view')  AS views,
					SUM(event_type = 'phone_click')   AS phone,
					SUM(event_type = 'website_click') AS website
				FROM $table
				$where
				GROUP BY consultant_id
				ORDER BY views DESC, consultant_id ASC
				LIMIT %d OFFSET %d";

		$values[] = $per_page;
		$values[] = $offset;

		return $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A );
	}

	/**
	 * How many consultants have any activity in this range?
	 * Needed for pagination.
	 */
	public static function count_consultants( $filters ) {
		global $wpdb;

		$table = BPA_Database::table_name();
		list( $where, $values ) = self::build_where( $filters );

		$sql = "SELECT COUNT(DISTINCT consultant_id) FROM $table $where";

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * The TOTAL row: the whole filtered set, NOT just the visible page.
	 */
	public static function get_totals( $filters ) {
		global $wpdb;

		$table = BPA_Database::table_name();
		list( $where, $values ) = self::build_where( $filters );

		$sql = "SELECT
					SUM(event_type = 'profile_view')  AS views,
					SUM(event_type = 'phone_click')   AS phone,
					SUM(event_type = 'website_click') AS website
				FROM $table
				$where";

		$row = $wpdb->get_row( $wpdb->prepare( $sql, $values ), ARRAY_A );

		return array(
			'views'   => isset( $row['views'] ) ? (int) $row['views'] : 0,
			'phone'   => isset( $row['phone'] ) ? (int) $row['phone'] : 0,
			'website' => isset( $row['website'] ) ? (int) $row['website'] : 0,
		);
	}

	/**
	 * When did we last record anything at all?
	 * This is the silent-failure detector.
	 */
	/**
	 * When was each event type last recorded?
	 * Used by the tracking health panel to spot silent failures.
	 */
	public static function get_activity_by_type() {
		global $wpdb;

		$table = BPA_Database::table_name();

		$rows = $wpdb->get_results(
			"SELECT event_type, MAX(created_at) AS last_seen
			 FROM $table
			 GROUP BY event_type",
			ARRAY_A
		);

		$out = array(
			'profile_view'  => null,
			'phone_click'   => null,
			'website_click' => null,
		);

		foreach ( (array) $rows as $row ) {
			if ( array_key_exists( $row['event_type'], $out ) ) {
				$out[ $row['event_type'] ] = $row['last_seen'];
			}
		}

		return $out;
	}

	/**
	 * Renders the tracking health panel.
	 */
	private static function render_health_panel() {
		$activity = self::get_activity_by_type();

		$labels = array(
			'profile_view'  => 'Profile views',
			'phone_click'   => 'Phone interactions',
			'website_click' => 'Website clicks',
		);

		// Anything older than this prompts a look. A judgement call, not a rule.
		$stale_after = 7 * DAY_IN_SECONDS;
		$now         = current_time( 'timestamp' );
		$warnings    = array();

		echo '<div class="bpa-health" style="background:#fff;border:1px solid #c3c4c7;padding:12px 16px;margin:16px 0">';
		echo '<p style="margin:0 0 8px"><strong>Tracking health</strong> <span style="color:#646970">(all time)</span></p>';
		echo '<ul style="margin:0">';

		foreach ( $labels as $type => $label ) {
			$last = $activity[ $type ];

			if ( ! $last ) {
				echo '<li style="color:#b32d2e">' . esc_html( $label ) . ': <strong>never recorded</strong></li>';
				$warnings[] = $label;
				continue;
			}

			$age     = $now - strtotime( $last );
			$is_old  = $age > $stale_after;
			$colour  = $is_old ? '#996800' : '#1d7d3f';
			$suffix  = $is_old ? ' &mdash; worth checking' : '';

			if ( $is_old ) {
				$warnings[] = $label;
			}

			printf(
				'<li style="color:%s">%s: last recorded <strong>%s</strong> (%s ago)%s</li>',
				esc_attr( $colour ),
				esc_html( $label ),
				esc_html( $last ),
				esc_html( human_time_diff( strtotime( $last ), $now ) ),
				wp_kses_post( $suffix )
			);
		}

		echo '</ul>';

		if ( ! empty( $warnings ) ) {
			echo '<p style="margin:8px 0 0;color:#996800">';
			echo 'Some tracking looks stale. The most common cause is the Business ';
			echo 'template being rebuilt without the phone or website elements. ';
			echo 'See the plugin README before assuming it is a traffic drop.';
			echo '</p>';
		}

		echo '</div>';
	}

		/**
	 * How long dashboard results are remembered.
	 */
	const CACHE_SECONDS = 300; // 5 minutes

	/**
	 * Builds a cache name unique to this exact set of filters.
	 */
	private static function cache_key( $prefix, $parts ) {
		return 'bpa_' . $prefix . '_' . md5( wp_json_encode( $parts ) );
	}

	/**
	 * Should we skip the cache for this request?
	 */
	private static function bypass_cache() {
		return isset( $_GET['bpa_refresh'] );
	}

	/**
	 * Cached wrapper around get_rows().
	 */
	public static function get_rows_cached( $filters, $per_page, $offset ) {
		$key = self::cache_key( 'rows', array( $filters, $per_page, $offset ) );

		if ( ! self::bypass_cache() ) {
			$cached = get_transient( $key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$rows = self::get_rows( $filters, $per_page, $offset );
		set_transient( $key, $rows, self::CACHE_SECONDS );

		return $rows;
	}

	/**
	 * Cached wrapper around get_totals().
	 */
	public static function get_totals_cached( $filters ) {
		$key = self::cache_key( 'totals', array( $filters ) );

		if ( ! self::bypass_cache() ) {
			$cached = get_transient( $key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$totals = self::get_totals( $filters );
		set_transient( $key, $totals, self::CACHE_SECONDS );

		return $totals;
	}

	/**
	 * Cached wrapper around count_consultants().
	 */
	public static function count_consultants_cached( $filters ) {
		$key = self::cache_key( 'count', array( $filters ) );

		if ( ! self::bypass_cache() ) {
			$cached = get_transient( $key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$count = self::count_consultants( $filters );
		set_transient( $key, $count, self::CACHE_SECONDS );

		return $count;
	}

	/**
	 * Consultants for the dropdown.
	 */
	private static function get_consultant_options() {
		$posts = get_posts(
			array(
				'post_type'        => 'business',
				'post_status'      => 'publish',
				'posts_per_page'   => -1,
				'orderby'          => 'title',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);

		$options = array();
		foreach ( $posts as $id ) {
			$options[ $id ] = get_the_title( $id );
		}
		return $options;
	}

	/**
	 * A consultant's name, with a safe fallback if the record is gone.
	 */
	public static function consultant_name( $consultant_id ) {
		$title = get_the_title( $consultant_id );
		if ( '' === trim( (string) $title ) ) {
			/* translators: %d is a numeric ID */
			return sprintf( '(deleted consultant #%d)', (int) $consultant_id );
		}
		return $title;
	}

	/**
	 * Renders the page.
	 */
	public static function render_page() {
		// Re-check permission here, not only on the menu. Defence in depth.
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.' ) );
		}

		$filters       = self::get_filters();
		$totals        = self::get_totals_cached( $filters );
		$consultants   = self::get_consultant_options();

		require_once BPA_PATH . 'includes/class-bpa-list-table.php';

		$table = new BPA_List_Table( $filters );
		$table->prepare_items();

		?>
		<div class="wrap">
			<h1>Blueprint Analytics</h1>

			<?php self::render_health_panel(); ?>

			<form method="get">
				<input type="hidden" name="page" value="blueprint-analytics" />

				<p>
					<label for="bpa_from">Date from</label>
					<input type="date" id="bpa_from" name="bpa_from"
						value="<?php echo esc_attr( $filters['from'] ); ?>" />

					<label for="bpa_to">Date to</label>
					<input type="date" id="bpa_to" name="bpa_to"
						value="<?php echo esc_attr( $filters['to'] ); ?>" />

					<label for="bpa_consultant">Consultant</label>
					<select id="bpa_consultant" name="bpa_consultant">
						<option value="0">All consultants</option>
						<?php foreach ( $consultants as $id => $name ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>"
								<?php selected( $filters['consultant'], $id ); ?>>
								<?php echo esc_html( $name ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<?php submit_button( 'Filter', 'secondary', '', false ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=blueprint-analytics' ) ); ?>"
						class="button">Reset</a>
					<a href="<?php echo esc_url( add_query_arg( 'bpa_refresh', '1' ) ); ?>"
						class="button" title="Bypass the 5-minute cache">Refresh data</a>
				</p>
			</form>

			<h2 style="margin-top:1.5em">
				Totals for <?php echo esc_html( $filters['from'] ); ?>
				to <?php echo esc_html( $filters['to'] ); ?>
			</h2>
			<p style="font-size:1.1em">
				<strong>Profile views:</strong> <?php echo esc_html( number_format_i18n( $totals['views'] ) ); ?>
				&nbsp;&nbsp;
				<strong>Phone interactions:</strong> <?php echo esc_html( number_format_i18n( $totals['phone'] ) ); ?>
				&nbsp;&nbsp;
				<strong>Website clicks:</strong> <?php echo esc_html( number_format_i18n( $totals['website'] ) ); ?>
			</p>

			<?php $table->display(); ?>
		</div>
		<?php
	}
}