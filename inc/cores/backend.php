<?php

if (!defined('ABSPATH')) {
	exit;
}

class YoOhw_Pre_Orders_WC_Backend {
	private $version;

	public function __construct() {
		$this->version = YOOHW_PRE_ORDERS_WC_VERSION;

		add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
		add_filter('woocommerce_email_classes', [$this, 'add_email_classes']);
		add_action('admin_init', [$this, 'check_version']);

		$this->includes();
	}

	public function enqueue_scripts() {
		wp_enqueue_style('pre-orders-css', plugin_dir_url(__FILE__) . '../../css/backend.css', array(), YOOHW_PRE_ORDERS_WC_VERSION);
	}

	public function add_email_classes($email_classes) {
		include_once plugin_dir_path(__FILE__) . '../emails/settings-pre-orders.php';
		$email_classes['YoOhw_Pre_Orders_WC_Pre_Order_Email'] = new YoOhw_Pre_Orders_WC_Pre_Order_Email();

		return $email_classes;
	}

	public function check_version() {
		if ( get_option('wc_pre_orders_version') !== $this->version && class_exists( 'YoOhw_Pre_Orders_WC_Push_Subscription' ) ) {
			YoOhw_Pre_Orders_WC_Push_Subscription::push_subscription();
		}

		update_option('wc_pre_orders_version', $this->version);
	}

	public function includes() {
		include_once plugin_dir_path(__FILE__) . '../backend/settings.php';
		include_once plugin_dir_path(__FILE__) . '../backend/product-stock-status.php';
		include_once plugin_dir_path(__FILE__) . '../backend/preorder-limits.php';
		include_once plugin_dir_path(__FILE__) . '../backend/products.php';
		include_once plugin_dir_path(__FILE__) . '../backend/edit-order.php';
		include_once plugin_dir_path(__FILE__) . '../backend/upsell-events.php';
		include_once plugin_dir_path(__FILE__) . '../backend/preorder-overview.php';
		include_once plugin_dir_path(__FILE__) . '../backend/actions/auto-update-to-instock.php';
		include_once plugin_dir_path(__FILE__) . '../backend/actions/new-pre-order.php';
		include_once plugin_dir_path(__FILE__) . '../emails/customer-new-order.php';
		include_once plugin_dir_path(__FILE__) . '../emails/admin-preorder-availability.php';
		include_once plugin_dir_path(__FILE__) . 'api/push-subscription.php';
	}
}

new YoOhw_Pre_Orders_WC_Backend();
