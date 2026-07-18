<?php

if (!defined('ABSPATH')) {
	exit;
}

class YoOhw_Pre_Orders_WC_Frontend {

	public function __construct() {
		add_action('wp_enqueue_scripts', [$this, 'frontend_enqueue_scripts']);
		$this->includes();
	}

	public function frontend_enqueue_scripts() {
		if (is_cart() || is_checkout() || is_account_page()) {
			wp_enqueue_style('pre-orders-frontend-css', plugin_dir_url(__FILE__) . '../../css/frontend.css', array(), YOOHW_PRE_ORDERS_WC_VERSION);

			$custom_css = "
				.stock-status-customer-actions p::before, .stock-status-customer-notice p::before {
					content: '';
					display: inline-block;
					width: 24px;
					height: 24px;
					background-image: url('" . plugin_dir_url( __FILE__ ) . "../../img/shipping.svg');
					background-size: contain;
					background-repeat: no-repeat;
					background-position: center;
					margin-right: 8px;
					vertical-align: middle;
				}
			";

			wp_add_inline_style( 'pre-orders-frontend-css', $custom_css );	
		}
	}

	public function includes() {
		include_once plugin_dir_path(__FILE__) . '../frontend/single-product.php';
		include_once plugin_dir_path(__FILE__) . '../frontend/product-list.php';
		include_once plugin_dir_path(__FILE__) . '../frontend/cart.php';
		include_once plugin_dir_path(__FILE__) . '../frontend/order-table.php';
		include_once plugin_dir_path(__FILE__) . '../frontend/customer-actions.php';
		include_once plugin_dir_path(__FILE__) . '../frontend/customer-actions-blocks.php';
	}
}

new YoOhw_Pre_Orders_WC_Frontend();
