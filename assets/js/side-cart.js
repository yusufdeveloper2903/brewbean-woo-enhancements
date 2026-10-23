/**
 * Brew & Bean – slide-out side cart.
 *
 * Renders the WooCommerce Store API cart into the drawer from templates/side-cart.php,
 * updates quantities / removes items without reloading, and opens automatically when
 * a product is added (our AJAX forms, WooCommerce blocks and classic AJAX buttons).
 */
( function () {
	'use strict';

	const BB = window.BrewBean;
	const root = document.querySelector( '[data-brewbean-side-cart]' );
	if ( ! BB || ! root ) {
		return;
	}

	const { __, el, config, TEXT_DOMAIN: TD } = BB;
	const panel = root.querySelector( '.brewbean-side-cart__panel' );
	const body = root.querySelector( '[data-brewbean-drawer-body]' );
	const footer = root.querySelector( '[data-brewbean-drawer-footer]' );
	const subtotalEl = root.querySelector( '[data-brewbean-drawer-subtotal]' );
	const progressEl = root.querySelector( '[data-brewbean-drawer-progress]' );
	const noticeEl = root.querySelector( '[data-brewbean-drawer-notice]' );
	const drawerCount = root.querySelector( '[data-brewbean-drawer-count]' );

	let isOpen = false;
	let releaseFocus = null;
	let lastFocus = null;
	let loaded = false;
	const pending = new Map();

	/* ------------------------------------------------------------------
	 * Open / close
	 * ---------------------------------------------------------------- */

	function setExpanded( value ) {
		document.querySelectorAll( '[data-brewbean-open-cart]' ).forEach( ( button ) =>
			button.setAttribute( 'aria-expanded', value ? 'true' : 'false' )
		);
	}

	function open() {
		if ( isOpen ) {
			return;
		}
		isOpen = true;
		lastFocus = document.activeElement;
		root.hidden = false;
		BB.lockScroll();
		setExpanded( true );
		// Next frame so the CSS transition runs.
		requestAnimationFrame( () => root.classList.add( 'is-open' ) );
		panel.focus();
		releaseFocus = BB.trapFocus( panel, close );
		if ( ! loaded ) {
			BB.cart.refresh().catch( showError );
		}
	}

	function close() {
		if ( ! isOpen ) {
			return;
		}
		isOpen = false;
		root.classList.remove( 'is-open' );
		setExpanded( false );
		if ( releaseFocus ) {
			releaseFocus();
		}
		BB.unlockScroll();
		window.setTimeout( () => {
			if ( ! isOpen ) {
				root.hidden = true;
			}
		}, 300 );
		if ( lastFocus && typeof lastFocus.focus === 'function' ) {
			lastFocus.focus();
		}
	}

	/* ------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	const money = ( amount, currency ) => BB.formatPrice( amount, currency );

	function lineTotal( item ) {
		const t = item.totals;
		const amount = parseInt( t.line_subtotal, 10 ) + ( config.taxInclusive ? parseInt( t.line_subtotal_tax, 10 ) : 0 );
		return money( amount, t );
	}

	function itemMeta( item ) {
		const rows = [];
		( item.variation || [] ).forEach( ( v ) => rows.push( `${ BB.decodeEntities( v.attribute ) }: ${ BB.decodeEntities( v.value ) }` ) );
		( item.item_data || [] ).forEach( ( d ) =>
			rows.push( `${ BB.decodeEntities( d.name || d.key ) }: ${ BB.decodeEntities( d.display || d.value ) }` )
		);
		return rows.length ? el( 'ul', { class: 'brewbean-line__meta' }, rows.map( ( r ) => el( 'li', {}, r ) ) ) : null;
	}

	function quantityControl( item ) {
		const limits = item.quantity_limits || { minimum: 1, maximum: 9999, multiple_of: 1, editable: true };
		const name = BB.decodeEntities( item.name );
		if ( ! limits.editable ) {
			return el( 'span', { class: 'brewbean-qty brewbean-qty--static' }, `× ${ item.quantity }` );
		}
		const step = limits.multiple_of || 1;
		return el( 'div', { class: 'brewbean-qty', role: 'group', 'aria-label': `${ __( 'Quantity', TD ) }: ${ name }` }, [
			el(
				'button',
				{ type: 'button', class: 'brewbean-qty__btn', 'data-action': 'dec', 'aria-label': __( 'Decrease quantity', TD ), disabled: item.quantity - step < limits.minimum },
				'−'
			),
			el( 'input', {
				type: 'number',
				class: 'brewbean-qty__input',
				value: String( item.quantity ),
				min: String( limits.minimum ),
				max: String( limits.maximum ),
				step: String( step ),
				inputmode: 'numeric',
				'aria-label': __( 'Quantity', TD ),
			} ),
			el(
				'button',
				{ type: 'button', class: 'brewbean-qty__btn', 'data-action': 'inc', 'aria-label': __( 'Increase quantity', TD ), disabled: item.quantity + step > limits.maximum },
				'+'
			),
		] );
	}

	function renderItem( item ) {
		const name = BB.decodeEntities( item.name );
		const image = item.images && item.images[ 0 ];
		const p = item.prices;
		const discounted = parseInt( p.regular_price, 10 ) > parseInt( p.price, 10 );

		const price = el( 'p', { class: 'brewbean-line__unit' }, [
			discounted ? el( 'del', {}, money( p.regular_price, p ) ) : null,
			discounted ? ' ' : null,
			el( discounted ? 'ins' : 'span', {}, money( p.price, p ) ),
			el( 'span', { class: 'brewbean-line__each' }, ` ${ __( 'each', TD ) }` ),
		] );

		const remove = el( 'button', { type: 'button', class: 'brewbean-line__remove', 'data-action': 'remove' }, [
			el( 'span', { 'aria-hidden': 'true', class: 'brewbean-line__remove-icon' } ),
			el( 'span', { class: 'screen-reader-text' }, `${ __( 'Remove', TD ) } ${ name }` ),
			el( 'span', { 'aria-hidden': 'true' }, __( 'Remove', TD ) ),
		] );

		return el( 'li', { class: 'brewbean-line', 'data-key': item.key }, [
			el( 'a', { class: 'brewbean-line__image', href: item.permalink, tabindex: '-1', 'aria-hidden': 'true' }, [
				el( 'img', { src: image ? image.thumbnail : config.placeholder, alt: '', width: '72', height: '72', loading: 'lazy' } ),
			] ),
			el( 'div', { class: 'brewbean-line__details' }, [
				el( 'a', { class: 'brewbean-line__name', href: item.permalink }, name ),
				itemMeta( item ),
				price,
				el( 'div', { class: 'brewbean-line__controls' }, [ quantityControl( item ), remove ] ),
			] ),
			el( 'p', { class: 'brewbean-line__total' }, lineTotal( item ) ),
		] );
	}

	function renderEmpty() {
		return el( 'div', { class: 'brewbean-side-cart__empty' }, [
			el( 'span', { class: 'brewbean-side-cart__empty-icon', 'aria-hidden': 'true' } ),
			el( 'p', { class: 'brewbean-side-cart__empty-title' }, __( 'Your cart is empty', TD ) ),
			el( 'p', {}, __( 'Freshly roasted beans are waiting for you.', TD ) ),
			el( 'a', { class: 'brewbean-button', href: config.shopUrl }, __( 'Browse coffee', TD ) ),
		] );
	}

	function updateCounts( count ) {
		document.querySelectorAll( '[data-brewbean-cart-count]' ).forEach( ( badge ) => {
			badge.textContent = String( count );
			badge.classList.toggle( 'is-empty', count === 0 );
			badge.classList.remove( 'is-bump' );
			// Restart the bump animation.
			void badge.offsetWidth;
			badge.classList.add( 'is-bump' );
		} );
		document.querySelectorAll( '[data-brewbean-open-cart] .screen-reader-text' ).forEach( ( sr ) => {
			/* translators: %d: number of items */
			sr.textContent = __( 'Open cart', TD ) + ` (${ count })`;
		} );
		drawerCount.textContent = count ? `(${ count })` : '';
	}

	function render( cart ) {
		loaded = true;
		body.setAttribute( 'aria-busy', 'false' );
		updateCounts( cart.items_count || 0 );

		const shipping = cart.extensions && cart.extensions.brewbean && cart.extensions.brewbean.shipping;
		progressEl.replaceChildren();
		if ( config.progress && shipping && cart.items.length ) {
			progressEl.append( BB.progressBar( shipping, 'side-cart' ) );
		}

		if ( ! cart.items.length ) {
			body.replaceChildren( renderEmpty() );
			footer.hidden = true;
			return;
		}

		const list = el( 'ul', { class: 'brewbean-lines' }, cart.items.map( renderItem ) );
		// Keep keyboard focus on the same control after re-rendering.
		const active = document.activeElement;
		const activeKey = active && active.closest && active.closest( '.brewbean-line' )?.dataset.key;
		const activeAction = active && ( active.dataset.action || ( active.classList.contains( 'brewbean-qty__input' ) ? 'input' : '' ) );

		body.replaceChildren( list );
		footer.hidden = false;

		const t = cart.totals;
		const subtotal = parseInt( t.total_items, 10 ) + ( config.taxInclusive ? parseInt( t.total_items_tax, 10 ) : 0 );
		subtotalEl.textContent = money( subtotal, t );

		if ( activeKey && ! active.isConnected ) {
			const line = body.querySelector( `.brewbean-line[data-key="${ CSS.escape( activeKey ) }"]` );
			const target = line && ( activeAction === 'input' ? line.querySelector( '.brewbean-qty__input' ) : line.querySelector( `[data-action="${ activeAction }"]` ) );
			if ( target && ! target.disabled ) {
				target.focus();
			} else {
				panel.focus();
			}
		}
	}

	function showError( error ) {
		noticeEl.textContent = ( error && error.message ) || __( 'Something went wrong. Please try again.', TD );
		noticeEl.hidden = false;
		window.clearTimeout( showError.timer );
		showError.timer = window.setTimeout( () => ( noticeEl.hidden = true ), 6000 );
		// Re-sync with the server state.
		BB.cart.refresh().catch( () => {} );
	}

	/* ------------------------------------------------------------------
	 * Cart mutations
	 * ---------------------------------------------------------------- */

	function syncOtherViews() {
		const $ = window.jQuery;
		if ( ! $ ) {
			return;
		}
		// Let classic WooCommerce views catch up (cart page table, checkout review, fragments).
		if ( config.isCart ) {
			$( document.body ).trigger( 'wc_update_cart' );
		} else if ( config.isCheckout ) {
			$( document.body ).trigger( 'update_checkout' );
		}
		$( document.body ).trigger( 'wc_fragment_refresh' );
	}

	function setBusy( key, busy ) {
		const line = body.querySelector( `.brewbean-line[data-key="${ CSS.escape( key ) }"]` );
		if ( line ) {
			line.classList.toggle( 'is-busy', busy );
			line.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		}
	}

	function scheduleQuantity( key, quantity ) {
		window.clearTimeout( pending.get( key ) );
		pending.set(
			key,
			window.setTimeout( () => {
				pending.delete( key );
				setBusy( key, true );
				BB.cart
					.updateItem( key, quantity )
					.then( syncOtherViews )
					.catch( showError );
			}, 350 )
		);
	}

	function removeItem( key ) {
		setBusy( key, true );
		const line = body.querySelector( `.brewbean-line[data-key="${ CSS.escape( key ) }"]` );
		if ( line ) {
			line.classList.add( 'is-removing' );
		}
		BB.cart
			.removeItem( key )
			.then( () => {
				syncOtherViews();
				panel.focus();
			} )
			.catch( showError );
	}

	body.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '[data-action]' );
		if ( ! button ) {
			return;
		}
		const line = button.closest( '.brewbean-line' );
		const key = line && line.dataset.key;
		if ( ! key ) {
			return;
		}
		if ( button.dataset.action === 'remove' ) {
			removeItem( key );
			return;
		}
		const input = line.querySelector( '.brewbean-qty__input' );
		const step = parseInt( input.step, 10 ) || 1;
		const min = parseInt( input.min, 10 ) || 1;
		const max = parseInt( input.max, 10 ) || 9999;
		const next = Math.min( max, Math.max( min, ( parseInt( input.value, 10 ) || min ) + ( button.dataset.action === 'inc' ? step : -step ) ) );
		input.value = String( next );
		line.querySelector( '[data-action="dec"]' ).disabled = next - step < min;
		line.querySelector( '[data-action="inc"]' ).disabled = next + step > max;
		scheduleQuantity( key, next );
	} );

	body.addEventListener( 'change', ( event ) => {
		const input = event.target.closest( '.brewbean-qty__input' );
		if ( ! input ) {
			return;
		}
		const key = input.closest( '.brewbean-line' ).dataset.key;
		const quantity = parseInt( input.value, 10 );
		if ( Number.isNaN( quantity ) || quantity <= 0 ) {
			removeItem( key );
		} else {
			scheduleQuantity( key, quantity );
		}
	} );

	/* ------------------------------------------------------------------
	 * Wiring
	 * ---------------------------------------------------------------- */

	document.addEventListener( 'click', ( event ) => {
		if ( event.target.closest( '[data-brewbean-open-cart]' ) ) {
			event.preventDefault();
			open();
		} else if ( event.target.closest( '[data-brewbean-close-cart]' ) ) {
			close();
		}
	} );

	BB.on( 'cart-updated', ( { cart } ) => render( cart ) );
	BB.on( 'open-cart', open );
	BB.on( 'added', () => {
		if ( config.autoOpen && ! config.isCart && ! config.isCheckout ) {
			open();
		}
	} );

	// Products added by WooCommerce itself (blocks use the Store API; classic loops use wc-ajax).
	const externalAdd = () => BB.cart.refresh().then( () => BB.emit( 'added', { source: 'woocommerce' } ) ).catch( () => {} );
	document.body.addEventListener( 'wc-blocks_added_to_cart', externalAdd );
	document.body.addEventListener( 'wc-blocks_removed_from_cart', () => BB.cart.refresh().catch( () => {} ) );
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'added_to_cart', externalAdd );
		window.jQuery( document.body ).on( 'removed_from_cart updated_wc_div updated_cart_totals', () => BB.cart.refresh().catch( () => {} ) );
	}
} )();
