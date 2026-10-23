<?php
/**
 * Clean up plugin options on uninstall. Order and product meta is kept on purpose:
 * it is part of the store's order history.
 *
 * @package BrewBean\WooEnhancements
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'brewbean_we_settings' );
delete_option( 'brewbean_we_demo_seeded' );
