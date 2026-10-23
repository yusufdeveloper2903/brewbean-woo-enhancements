<?php
/**
 * Side cart drawer shell. Contents are rendered by assets/js/side-cart.js.
 *
 * Override by copying to yourtheme/brewbean/side-cart.php (keep the data-* hooks).
 *
 * @package BrewBean\WooEnhancements
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="brewbean-side-cart" id="brewbean-side-cart" data-brewbean-side-cart hidden>
	<div class="brewbean-side-cart__overlay" data-brewbean-close-cart></div>
	<aside class="brewbean-side-cart__panel" role="dialog" aria-modal="true" aria-labelledby="brewbean-side-cart-title" tabindex="-1">
		<header class="brewbean-side-cart__header">
			<h2 class="brewbean-side-cart__title" id="brewbean-side-cart-title">
				<?php esc_html_e( 'Your cart', 'brewbean-woo-enhancements' ); ?>
				<span class="brewbean-side-cart__count" data-brewbean-drawer-count></span>
			</h2>
			<button type="button" class="brewbean-icon-button" data-brewbean-close-cart>
				<span class="screen-reader-text"><?php esc_html_e( 'Close cart', 'brewbean-woo-enhancements' ); ?></span>
				<svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
			</button>
		</header>
		<div class="brewbean-side-cart__progress" data-brewbean-drawer-progress></div>
		<div class="brewbean-side-cart__notice" data-brewbean-drawer-notice role="alert" hidden></div>
		<div class="brewbean-side-cart__body" data-brewbean-drawer-body aria-live="polite" aria-busy="true">
			<div class="brewbean-skeleton" aria-hidden="true"><span></span><span></span><span></span></div>
		</div>
		<footer class="brewbean-side-cart__footer" data-brewbean-drawer-footer hidden>
			<dl class="brewbean-side-cart__totals">
				<dt><?php esc_html_e( 'Subtotal', 'brewbean-woo-enhancements' ); ?></dt>
				<dd data-brewbean-drawer-subtotal></dd>
			</dl>
			<p class="brewbean-side-cart__note"><?php esc_html_e( 'Shipping and taxes are calculated at checkout.', 'brewbean-woo-enhancements' ); ?></p>
			<div class="brewbean-side-cart__actions">
				<a class="brewbean-button brewbean-button--ghost" href="<?php echo esc_url( wc_get_cart_url() ); ?>"><?php esc_html_e( 'View cart', 'brewbean-woo-enhancements' ); ?></a>
				<a class="brewbean-button" href="<?php echo esc_url( wc_get_checkout_url() ); ?>"><?php esc_html_e( 'Checkout', 'brewbean-woo-enhancements' ); ?></a>
			</div>
		</footer>
	</aside>
</div>
