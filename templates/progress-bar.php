<?php
/**
 * Free-shipping progress bar.
 *
 * Override by copying to yourtheme/brewbean/progress-bar.php.
 *
 * @package BrewBean\WooEnhancements
 *
 * @var string               $context Rendering context (cart|side-cart).
 * @var array<string, mixed> $data    Progress data: threshold, subtotal, remaining, percent, qualified.
 * @var string               $message Pre-escaped message HTML.
 */

defined( 'ABSPATH' ) || exit;

$brewbean_percent = (int) $data['percent'];
?>
<div class="brewbean-progress brewbean-progress--<?php echo esc_attr( $context ); ?><?php echo $data['qualified'] ? ' is-qualified' : ''; ?>">
	<p class="brewbean-progress__message"><?php echo wp_kses_post( $message ); ?></p>
	<div class="brewbean-progress__track" role="progressbar" aria-label="<?php esc_attr_e( 'Progress towards free shipping', 'brewbean-woo-enhancements' ); ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo esc_attr( (string) $brewbean_percent ); ?>">
		<span class="brewbean-progress__fill" style="width: <?php echo esc_attr( (string) $brewbean_percent ); ?>%"></span>
	</div>
</div>
