<?php
/**
 * Subscribe & save: a per-product recurring-delivery discount.
 *
 * - Admin: "Subscribe & save" tab in the Product data meta box (simple + variable products).
 * - Product page / quick view: "One-time purchase" vs "Subscribe & save X%" with a delivery interval.
 * - Cart: the choice is stored as cart item data and the discounted price is applied in
 *   woocommerce_before_calculate_totals, so classic cart, Store API and checkout all agree.
 * - Order: the interval is saved as order item meta.
 *
 * Note: this demo records the subscription choice on the order; it does not create
 * renewal orders (that is what WooCommerce Subscriptions would be used for in production).
 *
 * @package BrewBean\WooEnhancements
 */

declare( strict_types=1 );

namespace BrewBean\WooEnhancements\Features;

use BrewBean\WooEnhancements\Plugin;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Subscribe & save feature.
 */
final class SubscribeSave {

	public const META_ENABLED  = '_brewbean_subscribe_enabled';
	public const META_DISCOUNT = '_brewbean_subscribe_discount';
	public const CART_KEY      = 'brewbean_subscription';
	public const ORDER_META    = '_brewbean_subscription_interval';

	/**
	 * Delivery intervals offered to customers.
	 *
	 * @return array<string, string>
	 */
	public static function intervals(): array {
		return (array) apply_filters(
			'brewbean_we_subscription_intervals',
			array(
				'1-week'  => __( 'Every week', 'brewbean-woo-enhancements' ),
				'2-weeks' => __( 'Every 2 weeks', 'brewbean-woo-enhancements' ),
				'4-weeks' => __( 'Every 4 weeks', 'brewbean-woo-enhancements' ),
			)
		);
	}

	/**
	 * Hook everything.
	 */
	public function register(): void {
		$this->register_admin();

		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_product_options' ) );
		add_action( 'brewbean_we_quick_view_before_add_to_cart', array( $this, 'render_product_options' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_filter( 'woocommerce_available_variation', array( $this, 'variation_data' ), 10, 3 );

		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_discount' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'cart_item_price' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_item_meta' ), 10, 3 );
		add_filter( 'woocommerce_order_item_display_meta_key', array( $this, 'order_meta_label' ), 10, 2 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_internal_meta' ) );
	}

	/**
	 * Admin-only hooks (product data tab).
	 */
	public function register_admin(): void {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_product_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product' ) );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Discount percentage for a product (0 when the option is off). Variations inherit from the parent.
	 *
	 * @param WC_Product|null $product Product or variation.
	 */
	public static function discount_for( ?WC_Product $product ): float {
		if ( ! $product ) {
			return 0.0;
		}
		if ( $product->is_type( 'variation' ) ) {
			$product = wc_get_product( $product->get_parent_id() );
			if ( ! $product ) {
				return 0.0;
			}
		}
		if ( 'yes' !== $product->get_meta( self::META_ENABLED ) ) {
			return 0.0;
		}
		return min( 90.0, max( 0.0, (float) $product->get_meta( self::META_DISCOUNT ) ) );
	}

	/**
	 * Apply a percentage discount to a price, rounded to store decimals.
	 *
	 * @param float $price    Base price.
	 * @param float $discount Percentage.
	 */
	public static function discounted( float $price, float $discount ): float {
		return round( $price * ( 1 - $discount / 100 ), wc_get_price_decimals() );
	}

	/* ---------------------------------------------------------------------
	 * Admin: product data tab
	 * ------------------------------------------------------------------- */

	/**
	 * Register the tab.
	 *
	 * @param array<string, array<string, mixed>> $tabs Tabs.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_product_tab( array $tabs ): array {
		$tabs['brewbean_subscribe'] = array(
			'label'    => __( 'Subscribe & save', 'brewbean-woo-enhancements' ),
			'target'   => 'brewbean_subscribe_data',
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
			'priority' => 65,
		);
		return $tabs;
	}

	/**
	 * Tab panel.
	 */
	public function render_product_panel(): void {
		global $product_object;
		$product = $product_object instanceof WC_Product ? $product_object : null;
		?>
		<div id="brewbean_subscribe_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<p class="form-field brewbean-admin-intro">
					<?php esc_html_e( 'Let customers choose a recurring delivery and reward them with a discount on this product.', 'brewbean-woo-enhancements' ); ?>
				</p>
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => self::META_ENABLED,
						'label'       => __( 'Enable subscribe & save', 'brewbean-woo-enhancements' ),
						'description' => __( 'Show the subscription option on the product page and in quick view.', 'brewbean-woo-enhancements' ),
						'value'       => $product ? $product->get_meta( self::META_ENABLED ) : 'no',
					)
				);
				woocommerce_wp_text_input(
					array(
						'id'                => self::META_DISCOUNT,
						'label'             => __( 'Subscription discount (%)', 'brewbean-woo-enhancements' ),
						'desc_tip'          => true,
						'description'       => __( 'Percentage taken off the regular/sale price (and every variation) when a customer subscribes.', 'brewbean-woo-enhancements' ),
						'type'              => 'number',
						'value'             => $product ? $product->get_meta( self::META_DISCOUNT ) : '',
						'placeholder'       => '10',
						'custom_attributes' => array(
							'min'  => '0',
							'max'  => '90',
							'step' => '0.5',
						),
					)
				);
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Save the tab. WooCommerce verifies the meta box nonce and capability before this action runs.
	 *
	 * @param WC_Product $product Product being saved.
	 */
	public function save_product( WC_Product $product ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by WC_Admin_Meta_Boxes::save_meta_boxes().
		$enabled  = isset( $_POST[ self::META_ENABLED ] ) ? 'yes' : 'no';
		$discount = isset( $_POST[ self::META_DISCOUNT ] ) ? wc_format_decimal( sanitize_text_field( wp_unslash( $_POST[ self::META_DISCOUNT ] ) ) ) : '';
		// phpcs:enable

		$product->update_meta_data( self::META_ENABLED, $enabled );
		$product->update_meta_data( self::META_DISCOUNT, '' === $discount ? '' : (string) min( 90, max( 0, (float) $discount ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Frontend
	 * ------------------------------------------------------------------- */

	/**
	 * Purchase options inside the add-to-cart form (product page + quick view).
	 *
	 * @param WC_Product|null $product Product (quick view passes it; the product page uses the global).
	 */
	public function render_product_options( $product = null ): void {
		if ( ! $product instanceof WC_Product ) {
			$product = $GLOBALS['product'] ?? null;
		}
		if ( ! $product instanceof WC_Product || ! $product->is_type( array( 'simple', 'variable' ) ) ) {
			return;
		}
		$discount = self::discount_for( $product );
		if ( $discount <= 0 ) {
			return;
		}

		$once_price = '';
		$sub_price  = '';
		if ( $product->is_type( 'simple' ) ) {
			$base       = (float) wc_get_price_to_display( $product );
			$once_price = wc_price( $base );
			$sub_price  = wc_price( self::discounted( $base, $discount ) );
		}

		Plugin::template(
			'purchase-options.php',
			array(
				'product'    => $product,
				'discount'   => $discount,
				'once_price' => $once_price,
				'sub_price'  => $sub_price,
				'intervals'  => self::intervals(),
				'uid'        => wp_unique_id( 'brewbean-po-' ),
			)
		);
	}

	/**
	 * Script + styles on product pages.
	 */
	public function enqueue(): void {
		if ( is_product() ) {
			wp_enqueue_style( 'brewbean-we-frontend' );
			wp_enqueue_script( 'brewbean-we-product' );
		}
	}

	/**
	 * Expose the subscription price on each variation (used by quick view + product page JS).
	 *
	 * @param array<string, mixed>  $data      Variation data.
	 * @param WC_Product            $parent    Parent product.
	 * @param \WC_Product_Variation $variation Variation.
	 * @return array<string, mixed>
	 */
	public function variation_data( array $data, $parent, $variation ): array {
		$discount = self::discount_for( $parent );
		if ( $discount > 0 ) {
			$data['brewbean_subscribe_price_html'] = wc_price( self::discounted( (float) $data['display_price'], $discount ) );
		}
		unset( $variation );
		return $data;
	}

	/**
	 * Store the subscription choice on the cart item.
	 *
	 * @param array<string, mixed> $cart_item_data Cart item data.
	 * @param int                  $product_id     Product ID.
	 * @param int                  $variation_id   Variation ID.
	 * @return array<string, mixed>
	 */
	public function add_cart_item_data( array $cart_item_data, $product_id, $variation_id ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce add-to-cart forms carry no nonce.
		$mode     = isset( $_POST['brewbean_purchase'] ) ? sanitize_key( wp_unslash( $_POST['brewbean_purchase'] ) ) : 'once';
		$interval = isset( $_POST['brewbean_interval'] ) ? sanitize_key( wp_unslash( $_POST['brewbean_interval'] ) ) : '';
		// phpcs:enable

		if ( 'subscribe' !== $mode || self::discount_for( wc_get_product( (int) $product_id ) ) <= 0 ) {
			return $cart_item_data;
		}
		if ( ! array_key_exists( $interval, self::intervals() ) ) {
			$interval = (string) array_key_first( self::intervals() );
		}

		$cart_item_data[ self::CART_KEY ] = array( 'interval' => $interval );
		unset( $variation_id );
		return $cart_item_data;
	}

	/**
	 * Apply the discount. Idempotent: always calculated from a freshly loaded product price.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public function apply_discount( \WC_Cart $cart ): void {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item[ self::CART_KEY ] ) ) {
				continue;
			}
			$source   = wc_get_product( ! empty( $item['variation_id'] ) ? (int) $item['variation_id'] : (int) $item['product_id'] );
			$discount = self::discount_for( $source );
			if ( ! $source || $discount <= 0 ) {
				continue;
			}
			$item['data']->set_price( self::discounted( (float) $source->get_price(), $discount ) );
		}
	}

	/**
	 * Show the plan under the item name (classic cart, Store API item_data, mini carts).
	 *
	 * @param array<int, array<string, string>> $item_data Item data rows.
	 * @param array<string, mixed>              $cart_item Cart item.
	 * @return array<int, array<string, string>>
	 */
	public function display_cart_item_data( array $item_data, array $cart_item ): array {
		if ( empty( $cart_item[ self::CART_KEY ]['interval'] ) ) {
			return $item_data;
		}
		$item_data[] = array(
			'key'   => __( 'Subscription', 'brewbean-woo-enhancements' ),
			'value' => self::label( $cart_item[ self::CART_KEY ]['interval'], self::discount_for( $cart_item['data'] ?? null ) ),
		);
		return $item_data;
	}

	/**
	 * Strike-through the regular price for subscribed items on the classic cart.
	 *
	 * @param string               $price_html Price HTML.
	 * @param array<string, mixed> $cart_item  Cart item.
	 */
	public function cart_item_price( string $price_html, array $cart_item ): string {
		if ( empty( $cart_item[ self::CART_KEY ] ) ) {
			return $price_html;
		}
		$source = wc_get_product( ! empty( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : (int) $cart_item['product_id'] );
		if ( ! $source ) {
			return $price_html;
		}
		return wc_format_sale_price(
			wc_get_price_to_display( $source ),
			wc_get_price_to_display( $cart_item['data'] )
		);
	}

	/**
	 * Persist the plan on the order line item (HPOS-safe CRUD).
	 *
	 * @param \WC_Order_Item_Product $item          Order item.
	 * @param string                 $cart_item_key Cart key.
	 * @param array<string, mixed>   $values        Cart item.
	 */
	public function add_order_item_meta( $item, $cart_item_key, $values ): void {
		if ( empty( $values[ self::CART_KEY ]['interval'] ) ) {
			return;
		}
		$interval = (string) $values[ self::CART_KEY ]['interval'];
		$item->add_meta_data( self::ORDER_META, $interval, true );
		$item->add_meta_data( '_brewbean_subscription_discount', (string) self::discount_for( $values['data'] ?? null ), true );
		$item->add_meta_data( 'brewbean_subscription', self::label( $interval, self::discount_for( $values['data'] ?? null ) ), true );
		unset( $cart_item_key );
	}

	/**
	 * Friendly label for the visible order item meta key.
	 *
	 * @param string         $display_key Key.
	 * @param \WC_Meta_Data $meta        Meta object.
	 */
	public function order_meta_label( string $display_key, $meta ): string {
		return 'brewbean_subscription' === $meta->key ? __( 'Subscription', 'brewbean-woo-enhancements' ) : $display_key;
	}

	/**
	 * Keep the machine-readable keys out of the admin order items table.
	 *
	 * @param string[] $hidden Hidden meta keys.
	 * @return string[]
	 */
	public function hide_internal_meta( array $hidden ): array {
		$hidden[] = self::ORDER_META;
		$hidden[] = '_brewbean_subscription_discount';
		return $hidden;
	}

	/**
	 * "Every 2 weeks · save 15%".
	 *
	 * @param string $interval Interval key.
	 * @param float  $discount Discount percentage.
	 */
	public static function label( string $interval, float $discount ): string {
		$intervals = self::intervals();
		return sprintf(
			/* translators: 1: delivery interval, e.g. "Every 2 weeks", 2: discount percentage */
			__( '%1$s · save %2$s%%', 'brewbean-woo-enhancements' ),
			$intervals[ $interval ] ?? $interval,
			wc_format_localized_decimal( (string) $discount )
		);
	}
}
