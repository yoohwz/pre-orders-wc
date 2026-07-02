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
	
			// Check if the product exists and has the 'onpreorder' stock status
			if ($product && $product->get_stock_status() === 'onpreorder') {
				echo '<ul class="wc-item-meta">';
				echo '<li>';
				echo '<strong class="wc-item-meta-label">' . esc_html__('Pre-ordered', 'pre-orders-wc') . '</strong>:';
	
				// Retrieve the '_pre_order_available_date' meta and format it
				$pre_order_available_date = get_post_meta($product->get_id(), '_pre_order_available_date', true);
	
				if (!empty($pre_order_available_date)) {
					// Convert the date to the site timezone for display
					$date = new DateTime($pre_order_available_date, new DateTimeZone('UTC'));
					$date->setTimezone(new DateTimeZone(wp_timezone_string()));  // Site timezone setting
	
					// Display only the available date under 'Pre-order item' using the site's date format
					echo ' ' . esc_html__('Available on ', 'pre-orders-wc') . esc_html(date_i18n(get_option('date_format'), $date->getTimestamp()));
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
