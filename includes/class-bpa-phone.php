<?php
/**
 * The Show Phone control: reveals a consultant's phone number on click,
 * after recording the interaction.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BPA_Phone {

	/**
	 * The JetEngine field holding the phone number.
	 * ⚠️ Confirm this against JetEngine → Post Types → Business → Meta Fields.
	 */
	const PHONE_FIELD = 'phone_number';

	/**
	 * Registers the shortcode we place in the Elementor template.
	 */
	public static function register() {
		add_shortcode( 'bpa_phone', array( __CLASS__, 'render' ) );
	}

	/**
	 * Turns a stored phone value into a dialable international number.
	 * Returns an empty string if there is no usable number.
	 */
	public static function to_dialable( $raw ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return '';
		}

		// Keep only digits and a leading plus.
		$digits = preg_replace( '/[^0-9+]/', '', $raw );
		$digits = preg_replace( '/(?!^)\+/', '', $digits ); // only one plus, at the start

		if ( '' === $digits ) {
			return ''; // e.g. "contact via website"
		}

		// Already international.
		if ( 0 === strpos( $digits, '+61' ) ) {
			$national = substr( $digits, 3 );
			return self::is_plausible_au( $national ) ? '+61' . $national : '';
		}

		// Starts with the country code but no plus.
		if ( 0 === strpos( $digits, '61' ) && strlen( $digits ) >= 11 ) {
			$national = substr( $digits, 2 );
			return self::is_plausible_au( $national ) ? '+61' . $national : '';
		}

		// Standard Australian format: leading 0.
		if ( 0 === strpos( $digits, '0' ) ) {
			$national = substr( $digits, 1 );
			return self::is_plausible_au( $national ) ? '+61' . $national : '';
		}

		// Anything else we do not confidently recognise.
		return '';
	}

	/**
	 * A rough sanity check: Australian numbers without the leading 0
	 * are 9 digits (landline and mobile).
	 */
	private static function is_plausible_au( $national ) {
		return 9 === strlen( $national );
	}

	/**
	 * Encodes the number so it does not look like a phone number
	 * to automated scrapers. Not encryption, just not plain text.
	 */
	private static function encode( $dialable ) {
		return strrev( base64_encode( $dialable ) );
	}

	/**
	 * Renders the control.
	 */
	public static function render( $atts = array() ) {
		$consultant_id = get_the_ID();

		if ( ! $consultant_id ) {
			return '';
		}

		$raw      = get_post_meta( $consultant_id, self::PHONE_FIELD, true );
		$dialable = self::to_dialable( $raw );

		// No usable number: say so plainly, no broken button.
		if ( '' === $dialable ) {
			return '<span class="bpa-phone-none">Contact via website</span>';
		}

		$display  = trim( (string) $raw );
		$encoded  = self::encode( $dialable );
		$reveal_id = 'bpa-phone-' . (int) $consultant_id;

		ob_start();
		?>
		<div class="bpa-phone-wrap">
			<button type="button"
				class="bpa-phone-button elementor-button"
				data-bpa-consultant="<?php echo esc_attr( $consultant_id ); ?>"
				data-bpa-phone="<?php echo esc_attr( $encoded ); ?>"
				data-bpa-display="<?php echo esc_attr( $display ); ?>"
				data-bpa-target="<?php echo esc_attr( $reveal_id ); ?>"
				aria-expanded="false">
				Show Phone
			</button>

			<span id="<?php echo esc_attr( $reveal_id ); ?>"
				class="bpa-phone-revealed"
				role="status"
				aria-live="polite"
				hidden></span>
		</div>
		<?php
		return ob_get_clean();
	}
}