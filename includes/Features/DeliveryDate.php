<?php
/**
 * "Preferred delivery date" checkout field.
 *
 * Rendered on the (classic) checkout, validated server-side (format, lead time,
 * booking window, no Sundays), stored as order meta through the WC_Order CRUD API
 * (so it works with HPOS and legacy post storage), and shown on the admin order
 * screen, the orders list, the thank-you / My Account page and order emails.
 *
 * @package BrewBean\WooEnhancements
 */

declare( strict_types=1 );

namespace BrewBean\WooEnhancements\Features;

use BrewBean\WooEnhancements\Plugin;
use BrewBean\WooEnhancements\Settings;
use DateTimeImmutable;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * Delivery date feature.
 */
final class DeliveryDate {

	public const FIELD    = 'brewbean_delivery_date';
	public const META_KEY = '_brewbean_delivery_date';

	/**
	 * Hook everything.
	 */
	public function register(): void {
		add_action( 'woocommerce_before_order_notes', array( $this, 'render_field' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );

		// Display.
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'render_admin' ) );
		add_filter( 'woocommerce_email_order_meta_fields', array( $this, 'email_fields' ), 10, 3 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_customer' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_styles' ) );

		// Orders list column – HPOS screen and legacy posts screen.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Date rules
	 * ------------------------------------------------------------------- */

	/**
	 * Today in the store timezone.
	 */
	private static function today(): DateTimeImmutable {
		return new DateTimeImmutable( 'today', wp_timezone() );
	}

	/**
	 * Earliest allowed date (skips a Sunday when needed).
	 */
	public static function min_date(): DateTimeImmutable {
		$date = self::today()->modify( '+' . (int) Settings::get( 'delivery_lead_days' ) . ' days' );
		if ( Settings::enabled( 'delivery_skip_sundays' ) && '7' === $date->format( 'N' ) ) {
			$date = $date->modify( '+1 day' );
		}
		return $date;
	}

	/**
	 * Latest allowed date.
	 */
	public static function max_date(): DateTimeImmutable {
		return self::today()->modify( '+' . (int) Settings::get( 'delivery_window_days' ) . ' days' );
	}

	/**
	 * Validate a Y-m-d string. Returns an error message or null when valid.
	 *
	 * @param string $value Submitted value.
	 */
	public static function validation_error( string $value ): ?string {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
		if ( ! $date || $date->format( 'Y-m-d' ) !== $value ) {
			return __( 'Please enter a valid preferred delivery date.', 'brewbean-woo-enhancements' );
		}
		if ( $date < self::min_date() ) {
			return sprintf(
				/* translators: %s: earliest date */
				__( 'We roast to order – the earliest delivery date is %s.', 'brewbean-woo-enhancements' ),
				self::format( self::min_date()->format( 'Y-m-d' ) )
			);
		}
		if ( $date > self::max_date() ) {
			return sprintf(
				/* translators: %s: latest date */
				__( 'Please choose a delivery date on or before %s.', 'brewbean-woo-enhancements' ),
				self::format( self::max_date()->format( 'Y-m-d' ) )
			);
		}
		if ( Settings::enabled( 'delivery_skip_sundays' ) && '7' === $date->format( 'N' ) ) {
			return __( 'We do not deliver on Sundays. Please choose another day.', 'brewbean-woo-enhancements' );
		}
		return null;
	}

	/**
	 * Localized, human friendly date ("Tuesday, October 27, 2026").
	 *
	 * @param string $ymd Date in Y-m-d.
	 */
	public static function format( string $ymd ): string {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() );
		return $date ? wp_date( 'l, ' . get_option( 'date_format', 'F j, Y' ), $date->getTimestamp() ) : $ymd;
	}

	/* ---------------------------------------------------------------------
	 * Checkout
	 * ------------------------------------------------------------------- */

	/**
	 * Field markup, using WooCommerce's own form field renderer.
	 *
	 * @param \WC_Checkout $checkout Checkout object.
	 */
	public function render_field( $checkout ): void {
		$required = Settings::enabled( 'delivery_date_required' );
		$hint     = Settings::enabled( 'delivery_skip_sundays' )
			? __( 'We roast every bag to order. Deliveries Monday to Saturday.', 'brewbean-woo-enhancements' )
			: __( 'We roast every bag to order.', 'brewbean-woo-enhancements' );

		echo '<div class="brewbean-delivery-date">';
		woocommerce_form_field(
			self::FIELD,
			array(
				'type'              => 'date',
				'label'             => __( 'Preferred delivery date', 'brewbean-woo-enhancements' ),
				'required'          => $required,
				'class'             => array( 'form-row-wide' ),
				'description'       => $hint,
				'custom_attributes' => array(
					'min'                       => self::min_date()->format( 'Y-m-d' ),
					'max'                       => self::max_date()->format( 'Y-m-d' ),
					'data-brewbean-no-sundays' => Settings::enabled( 'delivery_skip_sundays' ) ? '1' : '0',
				),
			),
			$checkout->get_value( self::FIELD )
		);
		echo '</div>';
	}

	/**
	 * Server-side validation (the browser constraints are only a convenience).
	 *
	 * @param array<string, mixed> $data   Posted checkout data.
	 * @param \WP_Error            $errors Errors.
	 */
	public function validate( array $data, \WP_Error $errors ): void {
		unset( $data );
		$value = self::posted_value();

		if ( '' === $value ) {
			if ( Settings::enabled( 'delivery_date_required' ) ) {
				$errors->add(
					'brewbean_delivery_date_required',
					sprintf(
						/* translators: %s: field label */
						__( '%s is a required field.', 'brewbean-woo-enhancements' ),
						'<strong>' . esc_html__( 'Preferred delivery date', 'brewbean-woo-enhancements' ) . '</strong>'
					),
					array( 'id' => self::FIELD )
				);
			}
			return;
		}

		$error = self::validation_error( $value );
		if ( $error ) {
			$errors->add( 'brewbean_delivery_date_invalid', esc_html( $error ), array( 'id' => self::FIELD ) );
		}
	}

	/**
	 * Save on the order object (no direct post meta calls – HPOS compatible).
	 *
	 * @param WC_Order             $order Order being created.
	 * @param array<string, mixed> $data  Posted data.
	 */
	public function save( WC_Order $order, array $data ): void {
		unset( $data );
		$value = self::posted_value();
		if ( '' !== $value && null === self::validation_error( $value ) ) {
			$order->update_meta_data( self::META_KEY, $value );
		}
	}

	/**
	 * Sanitized posted value. The checkout nonce is verified by WC_Checkout::process_checkout().
	 */
	private static function posted_value(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce before validation.
		return isset( $_POST[ self::FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) : '';
	}

	/**
	 * Small progressive-enhancement script (Sunday warning) + styles.
	 */
	public function enqueue(): void {
		if ( is_checkout() && ! is_order_received_page() ) {
			wp_enqueue_style( 'brewbean-we-frontend' );
			wp_enqueue_script(
				'brewbean-we-checkout',
				BREWBEAN_WE_URL . 'assets/js/checkout.js',
				array( 'wp-i18n' ),
				Plugin::asset_version( 'assets/js/checkout.js' ),
				array( 'strategy' => 'defer', 'in_footer' => true )
			);
			wp_set_script_translations( 'brewbean-we-checkout', 'brewbean-woo-enhancements', BREWBEAN_WE_DIR . 'languages' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Display
	 * ------------------------------------------------------------------- */

	/**
	 * Small styles for the order edit screen (HPOS and legacy).
	 */
	public function admin_styles(): void {
		$screen = get_current_screen();
		if ( $screen && in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'shop_order' ), true ) ) {
			wp_enqueue_style( 'brewbean-we-admin', BREWBEAN_WE_URL . 'assets/css/admin.css', array(), Plugin::asset_version( 'assets/css/admin.css' ) );
		}
	}

	/**
	 * Read the stored date from an order.
	 *
	 * @param WC_Order|null $order Order.
	 */
	public static function get( ?WC_Order $order ): string {
		return $order ? (string) $order->get_meta( self::META_KEY ) : '';
	}

	/**
	 * Admin order screen (below the shipping address).
	 *
	 * @param WC_Order $order Order.
	 */
	public function render_admin( $order ): void {
		$value = self::get( $order );
		?>
		<div class="brewbean-admin-delivery">
			<h3><?php esc_html_e( 'Preferred delivery date', 'brewbean-woo-enhancements' ); ?></h3>
			<p>
				<?php if ( $value ) : ?>
					<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
					<strong><?php echo esc_html( self::format( $value ) ); ?></strong>
				<?php else : ?>
					<em><?php esc_html_e( 'Not specified', 'brewbean-woo-enhancements' ); ?></em>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Order emails (customer + admin, HTML + plain text).
	 *
	 * @param array<string, array<string, string>> $fields        Fields.
	 * @param bool                                 $sent_to_admin Admin email.
	 * @param WC_Order|mixed                       $order         Order.
	 * @return array<string, array<string, string>>
	 */
	public function email_fields( array $fields, $sent_to_admin, $order ): array {
		unset( $sent_to_admin );
		$value = $order instanceof WC_Order ? self::get( $order ) : '';
		if ( $value ) {
			$fields['brewbean_delivery_date'] = array(
				'label' => __( 'Preferred delivery date', 'brewbean-woo-enhancements' ),
				'value' => self::format( $value ),
			);
		}
		return $fields;
	}

	/**
	 * Thank-you page and My Account → View order.
	 *
	 * @param WC_Order $order Order.
	 */
	public function render_customer( $order ): void {
		$value = self::get( $order );
		if ( ! $value ) {
			return;
		}
		printf(
			'<section class="brewbean-order-delivery"><h2>%1$s</h2><p>%2$s</p></section>',
			esc_html__( 'Delivery', 'brewbean-woo-enhancements' ),
			sprintf(
				/* translators: %s: formatted date */
				esc_html__( 'Preferred delivery date: %s', 'brewbean-woo-enhancements' ),
				'<strong>' . esc_html( self::format( $value ) ) . '</strong>'
			)
		);
	}

	/**
	 * Orders list column.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function add_column( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new['brewbean_delivery'] = __( 'Delivery date', 'brewbean-woo-enhancements' );
			}
		}
		return $new;
	}

	/**
	 * Column content. HPOS passes the order object, the legacy screen passes the post ID.
	 *
	 * @param string             $column      Column key.
	 * @param WC_Order|int|mixed $order_or_id Order or ID.
	 */
	public function render_column( string $column, $order_or_id ): void {
		if ( 'brewbean_delivery' !== $column ) {
			return;
		}
		$order = $order_or_id instanceof WC_Order ? $order_or_id : wc_get_order( (int) $order_or_id );
		$value = $order instanceof WC_Order ? self::get( $order ) : '';
		$date  = $value ? DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() ) : false;

		if ( $date ) {
			printf( '<time datetime="%1$s">%2$s</time>', esc_attr( $value ), esc_html( wp_date( 'M j, Y', $date->getTimestamp() ) ) );
		} else {
			echo '&ndash;';
		}
	}
}
