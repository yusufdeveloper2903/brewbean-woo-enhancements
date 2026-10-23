<?php
/**
 * Brew & Bean Coffee – Twenty Twenty-Five child theme.
 *
 * Block child themes inherit the parent's styles automatically; only this theme's
 * small stylesheet needs to be enqueued. Design tokens live in theme.json.
 *
 * @package BrewBeanCoffee
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		wp_enqueue_style(
			'brewbean-coffee',
			get_stylesheet_uri(),
			array(),
			(string) wp_get_theme()->get( 'Version' )
		);
	}
);
