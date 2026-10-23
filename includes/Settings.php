<?php
/**
 * Plugin settings: storage, defaults and the WooCommerce → Brew & Bean admin screen.
 *
 * @package BrewBean\WooEnhancements
 */

declare( strict_types=1 );

namespace BrewBean\WooEnhancements;

defined( 'ABSPATH' ) || exit;

/**
 * Settings API wrapper. All options live in a single autoloaded array option.
 */
final class Settings {

	public const OPTION    = 'brewbean_we_settings';
	public const GROUP     = 'brewbean_we_settings_group';
	public const PAGE_SLUG = 'brewbean-settings';

	/**
	 * Request-level cache of the merged settings.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Default values. Also acts as the whitelist of known keys.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'side_cart_enabled'       => true,
			'side_cart_auto_open'     => true,
			'progress_enabled'        => true,
			'free_shipping_threshold' => 50.0,
			'apply_free_shipping'     => true,
			'quick_view_enabled'      => true,
			'delivery_date_enabled'   => true,
			'delivery_date_required'  => true,
			'delivery_lead_days'      => 2,
			'delivery_window_days'    => 30,
			'delivery_skip_sundays'   => true,
			'subscribe_enabled'       => true,
		);
	}

	/**
	 * Field definitions used to render and sanitize the settings screen.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function fields(): array {
		return array(
			'side_cart_enabled'       => array( 'section' => 'cart', 'type' => 'checkbox', 'title' => __( 'Side cart', 'brewbean-woo-enhancements' ), 'label' => __( 'Enable the AJAX slide-out side cart', 'brewbean-woo-enhancements' ) ),
			'side_cart_auto_open'     => array( 'section' => 'cart', 'type' => 'checkbox', 'title' => __( 'Auto-open', 'brewbean-woo-enhancements' ), 'label' => __( 'Open the side cart automatically after a product is added', 'brewbean-woo-enhancements' ) ),
			'progress_enabled'        => array( 'section' => 'shipping', 'type' => 'checkbox', 'title' => __( 'Progress bar', 'brewbean-woo-enhancements' ), 'label' => __( 'Show the free-shipping progress bar (side cart and cart page)', 'brewbean-woo-enhancements' ) ),
			'free_shipping_threshold' => array(
				'section'     => 'shipping',
				'type'        => 'price',
				'label'       => __( 'Free-shipping threshold', 'brewbean-woo-enhancements' ),
				'description' => __( 'Cart subtotal (after product discounts) that unlocks free shipping.', 'brewbean-woo-enhancements' ),
			),
			'apply_free_shipping'     => array( 'section' => 'shipping', 'type' => 'checkbox', 'title' => __( 'Free-shipping rule', 'brewbean-woo-enhancements' ), 'label' => __( 'Make every shipping rate free once the threshold is reached', 'brewbean-woo-enhancements' ) ),
			'quick_view_enabled'      => array( 'section' => 'catalog', 'type' => 'checkbox', 'title' => __( 'Quick view', 'brewbean-woo-enhancements' ), 'label' => __( 'Enable product quick view on shop and archive pages', 'brewbean-woo-enhancements' ) ),
			'subscribe_enabled'       => array( 'section' => 'catalog', 'type' => 'checkbox', 'title' => __( 'Subscribe & save', 'brewbean-woo-enhancements' ), 'label' => __( 'Enable subscribe & save (configured per product)', 'brewbean-woo-enhancements' ) ),
			'delivery_date_enabled'   => array( 'section' => 'delivery', 'type' => 'checkbox', 'title' => __( 'Delivery date field', 'brewbean-woo-enhancements' ), 'label' => __( 'Ask for a preferred delivery date at checkout', 'brewbean-woo-enhancements' ) ),
			'delivery_date_required'  => array( 'section' => 'delivery', 'type' => 'checkbox', 'title' => __( 'Required field', 'brewbean-woo-enhancements' ), 'label' => __( 'Make the delivery date required', 'brewbean-woo-enhancements' ) ),
			'delivery_lead_days'      => array(
				'section'     => 'delivery',
				'type'        => 'number',
				'min'         => 0,
				'max'         => 30,
				'label'       => __( 'Lead time (days)', 'brewbean-woo-enhancements' ),
				'description' => __( 'Earliest selectable date, counted from today. We roast to order.', 'brewbean-woo-enhancements' ),
			),
			'delivery_window_days'    => array(
				'section'     => 'delivery',
				'type'        => 'number',
				'min'         => 1,
				'max'         => 365,
				'label'       => __( 'Booking window (days)', 'brewbean-woo-enhancements' ),
				'description' => __( 'Latest selectable date, counted from today.', 'brewbean-woo-enhancements' ),
			),
			'delivery_skip_sundays'   => array( 'section' => 'delivery', 'type' => 'checkbox', 'title' => __( 'Sundays', 'brewbean-woo-enhancements' ), 'label' => __( 'No deliveries on Sundays', 'brewbean-woo-enhancements' ) ),
		);
	}

	/**
	 * Merged settings (stored values over defaults).
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return self::$cache;
	}

	/**
	 * Read one setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ): mixed {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Convenience boolean accessor.
	 *
	 * @param string $key Setting key.
	 */
	public static function enabled( string $key ): bool {
		return (bool) self::get( $key );
	}

	/**
	 * Free shipping threshold as a float (filterable, e.g. for multi-currency plugins).
	 */
	public static function threshold(): float {
		return (float) apply_filters( 'brewbean_we_free_shipping_threshold', (float) self::get( 'free_shipping_threshold' ) );
	}

	/**
	 * Hook the admin screen.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 60 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'update_option_' . self::OPTION, static fn () => self::$cache = null );
		// options.php checks `manage_options` by default; shop managers should be able to save too.
		add_filter( 'option_page_capability_' . self::GROUP, static fn () => 'manage_woocommerce' );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * WooCommerce → Brew & Bean.
	 */
	public function add_menu(): void {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'Brew & Bean settings', 'brewbean-woo-enhancements' ),
			__( 'Brew & Bean', 'brewbean-woo-enhancements' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
		if ( $hook ) {
			add_action( 'admin_print_styles-' . $hook, array( $this, 'enqueue_styles' ) );
		}
	}

	/**
	 * Settings page styles.
	 */
	public function enqueue_styles(): void {
		wp_enqueue_style( 'brewbean-we-admin', BREWBEAN_WE_URL . 'assets/css/admin.css', array(), VERSION );
	}

	/**
	 * Add a "Settings" link on the Plugins screen.
	 *
	 * @param array<int|string, string> $links Existing links.
	 * @return array<int|string, string>
	 */
	public function action_links( array $links ): array {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Settings', 'brewbean-woo-enhancements' ) ) );
		return $links;
	}

	/**
	 * Register the option, sections and fields with the Settings API.
	 */
	public function register_settings(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);

		$sections = array(
			'cart'     => array( __( 'Side cart', 'brewbean-woo-enhancements' ), __( 'A slide-out mini cart powered by the WooCommerce Store API.', 'brewbean-woo-enhancements' ) ),
			'shipping' => array( __( 'Free-shipping progress', 'brewbean-woo-enhancements' ), __( 'Nudge customers towards free shipping.', 'brewbean-woo-enhancements' ) ),
			'catalog'  => array( __( 'Catalog', 'brewbean-woo-enhancements' ), __( 'Shop page and product page features.', 'brewbean-woo-enhancements' ) ),
			'delivery' => array( __( 'Preferred delivery date', 'brewbean-woo-enhancements' ), __( 'An extra checkout field stored on the order (HPOS compatible).', 'brewbean-woo-enhancements' ) ),
		);

		foreach ( $sections as $id => [ $title, $intro ] ) {
			add_settings_section(
				'brewbean_' . $id,
				$title,
				static function () use ( $intro ): void {
					echo '<p class="brewbean-section-intro">' . esc_html( $intro ) . '</p>';
				},
				self::PAGE_SLUG
			);
		}

		foreach ( self::fields() as $key => $field ) {
			add_settings_field(
				'brewbean_' . $key,
				esc_html( 'checkbox' === $field['type'] ? $field['title'] : $field['label'] ),
				array( $this, 'render_field' ),
				self::PAGE_SLUG,
				'brewbean_' . $field['section'],
				array_merge( $field, array( 'key' => $key, 'label_for' => 'checkbox' === $field['type'] ? '' : 'brewbean-' . $key ) )
			);
		}
	}

	/**
	 * Sanitize the whole option array. Unknown keys are discarded.
	 *
	 * @param mixed $input Raw input from options.php.
	 * @return array<string, mixed>
	 */
	public function sanitize( mixed $input ): array {
		$input  = is_array( $input ) ? $input : array();
		$clean  = array();
		$fields = self::fields();

		foreach ( self::defaults() as $key => $default ) {
			$type = $fields[ $key ]['type'] ?? 'checkbox';
			$raw  = $input[ $key ] ?? null;

			switch ( $type ) {
				case 'checkbox':
					$clean[ $key ] = ! empty( $raw );
					break;
				case 'price':
					$value         = is_scalar( $raw ) ? wc_format_decimal( sanitize_text_field( (string) $raw ) ) : '';
					$clean[ $key ] = '' === $value ? (float) $default : max( 0.0, (float) $value );
					break;
				case 'number':
					$min           = (int) ( $fields[ $key ]['min'] ?? 0 );
					$max           = (int) ( $fields[ $key ]['max'] ?? PHP_INT_MAX );
					$clean[ $key ] = is_numeric( $raw ) ? min( $max, max( $min, absint( $raw ) ) ) : (int) $default;
					break;
			}
		}

		if ( $clean['delivery_window_days'] <= $clean['delivery_lead_days'] ) {
			$clean['delivery_window_days'] = $clean['delivery_lead_days'] + 1;
			add_settings_error( self::OPTION, 'brewbean_window', __( 'The booking window must be longer than the lead time, so it was adjusted.', 'brewbean-woo-enhancements' ), 'warning' );
		}

		return $clean;
	}

	/**
	 * Render a single field.
	 *
	 * @param array<string, mixed> $args Field definition.
	 */
	public function render_field( array $args ): void {
		$key   = (string) $args['key'];
		$id    = 'brewbean-' . $key;
		$name  = self::OPTION . '[' . $key . ']';
		$value = self::get( $key );

		switch ( $args['type'] ) {
			case 'checkbox':
				printf(
					'<label class="brewbean-toggle" for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /><span class="brewbean-toggle__track" aria-hidden="true"></span><span class="brewbean-toggle__label">%4$s</span></label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( (bool) $value, true, false ),
					esc_html( $args['label'] )
				);
				break;
			case 'price':
				printf(
					'<span class="brewbean-input-prefix">%1$s</span><input type="text" inputmode="decimal" class="small-text" id="%2$s" name="%3$s" value="%4$s" />',
					esc_html( get_woocommerce_currency_symbol() ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( wc_format_localized_price( (string) $value ) )
				);
				break;
			case 'number':
				printf(
					'<input type="number" class="small-text" id="%1$s" name="%2$s" value="%3$d" min="%4$d" max="%5$d" step="1" />',
					esc_attr( $id ),
					esc_attr( $name ),
					(int) $value,
					(int) $args['min'],
					(int) $args['max']
				);
				break;
		}

		if ( ! empty( $args['description'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( $args['description'] ) );
		}
	}

	/**
	 * Render the settings screen. settings_fields() outputs the nonce and option group.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'brewbean-woo-enhancements' ) );
		}
		?>
		<div class="wrap brewbean-settings">
			<header class="brewbean-settings__header">
				<span class="brewbean-settings__logo" aria-hidden="true">
					<svg viewBox="0 0 40 40" width="40" height="40"><circle cx="20" cy="20" r="19" fill="#3b2a20"/><path d="M20 8c-6 5-6 19 0 24 6-5 6-19 0-24z" fill="#c8873a"/><path d="M20 9c-2.5 6 2.5 16 0 22" stroke="#3b2a20" stroke-width="1.6" fill="none"/></svg>
				</span>
				<div>
					<h1><?php esc_html_e( 'Brew & Bean', 'brewbean-woo-enhancements' ); ?></h1>
					<p><?php esc_html_e( 'Store enhancements for your coffee shop. Toggle features and tune the details below.', 'brewbean-woo-enhancements' ); ?></p>
				</div>
			</header>
			<?php settings_errors(); ?>
			<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post" class="brewbean-settings__form">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Save settings', 'brewbean-woo-enhancements' ) );
				?>
			</form>
		</div>
		<?php
	}
}
