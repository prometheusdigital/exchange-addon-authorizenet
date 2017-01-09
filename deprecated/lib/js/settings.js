jQuery( document ).ready( function( $ ) {
	$( 'input#authorizenet-sandbox-mode' ).on( 'change', function() {
		$( '.sandbox-mode-options' ).toggleClass( 'hide-if-live-mode' );
	});
});