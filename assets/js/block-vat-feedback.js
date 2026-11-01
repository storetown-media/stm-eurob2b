/**
 * Checkout block: shows the result of the VAT ID check (TAX-015) at the address with the field.
 *
 * The server checks the number with every cart update and sends the result as cart extension data
 * (extensions['eurob2b-vat-id'] = { status, message }, translated plain text). This script only
 * mirrors it into one notice of the billing address, or of the shipping address while that one is
 * also the billing address (then the billing form is hidden), and removes it when there is nothing
 * to report. Like block-payment-hints.js it compares with the notice actually in the store, so the
 * result comes back after WooCommerce clears the notices.
 * No build step: it uses the wp.data global and the store of the Cart and Checkout blocks.
 */
( function ( wp, wc ) {
	'use strict';

	if ( ! wp || ! wp.data ) {
		return;
	}

	var CART_STORE = 'wc/store/cart';
	var CHECKOUT_STORE = 'wc/store/checkout';
	var NOTICES_STORE = 'core/notices';
	var BILLING = 'wc/checkout/billing-address';
	var SHIPPING = 'wc/checkout/shipping-address';
	var NOTICE_ID = 'eurob2b-vat-feedback';
	// Notice status per result: a number the order would refuse is an error, a confirmed one a success.
	var TYPES = { valid: 'success', error: 'error', invalid: 'warning', pending: 'warning', unavailable: 'warning' };
	var settings = wc && wc.wcSettings && wc.wcSettings.getSetting ? wc.wcSettings.getSetting( 'eurob2b-vat-feedback_data', {} ) : {};
	var namespace = settings.namespace || 'eurob2b-vat-id';

	function current() {
		var store = wp.data.select( CART_STORE );
		if ( ! store || typeof store.getCartData !== 'function' ) {
			return null;
		}
		var cart = store.getCartData() || {};
		var data = ( cart.extensions || {} )[ namespace ] || {};
		if ( ! data.message || ! TYPES[ data.status ] ) {
			return null;
		}
		return { status: TYPES[ data.status ], message: String( data.message ) };
	}

	function context() {
		var checkout = wp.data.select( CHECKOUT_STORE );
		return checkout && typeof checkout.getUseShippingAsBilling === 'function' && checkout.getUseShippingAsBilling() ? SHIPPING : BILLING;
	}

	function shown( where ) {
		var notices = wp.data.select( NOTICES_STORE );
		var list = notices && typeof notices.getNotices === 'function' ? notices.getNotices( where ) || [] : [];
		for ( var i = 0; i < list.length; i++ ) {
			if ( list[ i ].id === NOTICE_ID ) {
				return list[ i ].status + '|' + String( list[ i ].content );
			}
		}
		return '';
	}

	// Listeners run synchronously on every dispatch, including our own: without this guard a
	// dispatch from inside the listener would call the listener again before the store settles.
	var updating = false;

	wp.data.subscribe( function () {
		if ( updating ) {
			return;
		}
		var result = current();
		var where = context();
		var other = BILLING === where ? SHIPPING : BILLING;
		var wanted = result ? result.status + '|' + result.message : '';
		if ( wanted === shown( where ) && '' === shown( other ) ) {
			return;
		}
		updating = true;
		try {
			var notices = wp.data.dispatch( NOTICES_STORE );
			if ( '' !== shown( other ) ) {
				notices.removeNotice( NOTICE_ID, other );
			}
			if ( result ) {
				// A notice with the same id replaces the old one.
				notices.createNotice( result.status, result.message, {
					id: NOTICE_ID,
					context: where,
					isDismissible: false,
				} );
			} else if ( '' !== shown( where ) ) {
				notices.removeNotice( NOTICE_ID, where );
			}
		} finally {
			updating = false;
		}
	} );
} )( window.wp, window.wc );
