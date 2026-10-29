/**
 * Shows the custom fields that belong to the chosen registration type.
 *
 * Progressive enhancement only: the server validates per type regardless.
 */
( function () {
	'use strict';

	function update( form ) {
		var select = form.querySelector( '[name="eurob2b_registration_type"]' );
		var current = select ? select.value : form.getAttribute( 'data-eurob2b-locked-type' ) || '';
		var fields = form.querySelectorAll( '[data-eurob2b-types]' );
		Array.prototype.forEach.call( fields, function ( row ) {
			var types = ( row.getAttribute( 'data-eurob2b-types' ) || '' ).split( ',' ).filter( Boolean );
			var visible = types.length === 0 || types.indexOf( current ) !== -1;
			row.hidden = ! visible;
			Array.prototype.forEach.call( row.querySelectorAll( 'input, select, textarea' ), function ( input ) {
				input.disabled = ! visible;
				if ( input.getAttribute( 'data-eurob2b-required' ) === '1' ) {
					input.required = visible;
				}
			} );
		} );
		var descriptions = form.querySelectorAll( '[data-eurob2b-type-description]' );
		var describedBy = '';
		Array.prototype.forEach.call( descriptions, function ( el ) {
			el.hidden = el.getAttribute( 'data-eurob2b-type-description' ) !== current;
			if ( ! el.hidden && el.id ) {
				describedBy = el.id;
			}
		} );
		// The description of the chosen type is read out with the select.
		if ( select ) {
			if ( describedBy ) {
				select.setAttribute( 'aria-describedby', describedBy );
			} else {
				select.removeAttribute( 'aria-describedby' );
			}
		}
	}

	function init() {
		var forms = document.querySelectorAll( 'form.register, form.eurob2b-registration' );
		Array.prototype.forEach.call( forms, function ( form ) {
			if ( ! form.querySelector( '[data-eurob2b-types]' ) && ! form.querySelector( '[name="eurob2b_registration_type"]' ) ) {
				return;
			}
			update( form );
			var select = form.querySelector( '[name="eurob2b_registration_type"]' );
			if ( select ) {
				select.addEventListener( 'change', function () {
					update( form );
				} );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
