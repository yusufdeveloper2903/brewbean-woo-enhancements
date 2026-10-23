/**
 * End-to-end walkthrough + README screenshots.
 *
 * Runs against a fresh `npm run dev` site and walks the full customer journey:
 * shop → quick view (variable product, subscribe & save) → side cart (quantity change,
 * free-shipping progress) → product page → checkout with delivery date validation →
 * order placed → admin order screen → settings page (save round-trip) → mobile view.
 *
 * Fails (exit code 1) on broken steps or JavaScript errors.
 *
 * Usage: npm run dev   (in another terminal)
 *        npm run screenshots
 * Env:   BREWBEAN_URL (default http://127.0.0.1:9402)
 */
import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const BASE = ( process.env.BREWBEAN_URL || 'http://127.0.0.1:9402' ).replace( /\/$/, '' );
const OUT = join( dirname( fileURLToPath( import.meta.url ) ), '..', 'docs', 'screenshots' );
const TIMEOUT = 60_000;

const jsErrors = [];
const results = [];

function check( label, condition, detail = '' ) {
	results.push( { label, ok: !! condition, detail } );
	console.log( `${ condition ? '✔' : '✘' } ${ label }${ detail ? ` – ${ detail }` : '' }` );
	if ( ! condition ) {
		process.exitCode = 1;
	}
}

function watch( page, name ) {
	page.on( 'console', ( msg ) => {
		// WordPress admin's cross-document view transitions can log this harmless core message.
		if ( msg.type() === 'error' && ! /Transition was aborted because of invalid state/.test( msg.text() ) ) {
			jsErrors.push( `[${ name }] ${ msg.text() }` );
		}
	} );
	page.on( 'pageerror', ( error ) => jsErrors.push( `[${ name }] ${ error.message }` ) );
}

// WordPress Playground auto-logs-in every new visitor when started with --login.
// Shoppers must stay logged out, so mark the auto-login as already done for them.
const asGuest = ( context ) =>
	context.addCookies( [ { name: 'playground_auto_login_already_happened', value: '1', url: BASE } ] );

const shot = ( page, file, options = {} ) => page.screenshot( { path: join( OUT, file ), ...options } );
const text = ( page, selector ) => page.locator( selector ).first().innerText();

await mkdir( OUT, { recursive: true } );
const browser = await chromium.launch();

/* ---------------------------------------------------------------------
 * Customer journey (desktop, logged out)
 * ------------------------------------------------------------------- */
const customer = await browser.newContext( { viewport: { width: 1440, height: 900 }, locale: 'en-US' } );
customer.setDefaultTimeout( TIMEOUT );
await asGuest( customer );
const page = await customer.newPage();
watch( page, 'customer' );

// Warm-up visit (first request after boot primes WordPress caches).
await page.goto( `${ BASE }/`, { waitUntil: 'networkidle' } );

// 1. Shop.
await page.goto( `${ BASE }/`, { waitUntil: 'networkidle' } );
check( 'Shop lists products', ( await page.locator( '.wc-block-product, li.product' ).count() ) >= 10 );
check( 'Quick view buttons rendered', ( await page.locator( '[data-brewbean-quick-view]' ).count() ) >= 10 );
check( 'Header cart toggle rendered', ( await page.locator( '[data-brewbean-open-cart]' ).count() ) === 1 );
await shot( page, '01-shop.png' );

// 2. Add a simple product from the product card (WooCommerce block button) → side cart opens.
await page.locator( '.wc-block-product', { hasText: 'Ethiopia Yirgacheffe' } ).locator( 'button.add_to_cart_button' ).click();
await page.locator( '[data-brewbean-side-cart].is-open .brewbean-line' ).first().waitFor();
check( 'Side cart opens after block add-to-cart', true );
const progress1 = await text( page, '[data-brewbean-drawer-progress] .brewbean-progress__message' );
check( 'Progress bar shows remaining amount', /\$31\.00/.test( progress1 ), progress1 );
await page.keyboard.press( 'Escape' );
await page.locator( '[data-brewbean-side-cart]' ).waitFor( { state: 'hidden' } );
check( 'Esc closes the side cart', true );

// 3. Quick view on the variable product.
const qvButton = page.locator( '[data-brewbean-quick-view]' ).first();
await qvButton.click();
await page.locator( '[data-brewbean-modal].is-open [data-brewbean-qv]' ).waitFor();
check( 'Quick view opens', true );
check( 'Quick view moves focus into the dialog', await page.evaluate( () => !! document.activeElement.closest( '.brewbean-modal__dialog' ) ) );
// Focus trap: tab through every control and make sure focus never leaves the dialog.
let escaped = false;
for ( let i = 0; i < 30; i++ ) {
	await page.keyboard.press( 'Tab' );
	if ( ! ( await page.evaluate( () => !! document.activeElement.closest( '.brewbean-modal__dialog' ) ) ) ) {
		escaped = true;
		break;
	}
}
check( 'Quick view traps focus', ! escaped );
await page.keyboard.press( 'Escape' );
await page.locator( '[data-brewbean-modal]' ).waitFor( { state: 'hidden' } );
check( 'Esc closes quick view and returns focus', await qvButton.evaluate( ( node ) => node === document.activeElement ) );

await qvButton.click();
await page.locator( '[data-brewbean-modal].is-open [data-brewbean-qv]' ).waitFor();
const modal = page.locator( '[data-brewbean-modal]' );
await modal.locator( '.brewbean-swatch', { hasText: '500 g' } ).click();
await modal.locator( '.brewbean-swatch', { hasText: 'Espresso' } ).click();
check( 'Variation price updates', ( await text( page, '[data-brewbean-qv-price]' ) ).includes( '26.00' ) );
await modal.locator( 'label.brewbean-option--subscribe' ).click();
check( 'Subscription price for variation', ( await text( page, '[data-brewbean-sub-price]' ) ).includes( '22.10' ) );
await modal.locator( '[data-brewbean-qv-price]' ).hover();
await page.waitForTimeout( 500 );
await shot( page, '02-quick-view.png' );

await modal.locator( '[data-brewbean-submit]' ).click();
await page.locator( '[data-brewbean-side-cart].is-open .brewbean-line >> nth=1' ).waitFor();
check( 'Quick view add-to-cart opens side cart with 2 lines', ( await page.locator( '.brewbean-line' ).count() ) === 2 );
const subLine = page.locator( '.brewbean-line', { hasText: 'House Blend' } );
check( 'Subscription shown on the cart line', ( await subLine.innerText() ).includes( 'Every 2 weeks' ) );
check( 'Discounted price applied in cart', ( await subLine.locator( '.brewbean-line__total' ).innerText() ).includes( '22.10' ) );
await page.waitForTimeout( 700 );
await shot( page, '03-side-cart.png' );

// 4. Change quantity in the side cart → totals + progress update without reload.
const marker = await page.evaluate( () => ( window.__brewbeanNoReload = Math.random() ) );
await page.locator( '.brewbean-line', { hasText: 'Ethiopia' } ).locator( '[data-action="inc"]' ).click();
await page.waitForFunction( () => document.querySelector( '[data-brewbean-drawer-subtotal]' ).textContent.includes( '60.10' ) );
check( 'Quantity change updates subtotal', true, await text( page, '[data-brewbean-drawer-subtotal]' ) );
const progress2 = await text( page, '[data-brewbean-drawer-progress] .brewbean-progress__message' );
check( 'Progress bar switches to free shipping', /free/i.test( progress2 ), progress2 );
check( 'No page reload happened', ( await page.evaluate( () => window.__brewbeanNoReload ) ) === marker );
await page.keyboard.press( 'Escape' );

// 5. Product page with subscribe & save (AJAX add to cart, stays on page).
await page.goto( `${ BASE }/product/kenya-nyeri/`, { waitUntil: 'networkidle' } );
await page.locator( 'label.brewbean-option--subscribe' ).click();
check( 'Interval select appears when subscribing', await page.locator( '[data-brewbean-interval]' ).isVisible() );
await page.selectOption( 'select[name="brewbean_interval"]', '4-weeks' );
await page.locator( '.brewbean-purchase-options' ).scrollIntoViewIfNeeded();
await page.evaluate( () => window.scrollTo( 0, 0 ) );
await shot( page, '04-product-subscribe.png' );
await page.locator( 'form.cart button[name="add-to-cart"], form.cart .single_add_to_cart_button' ).first().click();
await page.locator( '[data-brewbean-side-cart].is-open .brewbean-line >> nth=2' ).waitFor();
check( 'Product page AJAX add opens side cart', page.url().endsWith( '/product/kenya-nyeri/' ) );
check( 'Kenya subscribed at 10% off ($19.80)', ( await page.locator( '.brewbean-line', { hasText: 'Kenya' } ).innerText() ).includes( '19.80' ) );
await page.keyboard.press( 'Escape' );

// 6. Cart page shows the progress bar.
await page.goto( `${ BASE }/cart/`, { waitUntil: 'networkidle' } );
check( 'Cart page progress bar', ( await page.locator( '.woocommerce-cart-form .brewbean-progress--cart' ).count() ) === 1 );

// 7. Checkout with delivery date validation.
await page.goto( `${ BASE }/checkout/`, { waitUntil: 'networkidle' } );
const fill = async ( selector, value ) => page.fill( selector, value );
await fill( '#billing_first_name', 'Ada' );
await fill( '#billing_last_name', 'Lovelace' );
await fill( '#billing_address_1', '42 Analytical Ave' );
await fill( '#billing_city', 'Brooklyn' );
await fill( '#billing_postcode', '11201' );
await fill( '#billing_phone', '555-0100' );
await fill( '#billing_email', 'ada@example.com' );
await page.evaluate( () => {
	const set = ( id, value ) => {
		const el = document.getElementById( id );
		if ( el ) {
			el.value = value;
			if ( window.jQuery ) {
				window.jQuery( el ).trigger( 'change' );
			}
		}
	};
	set( 'billing_country', 'US' );
	set( 'billing_state', 'NY' );
} );
await page.waitForLoadState( 'networkidle' );

const dateInput = page.locator( '#brewbean_delivery_date' );
check( 'Delivery date field on checkout', ( await dateInput.count() ) === 1 );
const min = await dateInput.getAttribute( 'min' );

const placeOrder = async () => {
	await page.locator( '#payment_method_cod' ).check();
	await page.locator( '#place_order' ).click();
};

// Screenshot with a valid date before exercising the validation.
await dateInput.fill( min );
await dateInput.dispatchEvent( 'change' );
await page.locator( '#billing_first_name' ).focus();
await page.locator( '#billing_first_name' ).blur();
await page.waitForLoadState( 'networkidle' );
await page.waitForFunction( () => ! document.querySelector( '.blockUI' ) );
await page.evaluate( () => window.scrollTo( 0, document.querySelector( '.woocommerce-shipping-fields' ).getBoundingClientRect().top + window.scrollY - 60 ) );
await page.waitForTimeout( 600 );
await shot( page, '05-checkout-delivery-date.png' );

// a) Empty → required error.
await dateInput.fill( '' );
await page.evaluate( () => document.querySelector( 'form.checkout' ).setAttribute( 'novalidate', '' ) );
await placeOrder();
await page.locator( '.woocommerce-error, .woocommerce-NoticeGroup-checkout' ).first().waitFor();
check( 'Empty date is rejected', /Preferred delivery date/i.test( await text( page, '.woocommerce-NoticeGroup-checkout, .woocommerce-error' ) ) );

// b) A Sunday inside the window → rejected server-side (browser validation bypassed).
const sunday = ( () => {
	const d = new Date( `${ min }T12:00:00` );
	while ( d.getDay() !== 0 ) {
		d.setDate( d.getDate() + 1 );
	}
	return d.toISOString().slice( 0, 10 );
} )();
await dateInput.evaluate( ( el, v ) => {
	el.value = v;
}, sunday );
await placeOrder();
await page.waitForFunction( () => /Sundays/.test( document.querySelector( '.woocommerce-NoticeGroup-checkout' )?.textContent || '' ) );
check( 'Sunday is rejected', true, sunday );

// c) Valid date → order placed.
await dateInput.fill( min );
await placeOrder();
await page.waitForURL( /order-received/, { timeout: 90_000 } );
const orderId = ( page.url().match( /order-received\/(\d+)/ ) || [] )[ 1 ];
check( 'Order placed', !! orderId, `#${ orderId }` );
check( 'Thank-you page shows the delivery date', /Preferred delivery date/.test( await page.locator( 'body' ).innerText() ) );
await customer.close();

/* ---------------------------------------------------------------------
 * Admin
 * ------------------------------------------------------------------- */
const admin = await browser.newContext( { viewport: { width: 1440, height: 1000 } } );
admin.setDefaultTimeout( TIMEOUT );
const adminPage = await admin.newPage();
watch( adminPage, 'admin' );
await adminPage.goto( `${ BASE }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
if ( await adminPage.locator( '#user_login' ).count() ) {
	await adminPage.fill( '#user_login', 'admin' );
	await adminPage.fill( '#user_pass', 'password' );
	await adminPage.click( '#wp-submit' );
	await adminPage.waitForURL( /wp-admin/ );
}

await adminPage.goto( `${ BASE }/wp-admin/admin.php?page=wc-orders&action=edit&id=${ orderId }`, { waitUntil: 'networkidle' } );
const adminDelivery = adminPage.locator( '.brewbean-admin-delivery' );
check( 'Admin order (HPOS screen) shows delivery date', ( await adminDelivery.count() ) === 1 && ! /Not specified/.test( await adminDelivery.innerText() ), await adminDelivery.innerText().catch( () => '' ) );
check( 'Admin order shows subscription item meta', /Every 2 weeks/.test( await adminPage.locator( '#order_line_items' ).innerText() ) );
await adminPage.evaluate( () => document.querySelector( '#order_data' )?.scrollIntoView() );
await adminPage.evaluate( () => window.scrollBy( 0, -60 ) );
await shot( adminPage, '06-admin-order.png' );

await adminPage.goto( `${ BASE }/wp-admin/admin.php?page=wc-orders`, { waitUntil: 'networkidle' } );
check( 'Orders list has a delivery date column', ( await adminPage.locator( 'th#brewbean_delivery, th.column-brewbean_delivery' ).count() ) > 0 );

// Settings: screenshot, then a save round-trip through options.php (nonce + sanitization).
await adminPage.goto( `${ BASE }/wp-admin/admin.php?page=brewbean-settings`, { waitUntil: 'networkidle' } );
await shot( adminPage, '07-settings.png', { fullPage: true } );
await adminPage.fill( '#brewbean-free_shipping_threshold', '75abc' );
await adminPage.click( '#submit' );
await adminPage.waitForURL( /settings-updated=true/ );
check( 'Settings save (sanitized "75abc" → 75)', ( await adminPage.inputValue( '#brewbean-free_shipping_threshold' ) ) === '75' );
await adminPage.fill( '#brewbean-free_shipping_threshold', '50' );
await adminPage.click( '#submit' );
await adminPage.waitForURL( /settings-updated=true/ );
check( 'Settings restored', ( await adminPage.inputValue( '#brewbean-free_shipping_threshold' ) ) === '50' );

// Product data tab.
await adminPage.goto( `${ BASE }/wp-admin/edit.php?post_type=product&s=House+Blend`, { waitUntil: 'networkidle' } );
await adminPage.locator( '#the-list a.row-title' ).first().click();
await adminPage.waitForLoadState( 'networkidle' );
await adminPage.locator( '.brewbean_subscribe_options a, li.brewbean_subscribe_tab a' ).first().click();
check( 'Product data tab "Subscribe & save"', await adminPage.locator( '#brewbean_subscribe_data' ).isVisible() );
await admin.close();

/* ---------------------------------------------------------------------
 * Mobile
 * ------------------------------------------------------------------- */
const mobile = await browser.newContext( {
	viewport: { width: 390, height: 844 },
	deviceScaleFactor: 2,
	isMobile: true,
	hasTouch: true,
} );
mobile.setDefaultTimeout( TIMEOUT );
await asGuest( mobile );
const mobilePage = await mobile.newPage();
watch( mobilePage, 'mobile' );
await mobilePage.goto( `${ BASE }/`, { waitUntil: 'networkidle' } );
await mobilePage.locator( '.wc-block-product', { hasText: 'Midnight Espresso' } ).locator( 'button.add_to_cart_button' ).tap();
await mobilePage.locator( '[data-brewbean-side-cart].is-open .brewbean-line' ).first().waitFor();
await mobilePage.locator( '[data-brewbean-close-cart].brewbean-icon-button' ).tap();
await mobilePage.locator( '[data-brewbean-side-cart]' ).waitFor( { state: 'hidden' } );
await mobilePage.locator( '.wc-block-product', { hasText: 'Hand Burr Grinder' } ).locator( 'button.add_to_cart_button' ).tap();
await mobilePage.locator( '[data-brewbean-side-cart].is-open .brewbean-line >> nth=1' ).waitFor();
check( 'Mobile: side cart works with touch', true );
await mobilePage.waitForTimeout( 600 );
await shot( mobilePage, '08-mobile-side-cart.png' );
await mobile.close();

await browser.close();

check( 'No JavaScript errors', jsErrors.length === 0, jsErrors.join( '\n' ) );
const failed = results.filter( ( r ) => ! r.ok ).length;
console.log( `\n${ results.length - failed }/${ results.length } checks passed. Screenshots in docs/screenshots/.` );
