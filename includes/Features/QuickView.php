<?php
/**
 * Product quick view modal on shop / archive pages.
 *
 * - Adds a "Quick view" button to product cards: block themes (Product Collection /
 *   All Products button block via render_block) and classic loops (woocommerce_after_shop_loop_item).
 * - ?wc-ajax=brewbean_quick_view returns the modal markup (templates/quick-view.php).
 * - Variations are matched in vanilla JS from the product's available variations,
 *   and the form posts to the shared AJAX add-to-cart endpoint.
 *
 * @package BrewBean\WooEnhancements
 */

declare( strict_types=1 );

namespace BrewBean\WooEnhancements\Features;

use BrewBean\WooEnhancements\Plugin;
use WC_Product;

defined( 'ABSPATH' ) || exit;

/**
 * Quick view feature.
 */
final class QuickView {

	public const ENDPOINT = 'brewbean_quick_view';

	/**
	 * Hook everything.
	 */
	public function register(): void {
		add_filter( 'render_block_woocommerce/product-button', array( $this, 'append_to_block_button' ), 10, 3 );
		add_action(
			'after_setup_theme',
			function (): void {
				// Block themes get the button through the product button block instead.
				if ( ! wp_is_block_theme() ) {
					add_action( 'woocommerce_after_shop_loop_item', array( $this, 'render_classic_button' ), 15 );
				}
			}
		);
		add_action( 'wc_ajax_' . self::ENDPOINT, array( $this, 'handle' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_action( 'wp_footer', array( $this, 'render_modal_shell' ) );
	}

	/**
	 * Load the modal only where product loops are likely.
	 */
	private function is_catalog_context(): bool {
		return is_shop() || is_product_taxonomy() || is_front_page() || is_search() || is_product();
	}

	/**
	 * Assets.
	 */
	public function enqueue(): void {
		if ( ! $this->is_catalog_context() ) {
			return;
		}
		wp_enqueue_style( 'brewbean-we-frontend' );
		wp_enqueue_script(
			'brewbean-we-quick-view',
			BREWBEAN_WE_URL . 'assets/js/quick-view.js',
			array( 'brewbean-we-core' ),
			Plugin::asset_version( 'assets/js/quick-view.js' ),
			array( 'strategy' => 'defer', 'in_footer' => true )
		);
		wp_set_script_translations( 'brewbean-we-quick-view', 'brewbean-woo-enhancements', BREWBEAN_WE_DIR . 'languages' );
	}

	/**
	 * Button markup.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function button( int $product_id ): string {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return '';
		}
		return sprintf(
			'<button type="button" class="brewbean-quick-view-button" data-brewbean-quick-view="%1$d" aria-haspopup="dialog">
				<svg aria-hidden="true" viewBox="0 0 24 24" width="16" height="16"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
				<span>%2$s</span><span class="screen-reader-text"> %3$s</span>
			</button>',
			$product_id,
			esc_html__( 'Quick view', 'brewbean-woo-enhancements' ),
			/* translators: %s: product name */
			esc_html( sprintf( __( 'of %s', 'brewbean-woo-enhancements' ), $product->get_name() ) )
		);
	}

	/**
	 * Block themes: append after the Add to cart button inside product collections.
	 *
	 * @param string    $content Block HTML.
	 * @param array     $parsed  Parsed block.
	 * @param \WP_Block $block   Block instance.
	 */
	public function append_to_block_button( string $content, array $parsed, $block ): string {
		unset( $parsed );
		$product_id = isset( $block->context['postId'] ) ? (int) $block->context['postId'] : 0;
		// Skip products without context and the main product on its own page.
		if ( ! $product_id || ( is_product() && get_queried_object_id() === $product_id ) ) {
			return $content;
		}
		return '<div class="brewbean-card-actions">' . $content . self::button( $product_id ) . '</div>';
	}

	/**
	 * Classic themes.
	 */
	public function render_classic_button(): void {
		global $product;
		if ( $product instanceof WC_Product ) {
			echo self::button( $product->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in button().
		}
	}

	/**
	 * Empty dialog, filled by JS.
	 */
	public function render_modal_shell(): void {
		if ( ! $this->is_catalog_context() ) {
			return;
		}
		?>
		<div class="brewbean-modal" data-brewbean-modal hidden>
			<div class="brewbean-modal__overlay" data-brewbean-modal-close></div>
			<div class="brewbean-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="brewbean-qv-title" tabindex="-1">
				<button type="button" class="brewbean-icon-button brewbean-modal__close" data-brewbean-modal-close>
					<span class="screen-reader-text"><?php esc_html_e( 'Close quick view', 'brewbean-woo-enhancements' ); ?></span>
					<svg aria-hidden="true" viewBox="0 0 24 24" width="22" height="22"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
				</button>
				<div class="brewbean-modal__content" data-brewbean-modal-content aria-live="polite"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: return the quick view markup for one product.
	 */
	public function handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only product data.
		$product_id = isset( $_GET['product_id'] ) ? absint( wp_unslash( $_GET['product_id'] ) ) : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : null;

		if ( ! $product || 'publish' !== $product->get_status() || ! $product->is_visible() ) {
			wp_send_json_error( array( 'message' => __( 'Product not found.', 'brewbean-woo-enhancements' ) ), 404 );
		}

		wp_send_json_success( array( 'html' => $this->render( $product ) ) );
	}

	/**
	 * Build template variables and render.
	 *
	 * @param WC_Product $product Product.
	 */
	private function render( WC_Product $product ): string {
		$image_ids = array_values( array_filter( array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() ) ) );
		$images    = array();
		foreach ( $image_ids as $id ) {
			$images[] = array(
				'id'    => (int) $id,
				'full'  => (string) wp_get_attachment_image_url( (int) $id, 'woocommerce_single' ),
				'thumb' => (string) wp_get_attachment_image_url( (int) $id, 'woocommerce_gallery_thumbnail' ),
				'alt'   => (string) get_post_meta( (int) $id, '_wp_attachment_image_alt', true ),
			);
		}

		$attributes = array();
		$variations = array();
		if ( $product instanceof \WC_Product_Variable ) {
			foreach ( $product->get_variation_attributes() as $name => $options ) {
				$is_tax  = taxonomy_exists( $name );
				$choices = array();
				foreach ( $options as $option ) {
					$label = $option;
					if ( $is_tax ) {
						$term  = get_term_by( 'slug', $option, $name );
						$label = $term ? $term->name : $option;
					}
					$choices[ $option ] = $label;
				}
				$attributes[ 'attribute_' . sanitize_title( $name ) ] = array(
					'label'   => wc_attribute_label( $name, $product ),
					'options' => $choices,
				);
			}
			foreach ( $product->get_available_variations() as $variation ) {
				$variations[] = array(
					'id'         => (int) $variation['variation_id'],
					'attributes' => $variation['attributes'],
					'priceHtml'  => $variation['price_html'] ? $variation['price_html'] : wc_price( (float) $variation['display_price'] ),
					'subHtml'    => $variation['brewbean_subscribe_price_html'] ?? '',
					'inStock'    => (bool) $variation['is_in_stock'] && (bool) $variation['is_purchasable'],
					'maxQty'     => '' === $variation['max_qty'] ? null : (int) $variation['max_qty'],
					'image'      => $variation['image']['src'] ?? '',
					'sku'        => $variation['sku'] ?? '',
				);
			}
		}

		return Plugin::template_html(
			'quick-view.php',
			array(
				'product'    => $product,
				'images'     => $images,
				'attributes' => $attributes,
				'variations' => $variations,
				'defaults'   => $product->get_default_attributes(),
			)
		);
	}
}
