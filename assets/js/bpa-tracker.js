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
		// The class may be on the link itself (multi-location repeater HTML)
		// or on a wrapping element (Elementor's Advanced → CSS Classes puts
		// it on the widget container). Handle both.
		var scope = e.target.closest( '.bpa-website-link' );

		if ( ! scope ) {
			return;
		}

		var link = scope.matches( 'a' ) ? scope : e.target.closest( 'a' );

		if ( ! link ) {
			return;
		}

		if ( ! bpaIsTrackableUrl( link.getAttribute( 'href' ) ) ) {
			return;
		}

		bpaSend( 'website_click' );
	} );
	/**
	 * Converts each phone link into a Show Phone control.
	 *
	 * Runs on both single-location (Icon List) and multi-location
	 * (Dynamic Repeater) layouts, because both output tel: links.
	 *
	 * Fails open: if this does not run, the number stays visible.
	 */
	/**
	 * Phone reveal tracking.
	 *
	 * The Show Phone control is provided by the EXISTING site
	 * implementation (inline snippet), not by this plugin. We only
	 * observe it.
	 *
	 * Detection: a click where a tel: link in the same container is
	 * currently hidden = a reveal. If the number is already visible,
	 * the click is on the number itself and is NOT counted.
	 *
	 * Registered in the CAPTURE phase so we read the DOM before the
	 * site's own handler reveals the number.
	 */
	function bpaIsHidden( el ) {
		if ( ! el ) {
			return false;
		}
		if ( el.hidden ) {
			return true;
		}
		var style = window.getComputedStyle( el );
		return 'none' === style.display || 'hidden' === style.visibility;
	}

	document.addEventListener( 'click', function ( e ) {
		// Find the clickable thing that was clicked.
		var trigger = e.target.closest( 'button, a, [role="button"]' );

		if ( ! trigger ) {
			return;
		}

		// Never count a click on the phone number itself.
		if ( trigger.matches( 'a[href^="tel:"]' ) ) {
			return;
		}

		// Only fire once per trigger.
		if ( trigger.getAttribute( 'data-bpa-fired' ) ) {
			return;
		}

		/*
		 * Look for a hidden phone link nearby. We walk up a few levels
		 * so this works for both the single-location Icon List and each
		 * separate location block in the multi-location repeater.
		 */
		var scope = trigger.parentElement;
		var hiddenPhone = null;
		var levels = 0;

		while ( scope && levels < 4 && ! hiddenPhone ) {
			var candidates = scope.querySelectorAll( 'a[href^="tel:"]' );

			for ( var i = 0; i < candidates.length; i++ ) {
				if ( bpaIsHidden( candidates[ i ] ) ) {
					hiddenPhone = candidates[ i ];
					break;
				}
			}

			scope = scope.parentElement;
			levels++;
		}

		if ( ! hiddenPhone ) {
			return; // not a phone reveal
		}

		// Ignore links with no actual digits, e.g. "contact via website".
		var raw = hiddenPhone.getAttribute( 'href' ).replace( 'tel:', '' );
		if ( ! /\d/.test( decodeURIComponent( raw ) ) ) {
			return;
		}

		trigger.setAttribute( 'data-bpa-fired', '1' );
		bpaSend( 'phone_click' );
	}, true ); // ← true = capture phase

	// Record the profile view.
	bpaSend( 'profile_view' );
}() );