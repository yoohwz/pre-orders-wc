<?php

if (!defined('ABSPATH')) {
	exit;
}

class YoOhw_Pre_Orders_WC_Cart_Checkout {

	public function __construct() {
		add_filter( 'woocommerce_get_item_data', [ $this, 'add_available_date_item_data' ], 10, 2 );

		// Classic cart/checkout: hide the empty-label <dt> for our no-date row.
		add_action( 'wp_footer', [ $this, 'hide_empty_variation_label' ] );
	}

	public function hide_empty_variation_label() {
		// Frontend only.
		if ( is_admin() ) {
			return;
		}

		if ( ! ( function_exists( 'is_cart' ) && function_exists( 'is_checkout' ) ) ) {
			return;
		}

		// Only hides: <dt class="variation-">:</dt>
		?>
		<style id="yoess-hide-empty-variation-label">
			dt.variation- {
				display: none;
			}
		</style>
		<?php
	}

	/**
	 * Add preorder info as line-item meta.
	 * Appears under the product name in both classic and blocks carts.
	 */
	public function add_available_date_item_data( $data, $cart_item ) {
		if ( empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
			return $data;
		}

		$product      = $cart_item['data'];
		$stock_status = $product->get_stock_status();

		// Only handle pre-order items.
		if ( 'onpreorder' !== $stock_status ) {
			return $data;
		}

		$available_date = yoohw_pre_orders_wc_get_product_available_date( $product );
		$formatted_date = yoohw_pre_orders_wc_format_available_date( $available_date );

		// Build label + value depending on whether date exists.
		if ( $formatted_date ) {
			// With date → show label "Preorder" and value "Available on %s".
			$name = __( 'Preorder', 'pre-orders-wc' );

			$value_text = sprintf(
				/* translators: %s: the available date of the pre-order product. */
				__( 'Available on %s', 'pre-orders-wc' ),
				$formatted_date
			);
		} else {
			// No date → no label, just "On preorder".
			$name       = ''; // will be hidden in classic via CSS.
			$value_text = __( 'On preorder', 'pre-orders-wc' );
		}

		$value_text = (string) $value_text;

		$data[] = [
			'name'    => $name,
			'value'   => $value_text,
			'display' => $value_text,
		];

		return $data;
	}
}

new YoOhw_Pre_Orders_WC_Cart_Checkout();
