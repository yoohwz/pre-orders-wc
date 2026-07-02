<?php
/**
 * Plugin Name: Pre-Orders for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/pre-orders-wc/
 * Description: Add pre-order stock status, availability dates, and customer delivery preferences to WooCommerce products.
 * Version: 1.2.0
 * Author: YoOhw.com
 * Author URI: https://yoohw.com
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Text Domain: pre-orders-wc
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
	exit;
}

if (!defined('YOOHW_PRE_ORDERS_WC_PREMIUM_URL')) {
	define('YOOHW_PRE_ORDERS_WC_PREMIUM_URL', 'https://yoohw.com/product/woocommerce-extended-stock-status/');
}

class YoOhw_Pre_Orders_WC {

	public function __construct() {
		$wcpo_plugin_data = get_file_data(__FILE__, ['Version' => 'Version'], false);
		$wcpo_plugin_version = isset($wcpo_plugin_data['Version']) ? $wcpo_plugin_data['Version'] : '';
		if (!defined('YOOHW_PRE_ORDERS_WC_VERSION')) {
			define('YOOHW_PRE_ORDERS_WC_VERSION', $wcpo_plugin_version);
		}

		add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_action_links']);

		add_action('plugins_loaded', [$this, 'init'], 20);
	}

	public function init() {
		if (!$this->has_woocommerce()) {
			add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
			return;
		}

		$this->includes();
	}

	public function has_woocommerce() {
		return class_exists('WooCommerce') && function_exists('WC');
	}

	public function add_action_links($links) {
		$settings_link = '<a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=products&section=stock_status')) . '">' . esc_html__( 'Settings', 'pre-orders-wc' ) . '</a>';
		array_unshift($links, $settings_link);
		return $links;
	}

	public function woocommerce_missing_notice() {
		if (!current_user_can('activate_plugins')) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__('Pre-Orders for WooCommerce requires WooCommerce to be installed and active.', 'pre-orders-wc');
		echo '</p></div>';
	}

	public function includes() {
		include_once plugin_dir_path(__FILE__) . 'inc/cores/notices.php';
		include_once plugin_dir_path(__FILE__) . 'inc/cores/backend.php';
		include_once plugin_dir_path(__FILE__) . 'inc/cores/frontend.php';
	}

	public static function deactivate() {
		$crons = _get_cron_array();
		if (empty($crons) || !is_array($crons)) {
			return;
		}

		$hooks = [
			'wc_pre_orders_update_stock_status',
			'wc_pre_orders_email_admin_preorder_availability',
		];

		foreach ($crons as $timestamp => $cron) {
			foreach ($hooks as $hook) {
				if (empty($cron[$hook])) {
					continue;
				}

				foreach ($cron[$hook] as $event) {
					wp_unschedule_event($timestamp, $hook, $event['args']);
				}
			}
		}
	}
}

new YoOhw_Pre_Orders_WC();
register_deactivation_hook(__FILE__, ['YoOhw_Pre_Orders_WC', 'deactivate']);
