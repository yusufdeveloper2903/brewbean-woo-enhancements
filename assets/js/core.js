/**
 * Brew & Bean – shared front-end helpers.
 *
 * Exposes `window.BrewBean` with:
 *  - Store API client (cart, update-item, remove-item) with nonce handling
 *  - addToCart( form ) for full product forms (posts to ?wc-ajax=brewbean_add_to_cart)
 *  - price formatting from Store API currency data
 *  - a tiny event bus on `document` (brewbean:cart-updated, brewbean:added, brewbean:open-cart)
 *  - focus-trap + purchase-option helpers shared by the side cart, quick view and product page
 *
 * No jQuery. Plain ES2020, no build step required.
 */
( function () {
	'use strict';

	const config = window.brewbeanConfig || {};
	const { __ } = ( window.wp && window.wp.i18n ) || { __: ( s ) => s };
	const TEXT_DOMAIN = 'brewbean-woo-enhancements';

	let storeNonce = config.storeNonce || '';
	let lastCart = null;

	/* ------------------------------------------------------------------
	 * Events
	 * ---------------------------------------------------------------- */

	const emit = ( name, detail = {} ) =>
		document.dispatchEvent( new CustomEvent( `brewbean:${ name }`, { detail } ) );

	const on = ( name, handler ) =>
		document.addEventListener( `brewbean:${ name }`, ( event ) => handler( event.detail || {}, event ) );

	/* ------------------------------------------------------------------
	 * Store API
	 * ---------------------------------------------------------------- */

	const genericError = () => __( 'Something went wrong. Please try again.', TEXT_DOMAIN );

	async function storeApi( path, { method = 'GET', body } = {}, retried = false ) {
		const headers = { Accept: 'application/json' };
		if ( method !== 'GET' ) {
			headers[ 'Content-Type' ] = 'application/json';
			headers.Nonce = storeNonce;
		}
		// Only send the REST cookie nonce for logged-in users; for guests a stale one would cause a 403.
		if ( config.restNonce && document.body.classList.contains( 'logged-in' ) ) {
			headers[ 'X-WP-Nonce' ] = config.restNonce;
		}

		const response = await fetch( config.storeApi + path, {
			method,
			credentials: 'same-origin',
			headers,
			body: body ? JSON.stringify( body ) : undefined,
		} );

		const nonce = response.headers.get( 'Nonce' );
		if ( nonce ) {
			storeNonce = nonce;
		}

		const data = await response.json().catch( () => null );

		if ( ! response.ok ) {
			// The guest session may have started since the page was rendered: refresh the nonce once.
			if ( ! retried && data && data.code === 'woocommerce_rest_invalid_nonce' ) {
				await storeApi( 'cart' );
				return storeApi( path, { method, body }, true );
			}
			const error = new Error( ( data && decodeEntities( data.message ) ) || genericError() );
			error.data = data;
			throw error;
		}
		return data;
	}

	function setCart( cart ) {
		lastCart = cart;
		emit( 'cart-updated', { cart } );
		return cart;
	}

	const cart = {
		get: () => lastCart,
		refresh: () => storeApi( 'cart' ).then( setCart ),
		updateItem: ( key, quantity ) =>
			storeApi( 'cart/update-item', { method: 'POST', body: { key, quantity } } ).then( setCart ),
		removeItem: ( key ) =>
			storeApi( 'cart/remove-item', { method: 'POST', body: { key } } ).then( setCart ),
	};

	/**
	 * Add a product from a full product form (simple, variable, subscribe options…).
	 *
	 * @param {HTMLFormElement} form      Product form.
	 * @param {HTMLElement}     submitter Clicked submit button (optional).
	 */
	async function addToCart( form, submitter ) {
		const body = submitter ? new FormData( form, submitter ) : new FormData( form );
		if ( ! body.get( 'add-to-cart' ) && submitter && submitter.value ) {
			body.set( 'add-to-cart', submitter.value );
		}

		const response = await fetch( config.wcAjax.replace( '%%endpoint%%', 'brewbean_add_to_cart' ), {
			method: 'POST',
			credentials: 'same-origin',
			body,
		} );
		const json = await response.json().catch( () => null );

		if ( ! json || ! json.success ) {
			const messages = ( json && json.data && json.data.messages ) || [ genericError() ];
			const error = new Error( messages.join( ' ' ) );
			error.messages = messages;
			throw error;
		}

		if ( json.data.storeNonce ) {
			storeNonce = json.data.storeNonce;
		}
		const updated = await cart.refresh();
		emit( 'added', { cart: updated, productId: json.data.productId } );
		return updated;
	}

	/* ------------------------------------------------------------------
	 * Formatting
	 * ---------------------------------------------------------------- */

	function formatPrice( minorAmount, currency ) {
		const c = currency || {};
		const unit = Number.isInteger( c.currency_minor_unit ) ? c.currency_minor_unit : 2;
		const value = parseInt( minorAmount || 0, 10 ) / Math.pow( 10, unit );
		const [ integer, decimals ] = Math.abs( value ).toFixed( unit ).split( '.' );
		const grouped = integer.replace( /\B(?=(\d{3})+(?!\d))/g, c.currency_thousand_separator ?? ',' );
		const amount = unit > 0 ? `${ grouped }${ c.currency_decimal_separator ?? '.' }${ decimals }` : grouped;
		return `${ value < 0 ? '-' : '' }${ c.currency_prefix ?? '' }${ amount }${ c.currency_suffix ?? '' }`;
	}

	const decoder = document.createElement( 'textarea' );
	function decodeEntities( html ) {
		if ( typeof html !== 'string' ) {
			return '';
		}
		// Strip tags first, then decode entities. The result is only ever used as textContent.
		decoder.innerHTML = html.replace( /<[^>]*>/g, '' );
		return decoder.value;
	}

	/**
	 * Tiny element factory: el( 'a', { class: 'x', href }, [ children ] ). Strings become text nodes.
	 */
	function el( tag, attrs = {}, children = [] ) {
		const node = document.createElement( tag );
		Object.entries( attrs ).forEach( ( [ key, value ] ) => {
			if ( value === false || value === null || value === undefined ) {
				return;
			}
			if ( key === 'class' ) {
				node.className = value;
			} else if ( key === 'text' ) {
				node.textContent = value;
			} else {
				node.setAttribute( key, value === true ? '' : value );
			}
		} );
		( Array.isArray( children ) ? children : [ children ] ).forEach( ( child ) => {
			if ( child === null || child === undefined || child === false ) {
				return;
			}
			node.append( child instanceof Node ? child : document.createTextNode( String( child ) ) );
		} );
		return node;
	}

	/* ------------------------------------------------------------------
	 * Accessibility helpers
	 * ---------------------------------------------------------------- */

	const FOCUSABLE =
		'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	function focusables( container ) {
		return Array.from( container.querySelectorAll( FOCUSABLE ) ).filter(
			( node ) => node.offsetWidth > 0 || node.offsetHeight > 0 || node === document.activeElement
		);
	}

	/**
	 * Keep Tab / Shift+Tab inside `container` while it is open. Returns a release function.
	 */
	function trapFocus( container, onEscape ) {
		const handler = ( event ) => {
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				onEscape();
				return;
			}
			if ( event.key !== 'Tab' ) {
				return;
			}
			const items = focusables( container );
			if ( ! items.length ) {
				event.preventDefault();
				container.focus();
				return;
			}
			const first = items[ 0 ];
			const last = items[ items.length - 1 ];
			if ( event.shiftKey && ( document.activeElement === first || document.activeElement === container ) ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		};
		document.addEventListener( 'keydown', handler );
		return () => document.removeEventListener( 'keydown', handler );
	}

	let lockCount = 0;
	const lockScroll = () => {
		lockCount++;
		document.documentElement.classList.add( 'brewbean-scroll-locked' );
	};
	const unlockScroll = () => {
		lockCount = Math.max( 0, lockCount - 1 );
		if ( ! lockCount ) {
			document.documentElement.classList.remove( 'brewbean-scroll-locked' );
		}
	};

	/* ------------------------------------------------------------------
	 * Subscribe & save purchase options (product page + quick view)
	 * ---------------------------------------------------------------- */

	function initPurchaseOptions( root ) {
		root.querySelectorAll( '[data-brewbean-purchase-options]' ).forEach( ( fieldset ) => {
			if ( fieldset.dataset.ready ) {
				return;
			}
			fieldset.dataset.ready = '1';
			const interval = fieldset.querySelector( '[data-brewbean-interval]' );
			const sync = () => {
				const subscribed = fieldset.querySelector( 'input[name="brewbean_purchase"]:checked' )?.value === 'subscribe';
				fieldset.classList.toggle( 'is-subscribed', subscribed );
				if ( interval ) {
					interval.hidden = ! subscribed;
				}
			};
			fieldset.addEventListener( 'change', sync );
			sync();
		} );
	}

	/* ------------------------------------------------------------------
	 * Progress bar markup (mirrors templates/progress-bar.php)
	 * ---------------------------------------------------------------- */

	function progressBar( shipping, context ) {
		const wrapper = el( 'div', {
			class: `brewbean-progress brewbean-progress--${ context }${ shipping.qualified ? ' is-qualified' : '' }`,
		} );
		const message = el( 'p', { class: 'brewbean-progress__message' } );
		// Message is generated server-side (escaped with esc_html + wc_price); render it as trusted markup.
		message.innerHTML = shipping.message;
		const fill = el( 'span', { class: 'brewbean-progress__fill' } );
		fill.style.width = `${ shipping.percent }%`;
		const track = el(
			'div',
			{
				class: 'brewbean-progress__track',
				role: 'progressbar',
				'aria-label': __( 'Progress towards free shipping', TEXT_DOMAIN ),
				'aria-valuemin': '0',
				'aria-valuemax': '100',
				'aria-valuenow': String( shipping.percent ),
			},
			fill
		);
		wrapper.append( message, track );
		return wrapper;
	}

	window.BrewBean = {
		config,
		__,
		TEXT_DOMAIN,
		on,
		emit,
		cart,
		storeApi,
		addToCart,
		formatPrice,
		decodeEntities,
		el,
		focusables,
		trapFocus,
		lockScroll,
		unlockScroll,
		initPurchaseOptions,
		progressBar,
	};
} )();
