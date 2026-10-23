/**
 * Brew & Bean – checkout: friendly client-side hints for the delivery date field.
 * The server re-validates everything (see DeliveryDate::validate()).
 */
( function () {
	'use strict';

	const { __ } = ( window.wp && window.wp.i18n ) || { __: ( s ) => s };
	const TD = 'brewbean-woo-enhancements';

	function check( input ) {
		const row = input.closest( '.form-row' );
		let message = '';
		if ( input.value ) {
			const date = new Date( `${ input.value }T12:00:00` );
			if ( input.min && input.value < input.min ) {
				message = __( 'That date is too soon – we roast every order fresh.', TD );
			} else if ( input.max && input.value > input.max ) {
				message = __( 'Please choose a date within our booking window.', TD );
			} else if ( input.dataset.brewbeanNoSundays === '1' && date.getDay() === 0 ) {
				message = __( 'We do not deliver on Sundays. Please choose another day.', TD );
			}
		}
		input.setCustomValidity( message );
		input.setAttribute( 'aria-invalid', message ? 'true' : 'false' );

		let hint = row && row.querySelector( '.brewbean-date-error' );
		if ( row && ! hint ) {
			hint = document.createElement( 'p' );
			hint.className = 'brewbean-date-error';
			hint.id = `${ input.id }-error`;
			hint.setAttribute( 'role', 'alert' );
			row.append( hint );
			input.setAttribute( 'aria-describedby', `${ input.getAttribute( 'aria-describedby' ) || '' } ${ hint.id }`.trim() );
		}
		if ( hint ) {
			hint.textContent = message;
			hint.hidden = ! message;
		}
		if ( row ) {
			row.classList.toggle( 'woocommerce-invalid', !! message );
		}
	}

	document.addEventListener( 'change', ( event ) => {
		if ( event.target.matches && event.target.matches( '#brewbean_delivery_date' ) ) {
			check( event.target );
		}
	} );
} )();
