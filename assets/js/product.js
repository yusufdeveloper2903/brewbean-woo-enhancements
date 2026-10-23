/**
 * Brew & Bean – single product page.
 *
 * - Subscribe & save option toggling (and per-variation subscription price).
 * - AJAX add to cart for the product form when the side cart is enabled,
 *   so the customer stays on the page and the side cart slides in.
 */
( function () {
	'use strict';

	const BB = window.BrewBean;
	if ( ! BB ) {
		return;
	}
	const { config } = BB;

	BB.initPurchaseOptions( document );

	// WooCommerce's variation form is jQuery-driven; listening to its events is the only hook.
	if ( window.jQuery ) {
		window.jQuery( 'form.variations_form' )
			.on( 'found_variation', function ( event, variation ) {
				const target = this.querySelector( '[data-brewbean-sub-price]' );
				if ( target && variation.brewbean_subscribe_price_html ) {
					target.innerHTML = variation.brewbean_subscribe_price_html;
				}
			} )
			.on( 'reset_data', function () {
				const target = this.querySelector( '[data-brewbean-sub-price]' );
				if ( target ) {
					target.textContent = target.dataset.defaultText || target.textContent;
				}
			} );
		document.querySelectorAll( '[data-brewbean-sub-price]' ).forEach( ( node ) => ( node.dataset.defaultText = node.textContent ) );
	}

	if ( ! config.sideCart ) {
		return;
	}

	document.addEventListener( 'submit', async ( event ) => {
		const form = event.target;
		if ( ! ( form instanceof HTMLFormElement ) || ! form.matches( 'form.cart' ) || form.matches( '[data-brewbean-ajax-form]' ) ) {
			return;
		}
		const submitter = event.submitter;
		const hasProduct = form.querySelector( '[name="add-to-cart"]' ) || ( submitter && submitter.name === 'add-to-cart' );
		// External/affiliate products submit to another site: leave them alone.
		if ( ! hasProduct || ( form.method || '' ).toLowerCase() === 'get' ) {
			return;
		}
		event.preventDefault();

		const button = submitter || form.querySelector( '[type="submit"]' );
		if ( button ) {
			button.classList.add( 'loading', 'is-loading' );
			button.disabled = true;
		}
		let errorBox = form.querySelector( '[data-brewbean-form-error]' );
		if ( ! errorBox ) {
			errorBox = BB.el( 'div', { class: 'brewbean-form-error', 'data-brewbean-form-error': true, role: 'alert', hidden: true } );
			form.append( errorBox );
		}
		errorBox.hidden = true;

		try {
			await BB.addToCart( form, submitter );
			if ( button ) {
				button.classList.add( 'added' );
			}
		} catch ( error ) {
			errorBox.textContent = error.message;
			errorBox.hidden = false;
		} finally {
			if ( button ) {
				button.classList.remove( 'loading', 'is-loading' );
				button.disabled = false;
			}
		}
	} );
} )();
