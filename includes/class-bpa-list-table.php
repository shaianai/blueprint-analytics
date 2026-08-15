<?php
/**
 * The consultant results table, built on WordPress's own table class.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WP_List_Table isn't loaded on every admin page, so load it ourselves.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BPA_List_Table extends WP_List_Table {

	private $filters;

	public function __construct( $filters ) {
		$this->filters = $filters;

		parent::__construct(
			array(
				'singular' => 'consultant',
				'plural'   => 'consultants',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The column headings.
	 */
	public function get_columns() {
		return array(
			'consultant' => 'Consultant',
			'views'      => 'Profile Views',
			'phone'      => 'Phone Interactions',
			'website'    => 'Website Clicks',
		);
	}

	/**
	 * Fetches the data and sets up pagination.
	 */
	public function prepare_items() {
		$per_page     = 25;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$this->items = BPA_Admin::get_rows( $this->filters, $per_page, $offset );

		$total_items = BPA_Admin::count_consultants( $this->filters );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array() );
	}

	/**
	 * Renders one cell.
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'consultant':
				$name = BPA_Admin::consultant_name( $item['consultant_id'] );
				$link = get_edit_post_link( $item['consultant_id'] );

				if ( $link ) {
					return '<a href="' . esc_url( $link ) . '">' . esc_html( $name ) . '</a>';
				}
				return esc_html( $name );

			case 'views':
			case 'phone':
			case 'website':
				return esc_html( number_format_i18n( (int) $item[ $column_name ] ) );
		}

		return '';
	}

	/**
	 * Shown when there is no data.
	 */
	public function no_items() {
		echo 'No activity recorded for the selected filters.';
	}
}