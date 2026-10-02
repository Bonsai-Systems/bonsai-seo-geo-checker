/**
 * Bonsai SEO/GEO Checker – settings screen: report logo picker.
 *
 * Opens the Media Library, stores the chosen attachment ID in the hidden
 * field and shows a preview. The ID is re-validated on save in PHP.
 */
/* global jQuery, wp */
( function ( $ ) {
	'use strict';

	$( function () {
		$( '.bsgc-media' ).each( function () {
			var $wrap = $( this );
			var $input = $wrap.find( 'input[type="hidden"]' );
			var $preview = $wrap.find( '.bsgc-media__preview' );
			var $choose = $wrap.find( '.bsgc-media__choose' );
			var $remove = $wrap.find( '.bsgc-media__remove' );
			var frame;

			function show( id, url ) {
				$input.val( id || '' );
				$preview.attr( 'src', url || '' ).prop( 'hidden', ! url );
				$remove.prop( 'hidden', ! url );
				$choose.text( url ? 'Change logo' : 'Choose logo' );
			}

			$choose.on( 'click.bonsai_bsgc', function () {
				if ( ! frame ) {
					frame = wp.media( {
						title: 'Choose report logo',
						button: { text: 'Use this logo' },
						library: { type: 'image' },
						multiple: false
					} );

					frame.on( 'select', function () {
						var image = frame.state().get( 'selection' ).first().toJSON();
						var size = image.sizes && image.sizes.medium ? image.sizes.medium : image;
						show( image.id, size.url );
					} );
				}

				frame.open();
			} );

			$remove.on( 'click.bonsai_bsgc', function () {
				show( '', '' );
				$choose.trigger( 'focus' );
			} );
		} );
	} );
}( jQuery ) );
