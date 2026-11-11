/**
 * STM EU-B2B: asks before a form or button deletes or moves data (WCAG 3.3.4). The question is the
 * value of data-eurob2b-confirm on the form or the button; without JavaScript the action runs as
 * before.
 */
( function () {
	'use strict';

	document.addEventListener( 'submit', function ( event ) {
		var form = event.target;
		var question = form && form.getAttribute ? form.getAttribute( 'data-eurob2b-confirm' ) : null;
		if ( question && ! window.confirm( question ) ) {
			event.preventDefault();
		}
	} );

	document.addEventListener( 'click', function ( event ) {
		var control = event.target && event.target.closest ? event.target.closest( 'button[data-eurob2b-confirm], input[data-eurob2b-confirm], a[data-eurob2b-confirm]' ) : null;
		if ( control && ! window.confirm( control.getAttribute( 'data-eurob2b-confirm' ) ) ) {
			event.preventDefault();
		}
	} );
}() );
