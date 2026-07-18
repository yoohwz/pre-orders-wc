<?php

if (!defined('ABSPATH')) {
	exit;
}

class YoOhw_Pre_Orders_WC_Order_Table {

	public function __construct() {
		// Hook into WooCommerce email item formatting to add pre-order notice after the product title in new order emails
		add_filter('woocommerce_order_item_name', array($this, 'display_preorder_text_and_date_in_order_table'), 10, 2);
		add_action('woocommerce_order_item_meta_end', array($this, 'add_preorder_notice_in_order_table'), 10, 3);
	}
	
	// Hook to add 'Pre-order' notice below the item name, similar to 'Backordered'
	public function add_preorder_notice_in_order_table($item_id, $item, $order) {
		// Check if the order item is a product
		if ($item instanceof WC_Order_Item_Product) {
			$product = $item->get_product();
	
			if ('onpreorder' === yoohw_pre_orders_wc_get_order_item_stock_status($item, $product)) {
				echo '<ul class="wc-item-meta">';
				echo '<li>';
				echo '<strong class="wc-item-meta-label">' . esc_html__('Pre-ordered', 'pre-orders-wc') . '</strong>:';
	
				// Retrieve the '_pre_order_available_date' meta and format it
				$pre_order_available_date = yoohw_pre_orders_wc_get_order_item_available_date($item, $product);
	
				if (!empty($pre_order_available_date)) {
					echo ' ' . esc_html__('Available on ', 'pre-orders-wc') . esc_html(yoohw_pre_orders_wc_format_available_date($pre_order_available_date));
				}

				echo '</li>';
			}
		}
	}
	
	// Modify product title as needed (not appending 'Pre-order' here, as it's handled by add_preorder_notice_in_email)
	public function display_preorder_text_and_date_in_order_table($item_name, $item) {
		return $item_name;  // No modification needed for the item name itself
	}
	
}

new YoOhw_Pre_Orders_WC_Order_Table();
