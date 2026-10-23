<?php
/**
 * AJAX add-to-cart for full product forms (simple, variable, subscribe options).
 *
 * The JS posts the product form to ?wc-ajax=brewbean_add_to_cart. WooCommerce's own
 * WC_Form_Handler::add_to_cart_action() (wp_loaded) processes the `add-to-cart`
 * field exactly as for a normal form submit – so stock checks, variation matching
 * and every third-party `woocommerce_add_to_cart_validation` filter still apply.
 * This endpoint then turns the resulting notices into a JSON response.
 *
 * @package BrewBean\WooEnhancements
 */

declare( strict_types=1 );

namespace BrewBean\WooEnhancements\Features;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the wc-ajax endpoint.
 */
final class AddToCart {

	public const ENDPOINT = 'brewbean_add_to_cart';

	/**
	 * Whether WooCommerce added an item during this request.
	 */
	private bool $added = false;

	/**
	 * Hook everything.
	 */
	public function register(): void {
		add_action( 'wc_ajax_' . self::ENDPOINT, array( $this, 'handle' ) );

		if ( $this->is_our_request() ) {
			// Never redirect from inside the AJAX request.
			add_filter( 'woocommerce_add_to_cart_redirect', '__return_false', PHP_INT_MAX );
			add_filter( 'pre_option_woocommerce_cart_redirect_after_add', static fn () => 'no' );
			add_action( 'woocommerce_add_to_cart', fn () => $this->added = true );
		}
	}

	/**
	 * Is the current request our endpoint?
	 */
	private function is_our_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check.
		return isset( $_GET['wc-ajax'] ) && self::ENDPOINT === sanitize_key( wp_unslash( $_GET['wc-ajax'] ) );
	}

	/**
	 * Respond with the outcome of the add-to-cart attempt.
	 */
	public function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- mirrors WooCommerce's nonce-less add-to-cart form.
		$product_id = isset( $_POST['add-to-cart'] ) ? absint( wp_unslash( $_POST['add-to-cart'] ) ) : 0;
		// phpcs:enable

		if ( ! $product_id || ! wc_get_product( $product_id ) ) {
			wp_send_json_error( array( 'messages' => array( __( 'That product could not be found.', 'brewbean-woo-enhancements' ) ) ), 400 );
		}

		$errors = array_map(
			static fn ( array $notice ): string => wp_strip_all_tags( html_entity_decode( (string) $notice['notice'], ENT_QUOTES, 'UTF-8' ) ),
			wc_get_notices( 'error' )
		);

		// The success notice ("… has been added to your cart") is replaced by the side cart.
		wc_clear_notices();

		if ( $errors || ! $this->added ) {
			wp_send_json_error(
				array( 'messages' => $errors ? $errors : array( __( 'Please choose product options before adding this product to your cart.', 'brewbean-woo-enhancements' ) ) ),
				422
			);
		}

		/**
		 * Fires after a product was added through the Brew & Bean AJAX endpoint.
		 *
		 * @param int $product_id Product ID.
		 */
		do_action( 'brewbean_we_ajax_added_to_cart', $product_id );

		wp_send_json_success(
			array(
				'productId'  => $product_id,
				'count'      => WC()->cart->get_cart_contents_count(),
				'storeNonce' => wp_create_nonce( 'wc_store_api' ),
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
			)
		);
	}
}
