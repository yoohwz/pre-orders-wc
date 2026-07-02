<?php

if (!defined('ABSPATH')) {
	exit;
}

class YoOhw_Pre_Orders_WC_Product_List {

	public function __construct() {
		// Hook into the 'woocommerce_product_add_to_cart_text' filter to change the add to cart button text on product list pages
		add_filter('woocommerce_product_add_to_cart_text', array($this, 'change_add_to_cart_button_text'), 10, 2);
		// Hook into the 'woocommerce_after_shop_loop_item' action to display the pre-order availability date before the button
		add_action('woocommerce_after_shop_loop_item', array($this, 'display_pre_order_date_message'), 9);
	}

	/**
	 * Change the add to cart button text for 'On preorder' products.
	 *
	 * @param string $text The current add to cart text.
	 * @param WC_Product $product The product object.
	 * @return string Modified add to cart text.
	 */
	public function change_add_to_cart_button_text($text, $product) {
		if ($product->get_stock_status() === 'onpreorder') {
			$preorder_text = get_option( 'yoess_pre_order_button_text', __( 'Pre-order now', 'pre-orders-wc' ) );
			return esc_html( $preorder_text );
		}
		return $text;
	}

	/**
	 * Display the pre-order availability date message before the add to cart button.
	 */
	public function display_pre_order_date_message() {
		global $product;
		if ($product->get_stock_status() === 'onpreorder') {
			$pre_order_date = get_post_meta($product->get_id(), '_pre_order_available_date', true);
			if ($pre_order_date) {
				echo sprintf(
					'<p class="pre-order-availability-date">' . wp_kses(
						sprintf(
							/* translators: %s: the available date of the pre-order product */
							__('This item will be available on <strong>%s</strong>.', 'pre-orders-wc'),
							esc_html(date_i18n(get_option('date_format'), strtotime($pre_order_date)))
						),
						array('strong' => array())
					) . '</p>'
				);
			}			
		}
	}
}

new YoOhw_Pre_Orders_WC_Product_List();
