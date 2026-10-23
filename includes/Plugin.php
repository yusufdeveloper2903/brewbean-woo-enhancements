<?php
/**
 * Plugin bootstrap: wires settings, shared assets and the feature classes.
 *
 * @package BrewBean\WooEnhancements
 */

declare( strict_types=1 );

namespace BrewBean\WooEnhancements;

use BrewBean\WooEnhancements\Features\AddToCart;
use BrewBean\WooEnhancements\Features\DeliveryDate;
use BrewBean\WooEnhancements\Features\QuickView;
use BrewBean\WooEnhancements\Features\ShippingProgress;
use BrewBean\WooEnhancements\Features\SideCart;
use BrewBean\WooEnhancements\Features\SubscribeSave;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

	/**
	 * Get the shared instance.
	 */
	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	/**
	 * Register hooks for every enabled feature. Runs on plugins_loaded.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ), 5 );

		( new Settings() )->register();

		// Shared AJAX add-to-cart endpoint used by the product page and quick view.
		( new AddToCart() )->register();

		$features = array(
			'side_cart_enabled'     => SideCart::class,
			'progress_enabled'      => ShippingProgress::class,
			'quick_view_enabled'    => QuickView::class,
			'delivery_date_enabled' => DeliveryDate::class,
			'subscribe_enabled'     => SubscribeSave::class,
		);

		foreach ( $features as $setting => $class ) {
			if ( Settings::enabled( $setting ) ) {
				( new $class() )->register();
			}
		}

		// The threshold logic (free rates, Store API data) is useful even when the bar is hidden.
		if ( ! Settings::enabled( 'progress_enabled' ) && Settings::enabled( 'apply_free_shipping' ) ) {
			( new ShippingProgress() )->register_shipping_rules();
		}

		// Subscription admin tab stays available so data is not lost while the feature is toggled off.
		if ( ! Settings::enabled( 'subscribe_enabled' ) && is_admin() ) {
			( new SubscribeSave() )->register_admin();
		}
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'brewbean-woo-enhancements', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Register (not enqueue) shared frontend assets. Features enqueue what they need.
	 */
	public function register_assets(): void {
		wp_register_style( 'brewbean-we-frontend', BREWBEAN_WE_URL . 'assets/css/frontend.css', array(), self::asset_version( 'assets/css/frontend.css' ) );

		wp_register_script(
			'brewbean-we-core',
			BREWBEAN_WE_URL . 'assets/js/core.js',
			array( 'wp-i18n' ),
			self::asset_version( 'assets/js/core.js' ),
			array( 'strategy' => 'defer', 'in_footer' => true )
		);
		wp_set_script_translations( 'brewbean-we-core', 'brewbean-woo-enhancements', BREWBEAN_WE_DIR . 'languages' );

		wp_register_script(
			'brewbean-we-product',
			BREWBEAN_WE_URL . 'assets/js/product.js',
			array( 'brewbean-we-core' ),
			self::asset_version( 'assets/js/product.js' ),
			array( 'strategy' => 'defer', 'in_footer' => true )
		);

		wp_add_inline_script(
			'brewbean-we-core',
			'window.brewbeanConfig = ' . wp_json_encode( $this->script_config() ) . ';',
			'before'
		);
	}

	/**
	 * Data shared with every Brew & Bean script.
	 *
	 * @return array<string, mixed>
	 */
	private function script_config(): array {
		return array(
			'storeApi'      => esc_url_raw( rest_url( 'wc/store/v1/' ) ),
			'storeNonce'    => wp_create_nonce( 'wc_store_api' ),
			'restNonce'     => wp_create_nonce( 'wp_rest' ),
			'wcAjax'        => \WC_AJAX::get_endpoint( '%%endpoint%%' ),
			'cartUrl'       => wc_get_cart_url(),
			'checkoutUrl'   => wc_get_checkout_url(),
			'shopUrl'       => wc_get_page_permalink( 'shop' ),
			'isCart'        => is_cart(),
			'isCheckout'    => is_checkout(),
			'autoOpen'      => Settings::enabled( 'side_cart_auto_open' ),
			'sideCart'      => Settings::enabled( 'side_cart_enabled' ),
			'progress'      => Settings::enabled( 'progress_enabled' ),
			'taxInclusive'  => 'incl' === get_option( 'woocommerce_tax_display_cart' ),
			'placeholder'   => wc_placeholder_img_src( 'woocommerce_thumbnail' ),
		);
	}

	/**
	 * Cache-busting version: file mtime in development, plugin version otherwise.
	 *
	 * @param string $relative Path relative to the plugin root.
	 */
	public static function asset_version( string $relative ): string {
		$file = BREWBEAN_WE_DIR . $relative;
		return ( defined( 'WP_DEBUG' ) && WP_DEBUG && is_readable( $file ) ) ? (string) filemtime( $file ) : VERSION;
	}

	/**
	 * Locate and render a template that themes can override in yourtheme/brewbean/.
	 *
	 * @param string               $name Template file name.
	 * @param array<string, mixed> $args Variables passed to the template.
	 */
	public static function template( string $name, array $args = array() ): void {
		wc_get_template( $name, $args, 'brewbean/', BREWBEAN_WE_DIR . 'templates/' );
	}

	/**
	 * Same as template() but returns the markup.
	 *
	 * @param string               $name Template file name.
	 * @param array<string, mixed> $args Variables passed to the template.
	 */
	public static function template_html( string $name, array $args = array() ): string {
		return wc_get_template_html( $name, $args, 'brewbean/', BREWBEAN_WE_DIR . 'templates/' );
	}
}
