/**
 * © 2026 Kenneth Raymond. All rights reserved.
 * Part of the Fernwell WordPress theme.
 * Do not copy, modify, or redistribute without permission.
 *
 * File: Answers navigation memory
 * Purpose: When a reader opens a post from the Answers listing, remember
 * which listing page and which item they left from (sessionStorage). On the
 * post, the "All answers" button returns them to that exact spot. Without
 * stored context the button keeps its default /answers/ href.
 *
 * Location:
 *   /wp-content/themes/fernwell/assets/js/answers-nav.js
 */
( function () {
	'use strict';

	var KEY = 'fwAnswersReturn';

	// Listing: remember where the reader left from.
	document.querySelectorAll( '.answer-item h2 a' ).forEach( function ( link ) {
		link.addEventListener( 'click', function () {
			var item = link.closest( '.answer-item' );
			try {
				sessionStorage.setItem(
					KEY,
					location.pathname + location.search + ( item && item.id ? '#' + item.id : '' )
				);
			} catch ( e ) {}
		} );
	} );

	// Single post: upgrade the back button to the stored spot.
	var back = document.querySelector( '.post-nav__back' );
	if ( back ) {
		try {
			var stored = sessionStorage.getItem( KEY );
			var base   = back.getAttribute( 'data-answers-base' ) || '/answers/';
			if ( stored && stored.indexOf( base ) === 0 ) {
				back.setAttribute( 'href', stored );
			}
		} catch ( e ) {}
	}
} )();
