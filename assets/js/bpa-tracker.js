/**
 * Blueprint Analytics tracker.
 * Runs in the visitor's browser. Records a profile view once per page load.
 */
( function () {
	'use strict';

	// If our data block is missing, do nothing rather than throwing errors.
	if ( typeof bpaData === 'undefined' || ! bpaData.consultantId ) {
		return;
	}

	/**
	 * Sends one event to WordPress.
	 */
	function bpaSend( eventType ) {
		var payload = {
			consultant_id: bpaData.consultantId,
			event_type: eventType,
			source_page: window.location.pathname
		};

		fetch( bpaData.endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( payload ),
			keepalive: true,
			credentials: 'same-origin'
		} ).catch( function () {
			// Silent failure. Analytics must never break the page.
		} );
	}

	// Expose it so other parts can reuse it.
	window.bpaTrack = bpaSend;

	/**
	 * Is this a real external web address worth counting?
	 * Rejects empty, malformed, and internal links.
	 * Required by 10.11: "do not count invalid/missing URLs".
	 */
	function bpaIsTrackableUrl( href ) {
		if ( ! href ) {
			return false;
		}

		var trimmed = href.trim();

		if ( '' === trimmed || '#' === trimmed ) {
			return false;
		}

		try {
			var url = new URL( trimmed, window.location.href );

			// Only real web addresses.
			if ( 'http:' !== url.protocol && 'https:' !== url.protocol ) {
				return false;
			}

			// A missing domain, e.g. "https:///" from an empty field.
			if ( ! url.hostname ) {
				return false;
			}

			// Links back to our own site are not website clicks.
			if ( url.hostname === window.location.hostname ) {
				return false;
			}

			return true;
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * Website URL clicks.
	 */
	document.addEventListener( 'click', function ( e ) {
		var link = e.target.closest( 'a.bpa-website-link' );

		if ( ! link ) {
			return;
		}

		if ( ! bpaIsTrackableUrl( link.getAttribute( 'href' ) ) ) {
			return; // invalid or missing URL: record nothing
		}

		bpaSend( 'website_click' );

		// We do NOT call preventDefault(). The link behaves exactly
		// as it always has, including opening in a new tab,
		// middle-click, and right-click open-in-new-tab.
	} );
	/**
	 * Converts each phone link into a Show Phone control.
	 *
	 * Runs on both single-location (Icon List) and multi-location
	 * (Dynamic Repeater) layouts, because both output tel: links.
	 *
	 * Fails open: if this does not run, the number stays visible.
	 */
	function bpaSetupPhoneControls() {
		var links = document.querySelectorAll( 'a[href^="tel:"]' );

		Array.prototype.forEach.call( links, function ( link, index ) {
			// Skip anything already converted.
			if ( link.getAttribute( 'data-bpa-phone-ready' ) ) {
				return;
			}

			// Ignore links with no actual number, e.g. "contact via website".
			var raw = link.getAttribute( 'href' ).replace( 'tel:', '' );
			if ( ! /\d/.test( decodeURIComponent( raw ) ) ) {
				return;
			}

			link.setAttribute( 'data-bpa-phone-ready', '1' );

			var button = document.createElement( 'button' );
			button.type = 'button';
			button.className = 'bpa-phone-button';
			button.textContent = 'Show Phone';
			button.setAttribute( 'aria-expanded', 'false' );
			button.setAttribute( 'aria-controls', 'bpa-phone-' + index );

			link.id = 'bpa-phone-' + index;
			link.hidden = true;

			// Place the button where the link is.
			link.parentNode.insertBefore( button, link );

			button.addEventListener( 'click', function () {
				// Guard against double-clicks firing twice.
				if ( button.getAttribute( 'data-bpa-fired' ) ) {
					return;
				}
				button.setAttribute( 'data-bpa-fired', '1' );

				bpaSend( 'phone_click' );

				link.hidden = false;
				button.setAttribute( 'aria-expanded', 'true' );
				button.hidden = true;

				link.setAttribute( 'tabindex', '-1' );
				link.focus();
			} );
		} );
	}

	bpaSetupPhoneControls();
	// Record the profile view.
	bpaSend( 'profile_view' );
}() );