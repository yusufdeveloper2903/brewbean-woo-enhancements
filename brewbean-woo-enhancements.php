<?php
/**
 * Plugin Name:       Brew & Bean – WooCommerce Enhancements
 * Plugin URI:        https://github.com/yusufdeveloper2903/brewbean-woo-enhancements
 * Description:       Store enhancements for the Brew & Bean coffee shop: AJAX side cart, free-shipping progress bar, product quick view, preferred delivery date at checkout and per-product subscribe & save. Demo / portfolio project.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.6
 * WC tested up to:   11.2
 * Author:            Yusuf Yuldashev
 * Author URI:        https://github.com/yusufdeveloper2903
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       brewbean-woo-enhancements
 * Domain Path:       /languages
 *
 * @package BrewBean\WooEnhancements
 */

declare( strict_types=1 );

namespace BrewBean\WooEnhancements;

defined( 'ABSPATH' ) || exit;

const VERSION     = '1.0.0';
const PLUGIN_FILE = __FILE__;

define( 'BREWBEAN_WE_DIR', plugin_dir_path( __FILE__ ) );
define( 'BREWBEAN_WE_URL', plugin_dir_url( __FILE__ ) );

/*
 * PSR-4 style autoloader for the BrewBean\WooEnhancements namespace.
 * BrewBean\WooEnhancements\Features\SideCart => includes/Features/SideCart.php
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) );
		$file     = BREWBEAN_WE_DIR . 'includes/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/*
 * Declare compatibility with WooCommerce High-Performance Order Storage (HPOS).
 * All order data is read and written through the WC_Order CRUD API.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'Brew & Bean – WooCommerce Enhancements requires WooCommerce to be installed and active.', 'brewbean-woo-enhancements' )
					);
				}
			);
			return;
		}
		Plugin::instance()->boot();
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}
	}
);
