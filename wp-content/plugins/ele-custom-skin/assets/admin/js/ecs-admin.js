( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {

		// ── Preview popups (click-to-toggle) ───────────────────────────
		document.querySelectorAll( '.ecs-preview-btn' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var wrap = btn.closest( '.ecs-preview-wrap' );
				var isOpen = wrap.classList.contains( 'is-open' );

				// Close all open popups first.
				document.querySelectorAll( '.ecs-preview-wrap.is-open' ).forEach( function ( w ) {
					w.classList.remove( 'is-open' );
					w.querySelector( '.ecs-preview-btn' ).classList.remove( 'is-active' );
				} );

				if ( ! isOpen ) {
					wrap.classList.add( 'is-open' );
					btn.classList.add( 'is-active' );
				}
			} );
		} );

		// Close on click outside.
		document.addEventListener( 'click', function () {
			document.querySelectorAll( '.ecs-preview-wrap.is-open' ).forEach( function ( w ) {
				w.classList.remove( 'is-open' );
				w.querySelector( '.ecs-preview-btn' ).classList.remove( 'is-active' );
			} );
		} );

	} );
} )();
