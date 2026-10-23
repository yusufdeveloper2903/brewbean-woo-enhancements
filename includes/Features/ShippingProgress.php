<?php
/**
 * Free-shipping progress bar + the threshold rule behind it.
 *
 * - Server-rendered bar on the classic cart page (refreshed by WooCommerce's own cart AJAX),
 *   and above the Cart block when the block cart is used.
 * - Exposes the progress data on the Store API cart (`extensions.brewbean`) so the
 *   side cart can render it without extra requests.
 * - Optionally makes every shipping rate free once the threshold is met.
 *
 * @package BrewBean\WooEnhancements
 */

declare( strict_types=1 );

namespace BrewBean\WooEnhancements\Features;

use Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema;
use BrewBean\WooEnhancements\Plugin;
use BrewBean\WooEnhancements\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Free-shipping progress feature.
 */
final class ShippingProgress {

	/**
	 * Hook everything.
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'woocommerce_before_cart_table', array( $this, 'render_cart_page_bar' ) );
		add_filter( 'render_block_woocommerce/cart', array( $this, 'prepend_to_cart_block' ) );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'fragments' ) );
		$this->register_shipping_rules();
		$this->register_store_api_data();
	}

	/**
	 * Free-shipping rate rule + Store API data (used even when the bar is hidden).
	 */
	public function register_shipping_rules(): void {
		if ( ! Settings::enabled( 'apply_free_shipping' ) ) {
			return;
		}
		add_filter( 'woocommerce_package_rates', array( $this, 'apply_free_shipping' ), 100 );
		add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'bust_rate_cache' ) );
	}

	/**
	 * Styles for the cart page bar.
	 */
	public function enqueue(): void {
		if ( is_cart() ) {
			wp_enqueue_style( 'brewbean-we-frontend' );
		}
	}

	/**
	 * Progress numbers for the current cart.
	 *
	 * @return array{threshold: float, subtotal: float, remaining: float, percent: int, qualified: bool}
	 */
	public static function data(): array {
		$threshold = Settings::threshold();
		$subtotal  = ( function_exists( 'WC' ) && WC()->cart ) ? (float) WC()->cart->get_displayed_subtotal() : 0.0;
		$remaining = max( 0.0, $threshold - $subtotal );

		return array(
			'threshold' => $threshold,
			'subtotal'  => $subtotal,
			'remaining' => $remaining,
			'percent'   => $threshold > 0 ? (int) min( 100, floor( $subtotal / $threshold * 100 ) ) : 100,
			'qualified' => $threshold <= 0 || $subtotal >= $threshold,
		);
	}

	/**
	 * Human readable message for the bar.
	 *
	 * @param array<string, mixed> $data Output of data().
	 */
	public static function message( array $data ): string {
		if ( $data['qualified'] ) {
			return esc_html__( 'Nice! Your order ships for free.', 'brewbean-woo-enhancements' );
		}
		return sprintf(
			/* translators: %s: amount left to spend, e.g. $12.00 */
			esc_html__( 'You are %s away from free shipping.', 'brewbean-woo-enhancements' ),
			'<strong>' . wp_kses_post( wc_price( $data['remaining'] ) ) . '</strong>'
		);
	}

	/**
	 * Bar markup (template: brewbean/progress-bar.php).
	 *
	 * @param string $context Where it is rendered (cart|block-cart).
	 */
	public static function html( string $context = 'cart' ): string {
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return '<div class="brewbean-progress brewbean-progress--' . esc_attr( $context ) . ' is-empty"></div>';
		}
		$data = self::data();
		return Plugin::template_html(
			'progress-bar.php',
			array(
				'context' => $context,
				'data'    => $data,
				'message' => self::message( $data ),
			)
		);
	}

	/**
	 * Classic cart page (inside the cart form, so WooCommerce refreshes it on update).
	 */
	public function render_cart_page_bar(): void {
		echo self::html( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
	}

	/**
	 * Block cart support.
	 *
	 * @param string $content Rendered block.
	 */
	public function prepend_to_cart_block( string $content ): string {
		wp_enqueue_style( 'brewbean-we-frontend' );
		return self::html( 'cart' ) . $content;
	}

	/**
	 * Keep the bar fresh when classic fragments are refreshed.
	 *
	 * @param array<string, string> $fragments Fragments.
	 * @return array<string, string>
	 */
	public function fragments( array $fragments ): array {
		$fragments['div.brewbean-progress--cart'] = self::html( 'cart' );
		return $fragments;
	}

	/**
	 * Make shipping free when the threshold is reached.
	 *
	 * @param array<string, \WC_Shipping_Rate> $rates Rates for the package.
	 * @return array<string, \WC_Shipping_Rate>
	 */
	public function apply_free_shipping( array $rates ): array {
		if ( ! self::data()['qualified'] ) {
			return $rates;
		}
		$threshold = html_entity_decode( wp_strip_all_tags( wc_price( Settings::threshold() ) ), ENT_QUOTES, 'UTF-8' );
		foreach ( $rates as $rate ) {
			if ( (float) $rate->get_cost() > 0 ) {
				$rate->set_cost( '0' );
				$rate->set_taxes( array() );
				$rate->set_label(
					sprintf(
						/* translators: 1: shipping method name, 2: threshold amount, e.g. $50.00 */
						__( '%1$s – free on orders over %2$s', 'brewbean-woo-enhancements' ),
						$rate->get_label(),
						$threshold
					)
				);
			}
		}
		return $rates;
	}

	/**
	 * Include the threshold in the package so cached rates are recalculated when it changes.
	 *
	 * @param array<int, array<string, mixed>> $packages Shipping packages.
	 * @return array<int, array<string, mixed>>
	 */
	public function bust_rate_cache( array $packages ): array {
		foreach ( $packages as &$package ) {
			$package['brewbean_threshold'] = Settings::threshold();
		}
		return $packages;
	}

	/**
	 * Add progress data to the Store API cart response: `extensions.brewbean.shipping`.
	 */
	private function register_store_api_data(): void {
		$callback = static function (): void {
			if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
				return;
			}
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => CartSchema::IDENTIFIER,
					'namespace'       => 'brewbean',
					'data_callback'   => static function (): array {
						$data = self::data();
						return array(
							'shipping' => array(
								'threshold' => wc_format_decimal( $data['threshold'], wc_get_price_decimals() ),
								'remaining' => wc_format_decimal( $data['remaining'], wc_get_price_decimals() ),
								'percent'   => $data['percent'],
								'qualified' => $data['qualified'],
								'message'   => self::message( $data ),
							),
						);
					},
					'schema_callback' => static fn (): array => array(
						'shipping' => array(
							'description' => __( 'Free-shipping progress for the current cart.', 'brewbean-woo-enhancements' ),
							'type'        => 'object',
							'context'     => array( 'view', 'edit' ),
							'readonly'    => true,
						),
					),
					'schema_type'     => ARRAY_A,
				)
			);
		};

		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$callback();
		} else {
			add_action( 'woocommerce_blocks_loaded', $callback );
		}
	}
}
