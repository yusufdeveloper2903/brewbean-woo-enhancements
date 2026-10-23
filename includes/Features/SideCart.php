<?php
/**
 * AJAX slide-out side cart.
 *
 * The drawer shell is rendered server-side (templates/side-cart.php); its contents are
 * rendered by assets/js/side-cart.js from the WooCommerce Store API (/wc/store/v1/cart),
 * so quantity changes and removals never reload the page.
 *
 * The header cart button (`brewbean/cart-toggle` block) is auto-inserted after the header
 * navigation via the Block Hooks API and also replaces WooCommerce's own Mini-Cart block, so
 * there is exactly one cart drawer. It is available as the [brewbean_cart_toggle] shortcode
 * too, with a floating fallback button when no toggle was printed (e.g. classic themes).
 *
 * @package BrewBean\WooEnhancements
 */

declare( strict_types=1 );

namespace BrewBean\WooEnhancements\Features;

use BrewBean\WooEnhancements\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Side cart feature.
 */
final class SideCart {

	public const BLOCK = 'brewbean/cart-toggle';

	/**
	 * Whether a toggle button was printed on this page.
	 */
	private bool $toggle_rendered = false;

	/**
	 * Hook everything.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'register_block' ) );
		add_filter( 'hooked_block_types', array( $this, 'hook_toggle_into_header' ), 10, 4 );
		add_filter( 'render_block_woocommerce/mini-cart', array( $this, 'replace_mini_cart' ) );
		add_shortcode( 'brewbean_cart_toggle', array( $this, 'render_toggle' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_action( 'wp_footer', array( $this, 'render_drawer' ) );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'fragments' ) );
	}

	/**
	 * Register the server-rendered header toggle block.
	 */
	public function register_block(): void {
		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 3,
				'title'           => __( 'Brew & Bean cart button', 'brewbean-woo-enhancements' ),
				'category'        => 'woocommerce',
				'render_callback' => array( $this, 'render_toggle' ),
				'supports'        => array( 'html' => false ),
			)
		);
	}

	/**
	 * Auto-insert the toggle after the navigation block of the header template part (Block Hooks API).
	 *
	 * @param string[]    $hooked   Block types hooked at this position.
	 * @param string|null $position before|after|first_child|last_child.
	 * @param string|null $anchor   Anchor block type (null for the template root).
	 * @param mixed       $context  Template, template part, pattern or post.
	 * @return string[]
	 */
	public function hook_toggle_into_header( array $hooked, ?string $position, ?string $anchor, mixed $context ): array {
		if ( 'after' === $position && 'core/navigation' === $anchor && $context instanceof \WP_Block_Template && 'header' === $context->area ) {
			$hooked[] = self::BLOCK;
		}
		return $hooked;
	}

	/**
	 * WooCommerce auto-inserts its Mini-Cart block into block theme headers.
	 * Swap its output for our toggle so there is exactly one cart drawer.
	 */
	public function replace_mini_cart(): string {
		return $this->render_toggle();
	}

	/**
	 * Header cart button.
	 */
	public function render_toggle(): string {
		// One cart button per page, whichever placement renders first wins.
		if ( $this->toggle_rendered ) {
			return '';
		}
		$this->toggle_rendered = true;
		$count                 = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;

		return sprintf(
			'<button type="button" class="brewbean-cart-toggle" data-brewbean-open-cart aria-controls="brewbean-side-cart" aria-expanded="false">
				<svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22"><path d="M6 7h12l-1 13H7L6 7Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="M9 9V6a3 3 0 0 1 6 0v3" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
				<span class="screen-reader-text">%1$s</span>
				<span class="brewbean-cart-count%3$s" data-brewbean-cart-count aria-hidden="true">%2$d</span>
			</button>',
			esc_html__( 'Open cart', 'brewbean-woo-enhancements' ),
			(int) $count,
			$count > 0 ? '' : ' is-empty'
		);
	}

	/**
	 * Assets.
	 */
	public function enqueue(): void {
		if ( $this->is_checkout_form() ) {
			return;
		}
		wp_enqueue_style( 'brewbean-we-frontend' );
		wp_enqueue_script(
			'brewbean-we-side-cart',
			BREWBEAN_WE_URL . 'assets/js/side-cart.js',
			array( 'brewbean-we-core' ),
			Plugin::asset_version( 'assets/js/side-cart.js' ),
			array( 'strategy' => 'defer', 'in_footer' => true )
		);
		wp_set_script_translations( 'brewbean-we-side-cart', 'brewbean-woo-enhancements', BREWBEAN_WE_DIR . 'languages' );

		// AJAX add to cart on the product page so the side cart can slide in.
		if ( is_product() ) {
			wp_enqueue_script( 'brewbean-we-product' );
		}
	}

	/**
	 * Drawer shell + floating fallback toggle.
	 */
	public function render_drawer(): void {
		if ( $this->is_checkout_form() ) {
			return;
		}
		if ( ! $this->toggle_rendered ) {
			echo '<div class="brewbean-floating-toggle">' . $this->render_toggle() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_toggle().
		}
		Plugin::template( 'side-cart.php' );
	}

	/**
	 * The checkout form has its own order review – no drawer there.
	 */
	private function is_checkout_form(): bool {
		return is_checkout() && ! is_order_received_page() && ! is_checkout_pay_page();
	}

	/**
	 * Keep the count badge in sync when classic WooCommerce refreshes fragments.
	 *
	 * @param array<string, string> $fragments Fragments.
	 * @return array<string, string>
	 */
	public function fragments( array $fragments ): array {
		$count = WC()->cart->get_cart_contents_count();
		$fragments['span[data-brewbean-cart-count]'] = sprintf(
			'<span class="brewbean-cart-count%s" data-brewbean-cart-count aria-hidden="true">%d</span>',
			$count > 0 ? '' : ' is-empty',
			(int) $count
		);
		return $fragments;
	}
}
