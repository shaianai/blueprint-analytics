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

	// Expose it so later steps (phone, website) can reuse it.
	window.bpaTrack = bpaSend;

	// Record the profile view.
	bpaSend( 'profile_view' );
}() );