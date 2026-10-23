<?php
/**
 * Demo content seeder used by the WordPress Playground blueprints.
 *
 * Creates the Brew & Bean catalog (categories, simple + variable products,
 * images bundled in demo/images), store settings, pages, shipping and menu.
 * It is idempotent: running it twice does nothing the second time.
 *
 * Not loaded during normal requests – only demo/seed.php requires it.
 *
 * @package BrewBean\WooEnhancements
 */

declare( strict_types=1 );

namespace BrewBean\WooEnhancements\Demo;

use BrewBean\WooEnhancements\Features\SubscribeSave;
use BrewBean\WooEnhancements\Settings;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;

defined( 'ABSPATH' ) || exit;

/**
 * Seeds a fresh WooCommerce install with demo data.
 */
final class Seeder {

	private const FLAG = 'brewbean_we_demo_seeded';

	/**
	 * Attachment IDs keyed by image file slug.
	 *
	 * @var array<string, int>
	 */
	private array $images = array();

	/**
	 * Category term IDs keyed by slug.
	 *
	 * @var array<string, int>
	 */
	private array $categories = array();

	/**
	 * Run every step.
	 *
	 * @return string[] Log lines.
	 */
	public function run(): array {
		$log = array();
		if ( get_option( self::FLAG ) ) {
			return array( 'Demo content already present – skipped.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$this->site_options();
		$log[] = 'Site + store options set.';
		$this->sqlite_compat();
		$this->enable_hpos();
		$log[] = 'HPOS: ' . get_option( 'woocommerce_custom_orders_table_enabled' );
		$this->theme();
		$log[] = 'Theme: ' . get_stylesheet();
		$this->pages();
		$log[] = 'Pages ready.';
		$this->shipping();
		$log[] = 'Shipping zone ready.';
		$this->categories();
		$this->products();
		$log[] = 'Products: ' . count( wc_get_products( array( 'limit' => -1, 'return' => 'ids' ) ) );
		$this->navigation();
		$log[] = 'Navigation ready.';

		update_option( Settings::OPTION, Settings::defaults() );
		update_option( self::FLAG, time() );
		delete_transient( '_wc_activation_redirect' );
		flush_rewrite_rules( false );

		return $log;
	}

	/**
	 * General + WooCommerce options for a ready-to-browse store.
	 */
	private function site_options(): void {
		$options = array(
			'blogname'                                => 'Brew & Bean',
			'blogdescription'                         => 'Specialty coffee, roasted to order',
			'timezone_string'                         => 'America/New_York',
			'date_format'                             => 'F j, Y',
			// Store basics.
			'woocommerce_currency'                    => 'USD',
			'woocommerce_currency_pos'                => 'left',
			'woocommerce_price_thousand_sep'          => ',',
			'woocommerce_price_decimal_sep'           => '.',
			'woocommerce_price_num_decimals'          => 2,
			'woocommerce_default_country'             => 'US:NY',
			'woocommerce_store_address'               => '120 Roastery Lane',
			'woocommerce_store_city'                  => 'Brooklyn',
			'woocommerce_store_postcode'              => '11201',
			'woocommerce_allowed_countries'           => 'all',
			'woocommerce_calc_taxes'                  => 'no',
			'woocommerce_enable_guest_checkout'       => 'yes',
			'woocommerce_enable_ajax_add_to_cart'     => 'yes',
			'woocommerce_cart_redirect_after_add'     => 'no',
			'woocommerce_weight_unit'                 => 'g',
			// Skip onboarding / marketing noise.
			'woocommerce_onboarding_profile'          => array( 'skipped' => true ),
			'woocommerce_task_list_hidden'            => 'yes',
			'woocommerce_task_list_complete'          => 'yes',
			'woocommerce_extended_task_list_hidden'   => 'yes',
			'woocommerce_show_marketplace_suggestions' => 'no',
			'woocommerce_allow_tracking'              => 'no',
			'woocommerce_coming_soon'                 => 'no',
			'woocommerce_store_pages_only'            => 'no',
			'woocommerce_admin_install_timestamp'     => time() - DAY_IN_SECONDS,
			// Offline payment methods for the demo checkout.
			'woocommerce_cheque_settings'             => array(
				'enabled'      => 'yes',
				'title'        => 'Check payments',
				'description'  => 'Demo store: no payment is taken.',
				'instructions' => '',
			),
			'woocommerce_cod_settings'                => array(
				'enabled'            => 'yes',
				'title'              => 'Cash on delivery',
				'description'        => 'Pay when your coffee arrives. (Demo store – nothing is charged.)',
				'instructions'       => '',
				'enable_for_methods' => array(),
				'enable_for_virtual' => 'yes',
			),
		);
		foreach ( $options as $name => $value ) {
			update_option( $name, $value );
		}

		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
	}

	/**
	 * WordPress Playground runs on SQLite, which cannot execute WooCommerce's
	 * stock-reservation query (`FOR UPDATE` / `INTERVAL`). On SQLite only, drop in a
	 * tiny must-use plugin that turns checkout stock holding off. MySQL sites are untouched.
	 */
	private function sqlite_compat(): void {
		$is_sqlite = ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) || class_exists( 'WP_SQLite_DB', false );
		if ( ! $is_sqlite ) {
			return;
		}
		wp_mkdir_p( WPMU_PLUGIN_DIR );
		$file = WPMU_PLUGIN_DIR . '/brewbean-sqlite-compat.php';
		if ( ! file_exists( $file ) ) {
			file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- demo setup only.
				$file,
				"<?php\n/**\n * Plugin Name: Brew & Bean – SQLite compatibility (demo)\n * Description: WordPress Playground uses SQLite, which cannot run WooCommerce's stock reservation query. Created by the Brew & Bean demo seeder.\n */\n\nadd_filter( 'woocommerce_hold_stock_for_checkout', '__return_false' );\n"
			);
		}
	}

	/**
	 * Make sure High-Performance Order Storage is active so the demo exercises it.
	 */
	private function enable_hpos(): void {
		try {
			$sync = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class );
			if ( ! $sync->check_orders_table_exists() ) {
				$sync->create_database_tables();
			}
			update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
			update_option( 'woocommerce_custom_orders_table_data_sync_enabled', 'no' );
		} catch ( \Throwable $e ) {
			// Older WooCommerce: stay on the posts storage, the plugin works with both.
			unset( $e );
		}
	}

	/**
	 * Install (copy) and activate the companion child theme.
	 */
	private function theme(): void {
		$source = BREWBEAN_WE_DIR . 'theme/brewbean-coffee';
		$target = get_theme_root() . '/brewbean-coffee';
		if ( ! is_dir( $target ) && is_dir( $source ) ) {
			WP_Filesystem();
			wp_mkdir_p( $target );
			copy_dir( $source, $target );
		}
		if ( wp_get_theme( 'brewbean-coffee' )->exists() && wp_get_theme( 'twentytwentyfive' )->exists() ) {
			switch_theme( 'brewbean-coffee' );
			// Run the "theme switched" routines now (WooCommerce block hooks, theme.json caches)
			// instead of during the first visitor's page load.
			check_theme_switched();
			wp_clean_theme_json_cache();
			// Let WooCommerce hook its header blocks (account icon) deterministically.
			update_option( 'woocommerce_hooked_blocks_version', WC()->version );
		}
	}

	/**
	 * WooCommerce pages (classic cart/checkout shortcodes) and a static front page.
	 */
	private function pages(): void {
		if ( class_exists( \WC_Install::class ) ) {
			\WC_Install::create_pages();
		}

		$shortcodes = array(
			'woocommerce_cart_page_id'     => '[woocommerce_cart]',
			'woocommerce_checkout_page_id' => '[woocommerce_checkout]',
		);
		foreach ( $shortcodes as $option => $shortcode ) {
			$page_id = (int) get_option( $option );
			if ( $page_id ) {
				wp_update_post(
					array(
						'ID'           => $page_id,
						'post_content' => '<!-- wp:shortcode -->' . $shortcode . '<!-- /wp:shortcode -->',
					)
				);
			}
		}

		$sample = get_page_by_path( 'sample-page' );
		if ( $sample ) {
			wp_delete_post( $sample->ID, true );
		}

		$about_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'Our Story',
				'post_name'    => 'our-story',
				'post_content' => "<!-- wp:paragraph -->\n<p>Brew &amp; Bean is a fictional specialty coffee roaster created to demonstrate custom WooCommerce development. Every product, image and price on this site is sample data – no real orders are processed.</p>\n<!-- /wp:paragraph -->",
			)
		);
		unset( $about_id );

		$shop_id = wc_get_page_id( 'shop' );
		if ( $shop_id > 0 ) {
			wp_update_post( array( 'ID' => $shop_id, 'post_title' => 'Shop' ) );
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $shop_id );
		}
	}

	/**
	 * One flat rate everywhere; the plugin makes it free above the threshold.
	 */
	private function shipping(): void {
		$zone = new \WC_Shipping_Zone( 0 );
		foreach ( $zone->get_shipping_methods() as $method ) {
			if ( 'flat_rate' === $method->id ) {
				return;
			}
		}
		$instance_id = $zone->add_shipping_method( 'flat_rate' );
		update_option(
			'woocommerce_flat_rate_' . $instance_id . '_settings',
			array(
				'title'      => 'Standard shipping',
				'tax_status' => 'none',
				'cost'       => '6.00',
			)
		);
		\WC_Cache_Helper::get_transient_version( 'shipping', true );
	}

	/**
	 * Product categories.
	 */
	private function categories(): void {
		$cats = array(
			'single-origin' => array( 'Single Origin', 'Traceable lots from a single farm or washing station.' ),
			'blends'        => array( 'Blends & Espresso', 'Balanced, dependable blends built for espresso and milk drinks.' ),
			'brewing-gear'  => array( 'Brewing Gear', 'Kettles, drippers, grinders and scales for better coffee at home.' ),
			'accessories'   => array( 'Accessories', 'Filters and the small things that make brewing easier.' ),
		);
		foreach ( $cats as $slug => [ $name, $description ] ) {
			$term = term_exists( $slug, 'product_cat' );
			if ( ! $term ) {
				$term = wp_insert_term( $name, 'product_cat', array( 'slug' => $slug, 'description' => $description ) );
			}
			$this->categories[ $slug ] = is_array( $term ) ? (int) $term['term_id'] : (int) $term;
		}
	}

	/**
	 * Import a bundled image once and return its attachment ID.
	 *
	 * @param string $slug File name without extension (demo/images/{slug}.jpg).
	 * @param string $title Attachment title / alt text.
	 */
	private function image( string $slug, string $title ): int {
		if ( isset( $this->images[ $slug ] ) ) {
			return $this->images[ $slug ];
		}
		$source = BREWBEAN_WE_DIR . 'demo/images/' . $slug . '.jpg';
		if ( ! is_readable( $source ) ) {
			return 0;
		}
		$tmp = wp_tempnam( $slug . '.jpg' );
		copy( $source, $tmp );
		$id = media_handle_sideload( array( 'name' => $slug . '.jpg', 'tmp_name' => $tmp ), 0, $title );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return 0;
		}
		update_post_meta( $id, '_wp_attachment_image_alt', $title );
		$this->images[ $slug ] = (int) $id;
		return (int) $id;
	}

	/**
	 * Create the catalog.
	 */
	private function products(): void {
		$coffee_desc = static fn ( string $intro, string $notes, string $process, string $altitude ): string => sprintf(
			"<p>%s</p>\n<ul><li><strong>Tasting notes:</strong> %s</li><li><strong>Process:</strong> %s</li><li><strong>Altitude:</strong> %s</li><li><strong>Roasted:</strong> to order, shipped within 48 hours</li></ul>",
			$intro,
			$notes,
			$process,
			$altitude
		);

		$simple = array(
			array(
				'name'      => 'Ethiopia Yirgacheffe Kochere',
				'slug'      => 'ethiopia-yirgacheffe',
				'cat'       => 'single-origin',
				'price'     => '19.00',
				'sku'       => 'BB-ETH-250',
				'short'     => 'Floral and bright with jasmine, bergamot and a honey-sweet finish. A classic washed Ethiopian for pour-over.',
				'desc'      => $coffee_desc( 'Grown by smallholders around the Kochere washing station and fully washed for a clean, tea-like cup.', 'Jasmine, bergamot, honey', 'Washed', '1,900 – 2,100 m' ),
				'featured'  => true,
				'subscribe' => 10,
				'weight'    => '250',
			),
			array(
				'name'      => 'Colombia Huila Pink Bourbon',
				'slug'      => 'colombia-huila',
				'cat'       => 'single-origin',
				'price'     => '21.00',
				'sale'      => '18.50',
				'sku'       => 'BB-COL-250',
				'short'     => 'A rare Pink Bourbon lot with notes of red berries, cacao nib and panela. Limited release.',
				'desc'      => $coffee_desc( 'A small natural-process lot of the Pink Bourbon variety from the hills of Huila.', 'Raspberry, cacao nib, panela', 'Natural', '1,750 m' ),
				'featured'  => true,
				'subscribe' => 10,
				'weight'    => '250',
			),
			array(
				'name'      => 'Kenya Nyeri AA',
				'slug'      => 'kenya-nyeri',
				'cat'       => 'single-origin',
				'price'     => '22.00',
				'sku'       => 'BB-KEN-250',
				'short'     => 'Juicy and complex: blackcurrant, grapefruit and a syrupy body. Our favourite filter coffee this season.',
				'desc'      => $coffee_desc( 'AA-grade SL28 and SL34 from cooperatives on the slopes of Mount Kenya.', 'Blackcurrant, grapefruit, brown sugar', 'Washed', '1,800 m' ),
				'subscribe' => 10,
				'weight'    => '250',
			),
			array(
				'name'      => 'Guatemala Antigua',
				'slug'      => 'guatemala-antigua',
				'cat'       => 'single-origin',
				'price'     => '17.00',
				'sku'       => 'BB-GUA-250',
				'short'     => 'Chocolate, toasted almond and a hint of orange. Smooth and forgiving for every brew method.',
				'desc'      => $coffee_desc( 'Grown in volcanic soil in the Antigua valley and sun-dried on patios.', 'Milk chocolate, almond, orange', 'Washed', '1,500 – 1,700 m' ),
				'subscribe' => 10,
				'weight'    => '250',
			),
			array(
				'name'      => 'Swiss Water Decaf',
				'slug'      => 'decaf-colombia',
				'cat'       => 'single-origin',
				'price'     => '16.00',
				'sku'       => 'BB-DEC-250',
				'short'     => 'All the caramel and cocoa, 99.9% caffeine free. Decaffeinated with water only – no chemicals.',
				'desc'      => $coffee_desc( 'A Colombian lot decaffeinated with the Swiss Water® process.', 'Caramel, cocoa, red apple', 'Washed, Swiss Water decaf', '1,600 m' ),
				'subscribe' => 10,
				'weight'    => '250',
			),
			array(
				'name'      => 'Midnight Espresso Blend',
				'slug'      => 'midnight-espresso',
				'cat'       => 'blends',
				'price'     => '16.00',
				'sku'       => 'BB-MID-250',
				'short'     => 'Our darkest roast: dark chocolate, molasses and a thick crema. Built to cut through milk.',
				'desc'      => $coffee_desc( 'Brazil and Sumatra, roasted a touch darker for a bold, low-acid espresso.', 'Dark chocolate, molasses, smoke', 'Natural + wet-hulled', '1,100 – 1,500 m' ),
				'subscribe' => 15,
				'weight'    => '250',
			),
			array(
				'name'     => 'Gooseneck Pour-Over Kettle',
				'slug'     => 'gooseneck-kettle',
				'cat'      => 'brewing-gear',
				'price'    => '64.00',
				'sku'      => 'BB-GEAR-KET',
				'short'    => 'A 1 L stainless steel kettle with a precision spout for slow, even pours. Works on gas, electric and induction.',
				'desc'     => '<p>Control is everything in pour-over. The narrow gooseneck spout gives you a steady, pencil-thin stream, and the built-in thermometer shows when you hit 93 °C.</p><ul><li>1 litre capacity</li><li>Brushed stainless steel, walnut-tone lid knob</li><li>Induction compatible</li></ul>',
				'featured' => true,
				'weight'   => '900',
			),
			array(
				'name'   => 'Ceramic Cone Dripper',
				'slug'   => 'ceramic-dripper',
				'cat'    => 'brewing-gear',
				'price'  => '24.00',
				'sku'    => 'BB-GEAR-DRP',
				'short'  => 'A glazed ceramic cone with spiral ribs for an even extraction. Brews 1–2 cups.',
				'desc'   => '<p>Heavy ceramic keeps the brew temperature stable while the spiral ribs let air escape for a clean cup. Fits standard #02 cone filters.</p>',
				'weight' => '420',
			),
			array(
				'name'     => 'Hand Burr Grinder',
				'slug'     => 'hand-grinder',
				'cat'      => 'brewing-gear',
				'price'    => '89.00',
				'sku'      => 'BB-GEAR-GRD',
				'short'    => 'Stainless conical burrs with 40 click settings – from espresso fine to French press coarse.',
				'desc'     => '<p>A consistent grind is the single biggest upgrade for home coffee. Dual bearings keep the burrs centred so every particle is the same size.</p><ul><li>38 mm stainless conical burrs</li><li>40 click grind settings</li><li>30 g capacity</li></ul>',
				'featured' => true,
				'weight'   => '600',
			),
			array(
				'name'   => 'Digital Brew Scale',
				'slug'   => 'brew-scale',
				'cat'    => 'brewing-gear',
				'price'  => '39.00',
				'sku'    => 'BB-GEAR-SCL',
				'short'  => 'Weigh coffee and water to 0.1 g with a built-in timer. USB-C rechargeable.',
				'desc'   => '<p>Repeatable recipes start with a scale. Auto-tare, a flow-rate timer and a water-resistant top make it a barista favourite.</p>',
				'weight' => '350',
			),
			array(
				'name'   => 'Paper Filters (100 pack)',
				'slug'   => 'paper-filters',
				'cat'    => 'accessories',
				'price'  => '8.00',
				'sku'    => 'BB-ACC-FLT',
				'short'  => 'Oxygen-bleached #02 cone filters. Rinse once for a perfectly clean cup.',
				'desc'   => '<p>One hundred unbleached-taste, oxygen-whitened paper filters that fit the Ceramic Cone Dripper and most #02 cones.</p>',
				'weight' => '120',
			),
		);

		$menu_order = 1;
		$this->variable_product( $menu_order++ );

		foreach ( $simple as $data ) {
			if ( wc_get_product_id_by_sku( $data['sku'] ) ) {
				continue;
			}
			$product = new WC_Product_Simple();
			$product->set_name( $data['name'] );
			$product->set_slug( $data['slug'] );
			$product->set_status( 'publish' );
			$product->set_sku( $data['sku'] );
			$product->set_regular_price( $data['price'] );
			if ( ! empty( $data['sale'] ) ) {
				$product->set_sale_price( $data['sale'] );
			}
			$product->set_short_description( $data['short'] );
			$product->set_description( $data['desc'] );
			$product->set_category_ids( array( $this->categories[ $data['cat'] ] ) );
			$product->set_featured( ! empty( $data['featured'] ) );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( 40 );
			$product->set_weight( $data['weight'] );
			$product->set_menu_order( $menu_order++ );
			$product->set_image_id( $this->image( $data['slug'], $data['name'] ) );
			if ( ! empty( $data['subscribe'] ) ) {
				$product->update_meta_data( SubscribeSave::META_ENABLED, 'yes' );
				$product->update_meta_data( SubscribeSave::META_DISCOUNT, (string) $data['subscribe'] );
			}
			$product->save();
		}
	}

	/**
	 * House Blend: variable product with Size × Grind variations.
	 *
	 * @param int $menu_order Sort order.
	 */
	private function variable_product( int $menu_order ): void {
		if ( wc_get_product_id_by_sku( 'BB-HOUSE' ) ) {
			return;
		}

		$sizes  = array(
			'250 g' => '14.00',
			'500 g' => '26.00',
			'1 kg'  => '48.00',
		);
		$grinds = array( 'Whole bean', 'Espresso', 'Filter', 'French press' );

		$size_attr = new WC_Product_Attribute();
		$size_attr->set_name( 'Size' );
		$size_attr->set_options( array_keys( $sizes ) );
		$size_attr->set_visible( true );
		$size_attr->set_variation( true );
		$size_attr->set_position( 0 );

		$grind_attr = new WC_Product_Attribute();
		$grind_attr->set_name( 'Grind' );
		$grind_attr->set_options( $grinds );
		$grind_attr->set_visible( true );
		$grind_attr->set_variation( true );
		$grind_attr->set_position( 1 );

		$product = new WC_Product_Variable();
		$product->set_name( 'Brew & Bean House Blend' );
		$product->set_slug( 'house-blend' );
		$product->set_status( 'publish' );
		$product->set_sku( 'BB-HOUSE' );
		$product->set_featured( true );
		$product->set_menu_order( $menu_order );
		$product->set_short_description( 'Our signature blend of Brazil, Colombia and Ethiopia. Milk chocolate, hazelnut and ripe cherry – sweet as espresso, smooth as filter. Choose your size and grind.' );
		$product->set_description(
			'<p>The coffee we drink every day. A seasonal blend balanced for espresso but versatile enough for any brewer.</p><ul><li><strong>Tasting notes:</strong> Milk chocolate, hazelnut, ripe cherry</li><li><strong>Roast:</strong> Medium</li><li><strong>Best for:</strong> Espresso, moka pot, AeroPress, filter</li></ul>'
		);
		$product->set_category_ids( array( $this->categories['blends'] ) );
		$product->set_attributes( array( $size_attr, $grind_attr ) );
		$product->set_default_attributes( array( 'size' => '250 g', 'grind' => 'Whole bean' ) );
		$product->set_image_id( $this->image( 'house-blend', 'Brew & Bean House Blend' ) );
		$product->set_gallery_image_ids(
			array_filter(
				array(
					$this->image( 'gallery-beans', 'Freshly roasted coffee beans' ),
					$this->image( 'gallery-cup', 'A cup of House Blend' ),
				)
			)
		);
		$product->update_meta_data( SubscribeSave::META_ENABLED, 'yes' );
		$product->update_meta_data( SubscribeSave::META_DISCOUNT, '15' );
		$parent_id = $product->save();

		$i = 0;
		foreach ( $sizes as $size => $price ) {
			foreach ( $grinds as $grind ) {
				$variation = new WC_Product_Variation();
				$variation->set_parent_id( $parent_id );
				$variation->set_attributes( array( 'size' => $size, 'grind' => $grind ) );
				$variation->set_regular_price( $price );
				$variation->set_sku( sprintf( 'BB-HOUSE-%d', ++$i ) );
				$variation->set_manage_stock( true );
				$variation->set_stock_quantity( 25 );
				$variation->set_status( 'publish' );
				$variation->save();
			}
		}
		\WC_Product_Variable::sync( $parent_id );
	}

	/**
	 * Header navigation (core/navigation falls back to the latest wp_navigation post).
	 */
	private function navigation(): void {
		$links = array(
			array( 'Shop', get_permalink( wc_get_page_id( 'shop' ) ) ),
			array( 'Single Origin', get_term_link( $this->categories['single-origin'], 'product_cat' ) ),
			array( 'Blends', get_term_link( $this->categories['blends'], 'product_cat' ) ),
			array( 'Brewing Gear', get_term_link( $this->categories['brewing-gear'], 'product_cat' ) ),
			array( 'Our Story', get_permalink( get_page_by_path( 'our-story' ) ) ),
		);
		$content = '';
		foreach ( $links as [ $label, $url ] ) {
			if ( is_string( $url ) ) {
				$content .= sprintf( '<!-- wp:navigation-link {"label":"%s","url":"%s","kind":"custom"} /-->', esc_attr( $label ), esc_url_raw( $url ) );
			}
		}
		wp_insert_post(
			array(
				'post_type'    => 'wp_navigation',
				'post_status'  => 'publish',
				'post_title'   => 'Main menu',
				'post_content' => $content,
			)
		);
	}
}
