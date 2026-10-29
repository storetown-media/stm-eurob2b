/**
 * Editor part of the EuroB2B registration form block (no build step: plain wp.element).
 */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var __ = wp.i18n.__;

	var types = ( window.eurob2bRegistrationBlock && window.eurob2bRegistrationBlock.types ) || [];

	registerBlockType( 'eurob2b/registration-form', {
		edit: function ( props ) {
			var options = [ { label: __( 'Choose a registration type', 'eurob2b' ), value: '' } ].concat(
				types.map( function ( t ) {
					return { label: t.label, value: t.slug };
				} )
			);
			var current = types.filter( function ( t ) {
				return t.slug === props.attributes.type;
			} )[ 0 ];

			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Registration type', 'eurob2b' ) },
						el( SelectControl, {
							label: __( 'Form is locked to', 'eurob2b' ),
							value: props.attributes.type,
							options: options,
							onChange: function ( value ) {
								props.setAttributes( { type: value } );
							}
						} )
					)
				),
				el(
					'div',
					{ className: 'eurob2b-registration-form-placeholder', style: { border: '1px solid #ccd0d4', padding: '16px' } },
					el( 'strong', null, __( 'EuroB2B registration form', 'eurob2b' ) ),
					el( 'p', null, current ? current.label : __( 'Choose a registration type in the block settings.', 'eurob2b' ) )
				)
			);
		},
		save: function () {
			return null;
		}
	} );
} )( window.wp );
