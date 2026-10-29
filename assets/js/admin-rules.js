/**
 * Rule form: shows only the fields that belong to the chosen rule type, scope and condition types.
 * Everything is validated server-side again; this script is a convenience only.
 */
( function ( $ ) {
	'use strict';

	function toggleByType() {
		var type = $( '#eurob2b-rule-type' ).val();
		$( '[data-eurob2b-type]' ).each( function () {
			var types = String( $( this ).data( 'eurob2bType' ) ).split( ',' );
			$( this ).toggle( types.indexOf( type ) !== -1 );
		} );
	}

	function toggleScope() {
		var kind = $( '#eurob2b-rule-scope' ).val();
		$( '.eurob2b-scope-ids' ).each( function () {
			var kinds = String( $( this ).data( 'eurob2bScope' ) ).split( ',' );
			$( this ).toggle( kinds.indexOf( kind ) !== -1 );
		} );
	}

	function valueField( type ) {
		switch ( type ) {
			case 'billing_country':
			case 'shipping_country':
				return 'countries';
			case 'payment_method':
				return 'payment';
			case 'shipping_method':
				return 'shipping';
			case 'category_quantity':
				return 'terms';
			default:
				return 'number';
		}
	}

	function toggleCondition( row ) {
		var type = $( row ).find( '.eurob2b-condition-type' ).val();
		var field = valueField( type );
		var isList = field === 'countries' || field === 'payment' || field === 'shipping';
		$( row ).find( '.eurob2b-cond-value' ).each( function () {
			var mine = $( this ).data( 'eurob2bFor' );
			$( this ).toggle( type !== '' && ( mine === field || ( field === 'terms' && mine === 'number' ) ) );
		} );
		var operator = $( row ).find( '.eurob2b-condition-operator' );
		operator.toggle( type !== '' );
		operator.find( 'option' ).each( function () {
			var kind = $( this ).data( 'eurob2bKind' );
			$( this ).prop( 'disabled', isList ? kind !== 'list' : kind !== 'number' );
		} );
		if ( operator.find( 'option:selected' ).prop( 'disabled' ) ) {
			operator.val( isList ? 'in' : 'gte' );
		}
	}

	$( function () {
		if ( ! $( '.eurob2b-rule-form' ).length ) {
			return;
		}
		toggleByType();
		toggleScope();
		$( '.eurob2b-condition' ).each( function () {
			toggleCondition( this );
		} );
		$( '#eurob2b-rule-type' ).on( 'change', toggleByType );
		$( '#eurob2b-rule-scope' ).on( 'change', toggleScope );
		$( '.eurob2b-condition-type' ).on( 'change', function () {
			toggleCondition( $( this ).closest( '.eurob2b-condition' ) );
		} );
	} );
}( window.jQuery ) );
