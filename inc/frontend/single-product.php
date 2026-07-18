<?php

if (!defined('ABSPATH')) {
	exit;
}

class YoOhw_Pre_Orders_WC_Single_Product {

	public function __construct() {
		add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
		add_filter('woocommerce_get_availability', array($this, 'display_pre_order_stock_status'), 10, 2);

		add_filter('woocommerce_product_single_add_to_cart_text', array($this, 'change_add_to_cart_button_text'), 10, 2);
		add_filter('woocommerce_available_variation', array($this,  'add_stock_status_to_variation_data'), 10, 3);

		add_action('wp_ajax_get_stock_status', array($this, 'get_stock_status'));
		add_action('wp_ajax_nopriv_get_stock_status', array($this, 'get_stock_status'));
	}

	public function enqueue_scripts() {
		if (!wp_script_is('jquery', 'enqueued')) {
			wp_enqueue_script('jquery');
		}

		wp_enqueue_script('frontend-product-js', plugin_dir_url(__FILE__) . '../../js/frontend-product.js', array('jquery'), '1.1', true);

		wp_localize_script(
			'frontend-product-js',
			'stockStatusPreorderTexts',
			array(
				// 1) Pre‐order button text: use the option, or fallback to a translatable default
				'preorder_text'    => get_option(
					'yoess_pre_order_button_text',
					/* translators: Default “Pre‐order now” if option is empty */
					__( 'Pre‐order now', 'pre-orders-wc' )
				),

				// 2) “Add to cart” label (translatable)
				'add_to_cart_text' => __( 'Add to cart', 'pre-orders-wc' ),
			)
		);
	}

	public function display_pre_order_stock_status($availability, $product) {
		if ($product->get_stock_status() === 'onpreorder') {
			$pre_order_date = yoohw_pre_orders_wc_get_product_available_date($product);
			if ($pre_order_date) {
				/* translators: %s: the available date of the pre-order product */
				$availability['availability'] = sprintf(
					/* translators: %s: the available date of the pre-order product */
					wp_kses_post(__('Available on pre-order<br>This item will be available on <strong>%s</strong>.', 'pre-orders-wc')),
					esc_html(yoohw_pre_orders_wc_format_available_date($pre_order_date))
				);
							
			} else {
				$availability['availability'] = esc_html__('Available on pre-order.', 'pre-orders-wc');
			}
			$availability['class'] = 'onpreorder';
		}
		return $availability;
	}

	public function change_add_to_cart_button_text($text, $product) {
		if ($product->is_type('variable')) {
			return esc_html__('Add to cart', 'pre-orders-wc');
		} elseif ($product->get_stock_status() === 'onpreorder') {
			$preorder_text = get_option( 'yoess_pre_order_button_text', __( 'Pre-order now', 'pre-orders-wc' ) );
			return esc_html( $preorder_text );
		}
	
		return esc_html__('Add to cart', 'pre-orders-wc');
	}
	
	public function get_stock_status() {
		// Check if the required POST data is set
		if (!isset($_POST['variation_id']) || !isset($_POST['nonce'])) {
			wp_send_json_error(array('message' => esc_html__('Invalid request', 'pre-orders-wc')));
		}
	
		// Unslash and sanitize the nonce
		$nonce = sanitize_text_field(wp_unslash($_POST['nonce']));
		if (!wp_verify_nonce($nonce, 'get_stock_status_nonce')) {
			wp_send_json_error(array('message' => esc_html__('Invalid nonce', 'pre-orders-wc')));
		}
	
		// Sanitize and get the variation ID
		$variation_id = intval($_POST['variation_id']);
		$stock_status = get_post_meta($variation_id, '_stock_status', true);
	
		// Return the stock status
		if ($stock_status) {
			wp_send_json_success(array('stock_status' => esc_html($stock_status)));
		} else {
			wp_send_json_error(array('message' => esc_html__('No stock status found', 'pre-orders-wc')));
		}
	}
	
	public function add_stock_status_to_variation_data($variation_data, $product, $variation) {
		// Add stock status to variation data
		$variation_data['stock_status'] = $variation->get_stock_status();
		return $variation_data;
	}	
}

new YoOhw_Pre_Orders_WC_Single_Product();
