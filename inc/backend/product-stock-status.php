<?php

if (!defined('ABSPATH')) {
	exit;
}

class YoOhw_Pre_Orders_WC_Single_Product_Stock_Status {

	public function __construct() {
		add_filter('woocommerce_product_stock_status_options', array($this, 'add_pre_order_stock_status_to_options'));

		add_action('woocommerce_admin_process_product_object', array($this, 'save_pre_order_stock_status'));
		add_filter('woocommerce_product_is_in_stock', array($this, 'product_in_stock_status'), 10, 2);
		add_filter('woocommerce_product_class', array($this, 'set_pre_order_stock_class'), 10, 4);
		add_action('woocommerce_product_options_stock_status', array($this, 'add_pre_order_available_date_field'));
		add_action('woocommerce_process_product_meta', array($this, 'save_pre_order_date'), 99);
		add_action('woocommerce_product_options_general_product_data', array($this, 'add_nonce_field'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));

		// Hooks for variable products
		add_action('woocommerce_variation_options_pricing', array($this, 'add_pre_order_available_date_field_variations'), 10, 3);
		add_action('woocommerce_save_product_variation', array($this, 'save_pre_order_date_variations'), 99, 2);
		add_filter('woocommerce_available_variation', array($this, 'add_pre_order_data_to_variations'));
	}

	public function enqueue_scripts() {
		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || 'product' !== $screen->post_type) {
			return;
		}

		if (!wp_script_is('jquery', 'enqueued')) {
			wp_enqueue_script('jquery');
		}

		wp_enqueue_script('product-stock-status', plugin_dir_url(__FILE__) . '../../js/backend-product-stock-status.js', array('jquery'), YOOHW_PRE_ORDERS_WC_VERSION, true);
	}

	public function add_pre_order_stock_status_to_options($status_options) {
		$status_options['onpreorder'] = esc_html__('On preorder', 'pre-orders-wc');
		return $status_options;
	}

	public function save_pre_order_stock_status($product) {
		// Verify nonce before processing form data
		if (
			isset($_POST['wc_pre_orders_nonce']) &&
			wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wc_pre_orders_nonce'])), 'wc_pre_orders_nonce_action') &&
			isset($_POST['_stock_status'])
		) {
			// Sanitize and set the stock status
			$stock_status = sanitize_text_field(wp_unslash($_POST['_stock_status']));
			$product->set_stock_status($stock_status);
		}
	}
	
	public function product_in_stock_status($in_stock, $product) {
		if ($product->get_stock_status() === 'onpreorder') {
			return true;
		}
		return $in_stock;
	}

	public function set_pre_order_stock_class($classname, $product, $product_type, $post_type) {
		if (is_numeric($product)) {
			$product = wc_get_product($product);
		}
		if ($product instanceof WC_Product && $product->get_stock_status() === 'onpreorder') {
			$classname .= ' product-on-preorder';
		}
		return $classname;
	}

	public function add_pre_order_available_date_field() {
		global $post;
		woocommerce_wp_text_input(
			array(
				'id'          => '_pre_order_available_date',
				'label'       => esc_html__('Available date', 'pre-orders-wc'),
				'placeholder' => 'YYYY-MM-DD HH:MM',
				'type'        => 'datetime-local',  // Change input type to datetime-local
				'value'       => esc_attr(yoohw_pre_orders_wc_get_product_available_date($post->ID)),
				'desc_tip'    => true,
				'description' => esc_html__('The date and time when the product will be available for Pre-order.', 'pre-orders-wc'),
			)
		);
	}

	public function save_pre_order_date($post_id) {
		if (
			isset($_POST['_pre_order_available_date']) &&
			isset($_POST['wc_pre_orders_nonce']) &&
			wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wc_pre_orders_nonce'])), 'wc_pre_orders_nonce_action')
		) {
			// Sanitize and save date with time
			$pre_order_date = yoohw_pre_orders_wc_update_product_available_date($post_id, sanitize_text_field(wp_unslash($_POST['_pre_order_available_date'])));
	
			// Schedule the event for this product when the date is updated
			if (class_exists('YoOhw_Pre_Orders_WC_Auto_Update_To_In_Stock')) {
				$scheduler = new YoOhw_Pre_Orders_WC_Auto_Update_To_In_Stock();
				$scheduler->schedule_event_for_product($post_id);
			}

			// Schedule the event to remind the administrations about the incoming available pre-order product
			if (class_exists('YoOhw_Pre_Orders_WC_Email_Available_Product_Admin')) {
				$scheduler = new YoOhw_Pre_Orders_WC_Email_Available_Product_Admin();
				$scheduler->schedule_event_for_available_product_admin($post_id);
			}
		}
	}
	
	public function add_nonce_field() {
		wp_nonce_field('wc_pre_orders_nonce_action', 'wc_pre_orders_nonce');
	}

	// Add 'Available date' field to variable products
	public function add_pre_order_available_date_field_variations($loop, $variation_data, $variation) {
		woocommerce_wp_text_input(
			array(
				'id'          => "_pre_order_available_date_{$variation->ID}",
				'name'        => "_pre_order_available_date[{$variation->ID}]",
				'label'       => esc_html__('Available date', 'pre-orders-wc'),
				'placeholder' => 'YYYY-MM-DD HH:MM',
				'type'        => 'datetime-local',  // Change input type to datetime-local
				'value'       => esc_attr(yoohw_pre_orders_wc_get_product_available_date($variation->ID)),
				'desc_tip'    => true,
				'description' => esc_html__('The date and time when this variation will be available for Pre-order.', 'pre-orders-wc'),
				'wrapper_class' => 'form-row form-row-full pre-order-available-date-input',
			)
		);
	}

	// Save 'Available date' field value for each variation
	public function save_pre_order_date_variations($variation_id, $i) {
		// Check if the pre-order date for this variation is set
		if (
			isset($_POST['_pre_order_available_date'][$variation_id]) &&
			isset($_POST['wc_pre_orders_nonce']) &&
			wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wc_pre_orders_nonce'])), 'wc_pre_orders_nonce_action')
		) {
			// Log the date being processed for this variation
			$pre_order_date = yoohw_pre_orders_wc_update_product_available_date($variation_id, sanitize_text_field(wp_unslash($_POST['_pre_order_available_date'][$variation_id])));

			if (class_exists('YoOhw_Pre_Orders_WC_Auto_Update_To_In_Stock')) {
				$scheduler = new YoOhw_Pre_Orders_WC_Auto_Update_To_In_Stock();
				$scheduler->schedule_event_for_product($variation_id);
			}

			if (class_exists('YoOhw_Pre_Orders_WC_Email_Available_Product_Admin')) {
				$scheduler = new YoOhw_Pre_Orders_WC_Email_Available_Product_Admin();
				$scheduler->schedule_event_for_available_product_admin($variation_id);
			}
		}
	}

	// Add pre-order data to variations in front-end
	public function add_pre_order_data_to_variations($variation) {
		$variation['_pre_order_available_date'] = yoohw_pre_orders_wc_get_product_available_date($variation['variation_id']);
		$variation['_extended_status_available_date'] = $variation['_pre_order_available_date'];
		return $variation;
	}
}

// Instantiate the class to make sure the hooks are registered
new YoOhw_Pre_Orders_WC_Single_Product_Stock_Status();
