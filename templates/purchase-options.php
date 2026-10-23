<?php
/**
 * "One-time purchase" vs "Subscribe & save" choice inside the add-to-cart form.
 *
 * Override by copying to yourtheme/brewbean/purchase-options.php.
 *
 * @package BrewBean\WooEnhancements
 *
 * @var WC_Product            $product    Product.
 * @var float                 $discount   Discount percentage.
 * @var string                $once_price Price HTML for a one-time purchase (simple products).
 * @var string                $sub_price  Price HTML when subscribed (simple products).
 * @var array<string, string> $intervals  Interval key => label.
 * @var string                $uid        Unique ID prefix for this instance.
 */

defined( 'ABSPATH' ) || exit;

$brewbean_discount = wc_format_localized_decimal( (string) $discount );
?>
<fieldset class="brewbean-purchase-options" data-brewbean-purchase-options>
	<legend class="brewbean-purchase-options__legend"><?php esc_html_e( 'Purchase options', 'brewbean-woo-enhancements' ); ?></legend>

	<label class="brewbean-option" for="<?php echo esc_attr( $uid ); ?>-once">
		<input type="radio" id="<?php echo esc_attr( $uid ); ?>-once" name="brewbean_purchase" value="once" checked />
		<span class="brewbean-option__body">
			<span class="brewbean-option__title"><?php esc_html_e( 'One-time purchase', 'brewbean-woo-enhancements' ); ?></span>
			<?php if ( $once_price ) : ?>
				<span class="brewbean-option__price"><?php echo wp_kses_post( $once_price ); ?></span>
			<?php endif; ?>
		</span>
	</label>

	<label class="brewbean-option brewbean-option--subscribe" for="<?php echo esc_attr( $uid ); ?>-sub">
		<input type="radio" id="<?php echo esc_attr( $uid ); ?>-sub" name="brewbean_purchase" value="subscribe" />
		<span class="brewbean-option__body">
			<span class="brewbean-option__title">
				<?php
				/* translators: %s: discount percentage */
				printf( esc_html__( 'Subscribe & save %s%%', 'brewbean-woo-enhancements' ), esc_html( $brewbean_discount ) );
				?>
				<span class="brewbean-badge"><?php esc_html_e( 'Best value', 'brewbean-woo-enhancements' ); ?></span>
			</span>
			<span class="brewbean-option__price" data-brewbean-sub-price><?php echo $sub_price ? wp_kses_post( $sub_price ) : esc_html__( 'on every bag', 'brewbean-woo-enhancements' ); ?></span>
			<span class="brewbean-option__hint"><?php esc_html_e( 'Freshly roasted and delivered on your schedule. Skip or cancel anytime.', 'brewbean-woo-enhancements' ); ?></span>
		</span>
	</label>

	<div class="brewbean-option__interval" data-brewbean-interval hidden>
		<label for="<?php echo esc_attr( $uid ); ?>-interval"><?php esc_html_e( 'Deliver', 'brewbean-woo-enhancements' ); ?></label>
		<select id="<?php echo esc_attr( $uid ); ?>-interval" name="brewbean_interval">
			<?php foreach ( $intervals as $brewbean_key => $brewbean_label ) : ?>
				<option value="<?php echo esc_attr( $brewbean_key ); ?>" <?php selected( '2-weeks', $brewbean_key ); ?>><?php echo esc_html( $brewbean_label ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
</fieldset>
