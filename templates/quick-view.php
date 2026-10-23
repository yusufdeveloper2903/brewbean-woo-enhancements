<?php
/**
 * Quick view modal content.
 *
 * Override by copying to yourtheme/brewbean/quick-view.php (keep the data-* hooks).
 *
 * @package BrewBean\WooEnhancements
 *
 * @var WC_Product                          $product    Product.
 * @var array<int, array<string, mixed>>    $images     Images: id, full, thumb, alt.
 * @var array<string, array<string, mixed>> $attributes Variation attributes keyed by form field name.
 * @var array<int, array<string, mixed>>    $variations Available variations (variable products).
 * @var array<string, string>               $defaults   Default attributes.
 */

defined( 'ABSPATH' ) || exit;

$brewbean_is_variable = $product->is_type( 'variable' );
$brewbean_can_buy     = $product->is_purchasable() && $product->is_in_stock() && $product->is_type( array( 'simple', 'variable' ) );
$brewbean_categories  = wc_get_product_category_list( $product->get_id(), ', ' );
?>
<article class="brewbean-qv" data-brewbean-qv>
	<div class="brewbean-qv__gallery" data-brewbean-gallery>
		<figure class="brewbean-qv__main">
			<?php if ( $images ) : ?>
				<img src="<?php echo esc_url( $images[0]['full'] ); ?>" alt="<?php echo esc_attr( $images[0]['alt'] ? $images[0]['alt'] : $product->get_name() ); ?>" data-brewbean-main-image width="600" height="600" />
			<?php else : ?>
				<?php echo wp_kses_post( wc_placeholder_img( 'woocommerce_single' ) ); ?>
			<?php endif; ?>
			<?php if ( $product->is_on_sale() ) : ?>
				<span class="brewbean-qv__sale"><?php esc_html_e( 'Sale', 'brewbean-woo-enhancements' ); ?></span>
			<?php endif; ?>
		</figure>
		<?php if ( count( $images ) > 1 ) : ?>
			<ul class="brewbean-qv__thumbs" aria-label="<?php esc_attr_e( 'Product images', 'brewbean-woo-enhancements' ); ?>">
				<?php foreach ( $images as $brewbean_i => $brewbean_image ) : ?>
					<li>
						<button type="button" class="brewbean-qv__thumb<?php echo 0 === $brewbean_i ? ' is-active' : ''; ?>" data-brewbean-thumb="<?php echo esc_url( $brewbean_image['full'] ); ?>" aria-pressed="<?php echo 0 === $brewbean_i ? 'true' : 'false'; ?>">
							<img src="<?php echo esc_url( $brewbean_image['thumb'] ); ?>" alt="" width="80" height="80" />
							<span class="screen-reader-text">
								<?php
								/* translators: %d: image number */
								echo esc_html( sprintf( __( 'Show image %d', 'brewbean-woo-enhancements' ), $brewbean_i + 1 ) );
								?>
							</span>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>

	<div class="brewbean-qv__summary">
		<?php if ( $brewbean_categories ) : ?>
			<p class="brewbean-qv__eyebrow"><?php echo esc_html( wp_strip_all_tags( $brewbean_categories ) ); ?></p>
		<?php endif; ?>
		<h2 class="brewbean-qv__title" id="brewbean-qv-title"><?php echo esc_html( $product->get_name() ); ?></h2>
		<p class="brewbean-qv__price price" data-brewbean-qv-price><?php echo wp_kses_post( $product->get_price_html() ); ?></p>
		<div class="brewbean-qv__excerpt">
			<?php echo wp_kses_post( wpautop( $product->get_short_description() ) ); ?>
		</div>

		<?php if ( $brewbean_can_buy ) : ?>
			<form class="brewbean-qv__form cart" data-brewbean-ajax-form method="post" action="<?php echo esc_url( $product->get_permalink() ); ?>"
				<?php if ( $brewbean_is_variable ) : ?>
					data-brewbean-variations="<?php echo esc_attr( wc_esc_json( (string) wp_json_encode( $variations ) ) ); ?>"
				<?php endif; ?>
			>
				<?php foreach ( $attributes as $brewbean_field => $brewbean_attribute ) : ?>
					<?php $brewbean_default = $defaults[ substr( $brewbean_field, 10 ) ] ?? ''; ?>
					<fieldset class="brewbean-swatches" data-brewbean-attribute="<?php echo esc_attr( $brewbean_field ); ?>">
						<legend><?php echo esc_html( $brewbean_attribute['label'] ); ?></legend>
						<div class="brewbean-swatches__options">
							<?php foreach ( $brewbean_attribute['options'] as $brewbean_value => $brewbean_label ) : ?>
								<label class="brewbean-swatch">
									<input type="radio" name="<?php echo esc_attr( $brewbean_field ); ?>" value="<?php echo esc_attr( (string) $brewbean_value ); ?>" <?php checked( (string) $brewbean_default, (string) $brewbean_value ); ?> required />
									<span><?php echo esc_html( (string) $brewbean_label ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</fieldset>
				<?php endforeach; ?>

				<?php if ( $brewbean_is_variable ) : ?>
					<input type="hidden" name="variation_id" value="0" data-brewbean-variation-id />
					<input type="hidden" name="product_id" value="<?php echo esc_attr( (string) $product->get_id() ); ?>" />
					<p class="brewbean-qv__availability" data-brewbean-availability role="status"></p>
				<?php endif; ?>

				<?php
				/**
				 * Hook: inside the quick view form, before the quantity / add to cart row.
				 * Subscribe & save prints its purchase options here.
				 *
				 * @param WC_Product $product Product.
				 */
				do_action( 'brewbean_we_quick_view_before_add_to_cart', $product );
				?>

				<div class="brewbean-qv__actions">
					<?php
					woocommerce_quantity_input(
						array(
							'min_value'   => $product->get_min_purchase_quantity(),
							'max_value'   => $product->get_max_purchase_quantity(),
							'input_value' => $product->get_min_purchase_quantity(),
						),
						$product
					);
					?>
					<input type="hidden" name="add-to-cart" value="<?php echo esc_attr( (string) $product->get_id() ); ?>" />
					<button type="submit" class="brewbean-button single_add_to_cart_button" data-brewbean-submit><?php echo esc_html( $product->single_add_to_cart_text() ); ?></button>
				</div>
				<div class="brewbean-form-error" data-brewbean-form-error role="alert" hidden></div>
			</form>
		<?php elseif ( ! $product->is_in_stock() ) : ?>
			<p class="stock out-of-stock"><?php esc_html_e( 'Out of stock', 'brewbean-woo-enhancements' ); ?></p>
		<?php endif; ?>

		<a class="brewbean-qv__details" href="<?php echo esc_url( $product->get_permalink() ); ?>">
			<?php esc_html_e( 'View full details', 'brewbean-woo-enhancements' ); ?> <span aria-hidden="true">&rarr;</span>
		</a>
	</div>
</article>
