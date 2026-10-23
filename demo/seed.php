<?php
/**
 * Demo seeder entry point, executed by the Playground blueprints:
 *
 *   require '/wordpress/wp-load.php';
 *   require WP_PLUGIN_DIR . '/brewbean-woo-enhancements/demo/seed.php';
 *
 * @package BrewBean\WooEnhancements
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \BrewBean\WooEnhancements\Demo\Seeder::class ) || ! function_exists( 'WC' ) ) {
	echo "Brew & Bean seeder: plugin or WooCommerce not active.\n";
	return;
}

// Act as the first administrator so content is not run through KSES.
wp_set_current_user( 1 );

foreach ( ( new \BrewBean\WooEnhancements\Demo\Seeder() )->run() as $line ) {
	echo esc_html( $line ) . "\n";
}
