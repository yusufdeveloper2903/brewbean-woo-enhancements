/**
 * Brew & Bean – product quick view modal.
 *
 * Accessible dialog: focus moves into the dialog, Tab is trapped, Esc / overlay / close
 * button close it and focus returns to the trigger. Variations are matched in vanilla JS
 * from the JSON the server renders into the form.
 */
( function () {
	'use strict';

	const BB = window.BrewBean;
	const modal = document.querySelector( '[data-brewbean-modal]' );
	if ( ! BB || ! modal ) {
		return;
	}

	const { __, config, TEXT_DOMAIN: TD } = BB;
	const dialog = modal.querySelector( '.brewbean-modal__dialog' );
	const content = modal.querySelector( '[data-brewbean-modal-content]' );
	const cache = new Map();

	let trigger = null;
	let releaseFocus = null;
	let isOpen = false;
	let requestId = 0;

	/* ------------------------------------------------------------------
	 * Open / close
	 * ---------------------------------------------------------------- */

	function loading() {
		content.innerHTML =
			'<div class="brewbean-qv brewbean-qv--loading" aria-hidden="true"><div class="brewbean-qv__gallery"><div class="brewbean-shimmer brewbean-shimmer--square"></div></div><div class="brewbean-qv__summary"><div class="brewbean-shimmer"></div><div class="brewbean-shimmer brewbean-shimmer--short"></div><div class="brewbean-shimmer"></div></div></div>' +
			`<p class="screen-reader-text">${ __( 'Loading product…', TD ) }</p>`;
	}

	async function open( productId, button ) {
		trigger = button;
		const current = ++requestId;

		if ( ! isOpen ) {
			isOpen = true;
			modal.hidden = false;
			BB.lockScroll();
			requestAnimationFrame( () => modal.classList.add( 'is-open' ) );
			releaseFocus = BB.trapFocus( dialog, close );
		}
		loading();
		dialog.focus();

		try {
			let html = cache.get( productId );
			if ( ! html ) {
				const url = config.wcAjax.replace( '%%endpoint%%', 'brewbean_quick_view' ) + '&product_id=' + encodeURIComponent( productId );
				const response = await fetch( url, { credentials: 'same-origin' } );
				const json = await response.json();
				if ( ! json.success ) {
					throw new Error( json.data && json.data.message );
				}
				html = json.data.html;
				cache.set( productId, html );
			}
			if ( current !== requestId || ! isOpen ) {
				return;
			}
			// Markup comes from our own endpoint and is escaped server-side.
			content.innerHTML = html;
			init( content );
			const title = content.querySelector( '#brewbean-qv-title' );
			if ( title ) {
				title.setAttribute( 'tabindex', '-1' );
				title.focus();
			}
		} catch ( error ) {
			content.innerHTML = '';
			content.append(
				BB.el( 'p', { class: 'brewbean-modal__error', role: 'alert' }, ( error && error.message ) || __( 'Sorry, this product could not be loaded.', TD ) )
			);
		}
	}

	function close() {
		if ( ! isOpen ) {
			return;
		}
		isOpen = false;
		requestId++;
		modal.classList.remove( 'is-open' );
		if ( releaseFocus ) {
			releaseFocus();
		}
		BB.unlockScroll();
		window.setTimeout( () => {
			if ( ! isOpen ) {
				modal.hidden = true;
				content.innerHTML = '';
			}
		}, 250 );
		if ( trigger && trigger.isConnected ) {
			trigger.focus();
		}
	}

	/* ------------------------------------------------------------------
	 * Content behaviour
	 * ---------------------------------------------------------------- */

	function init( scope ) {
		initGallery( scope );
		BB.initPurchaseOptions( scope );
		const form = scope.querySelector( '[data-brewbean-ajax-form]' );
		if ( form ) {
			if ( form.dataset.brewbeanVariations ) {
				initVariations( form );
			}
			form.addEventListener( 'submit', ( event ) => submit( event, form ) );
		}
	}

	function initGallery( scope ) {
		const main = scope.querySelector( '[data-brewbean-main-image]' );
		const thumbs = scope.querySelectorAll( '[data-brewbean-thumb]' );
		thumbs.forEach( ( thumb ) =>
			thumb.addEventListener( 'click', () => {
				main.src = thumb.dataset.brewbeanThumb;
				thumbs.forEach( ( t ) => {
					t.classList.toggle( 'is-active', t === thumb );
					t.setAttribute( 'aria-pressed', t === thumb ? 'true' : 'false' );
				} );
			} )
		);
	}

	function initVariations( form ) {
		let variations = [];
		try {
			variations = JSON.parse( form.dataset.brewbeanVariations );
		} catch ( e ) {
			return;
		}
		const scope = form.closest( '[data-brewbean-qv]' );
		const priceEl = scope.querySelector( '[data-brewbean-qv-price]' );
		const defaultPrice = priceEl.innerHTML;
		const mainImage = scope.querySelector( '[data-brewbean-main-image]' );
		const defaultImage = mainImage && mainImage.src;
		const idInput = form.querySelector( '[data-brewbean-variation-id]' );
		const availability = form.querySelector( '[data-brewbean-availability]' );
		const submitButton = form.querySelector( '[data-brewbean-submit]' );
		const subPrice = form.querySelector( '[data-brewbean-sub-price]' );
		const qty = form.querySelector( 'input.qty' );
		const groups = Array.from( form.querySelectorAll( '[data-brewbean-attribute]' ) );

		const selected = () =>
			Object.fromEntries( groups.map( ( g ) => [ g.dataset.brewbeanAttribute, g.querySelector( 'input:checked' )?.value || '' ] ) );

		const matches = ( variation, chosen ) =>
			Object.entries( chosen ).every( ( [ key, value ] ) => {
				const expected = variation.attributes[ key ];
				return ! value || expected === '' || expected === value;
			} );

		function update() {
			const chosen = selected();
			const complete = Object.values( chosen ).every( Boolean );

			// Disable options that cannot lead to an in-stock variation with the other choices.
			groups.forEach( ( group ) => {
				const key = group.dataset.brewbeanAttribute;
				group.querySelectorAll( 'input' ).forEach( ( input ) => {
					const candidate = { ...chosen, [ key ]: input.value };
					const possible = variations.some( ( v ) => v.inStock && matches( v, candidate ) );
					input.closest( '.brewbean-swatch' ).classList.toggle( 'is-unavailable', ! possible );
				} );
			} );

			const variation = complete ? variations.find( ( v ) => matches( v, chosen ) ) : null;
			if ( variation && variation.inStock ) {
				idInput.value = String( variation.id );
				priceEl.innerHTML = variation.priceHtml;
				availability.textContent = '';
				submitButton.disabled = false;
				if ( subPrice && variation.subHtml ) {
					subPrice.innerHTML = variation.subHtml;
				}
				if ( mainImage && variation.image ) {
					mainImage.src = variation.image;
				}
				if ( qty && variation.maxQty ) {
					qty.max = String( variation.maxQty );
				}
			} else {
				idInput.value = '0';
				priceEl.innerHTML = defaultPrice;
				submitButton.disabled = true;
				availability.textContent = complete
					? __( 'Sorry, this combination is unavailable.', TD )
					: __( 'Choose your options to see the price.', TD );
				if ( mainImage && defaultImage ) {
					mainImage.src = defaultImage;
				}
			}
		}

		form.addEventListener( 'change', ( event ) => {
			if ( event.target.closest( '[data-brewbean-attribute]' ) ) {
				update();
			}
		} );
		update();
	}

	async function submit( event, form ) {
		event.preventDefault();
		const button = form.querySelector( '[data-brewbean-submit]' );
		const errorBox = form.querySelector( '[data-brewbean-form-error]' );
		errorBox.hidden = true;
		button.classList.add( 'is-loading' );
		button.disabled = true;

		try {
			await BB.addToCart( form );
			if ( config.sideCart && config.autoOpen ) {
				close();
			} else {
				errorBox.classList.add( 'is-success' );
				errorBox.textContent = __( 'Added to your cart.', TD );
				errorBox.hidden = false;
			}
		} catch ( error ) {
			errorBox.classList.remove( 'is-success' );
			errorBox.textContent = error.message;
			errorBox.hidden = false;
		} finally {
			button.classList.remove( 'is-loading' );
			button.disabled = false;
		}
	}

	/* ------------------------------------------------------------------
	 * Wiring
	 * ---------------------------------------------------------------- */

	document.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '[data-brewbean-quick-view]' );
		if ( button ) {
			event.preventDefault();
			open( button.dataset.brewbeanQuickView, button );
			return;
		}
		if ( isOpen && event.target.closest( '[data-brewbean-modal-close]' ) ) {
			close();
		}
	} );
} )();
