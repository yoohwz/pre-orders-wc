<?php

if (!defined('ABSPATH')) {
	exit;
}

class YoOhw_Pre_Orders_WC_Auto_Update_To_In_Stock {

	public function __construct() {
		add_action('wc_pre_orders_update_stock_status', array($this, 'update_pre_order_stock_status'), 10, 2);
	}

	// Common function to schedule events for both simple products and variations
	public static function schedule_event_for_product($product_id) {
		self::unschedule_event($product_id);
		$product = wc_get_product($product_id);
		if (!$product || 'onpreorder' !== $product->get_stock_status()) {
			return;
		}

		$date = yoohw_pre_orders_wc_parse_available_date(yoohw_pre_orders_wc_get_product_available_date($product));
		if ($date) {
			wp_schedule_single_event(max(time(), $date->getTimestamp()), 'wc_pre_orders_update_stock_status', array($product_id));
		}
	}

	// Unschedule the event if needed (for example, if the date is changed)
	public static function unschedule_event($product_id) {
		wp_clear_scheduled_hook('wc_pre_orders_update_stock_status', array($product_id));
	}

	// Function to update stock status when the scheduled event fires
	public function update_pre_order_stock_status($product_id, $retry_notification = false) {
		$product = wc_get_product($product_id);
		if (!$product) {
			return;
		}

		if ($retry_notification) {
			if ('instock' === $product->get_stock_status()) {
				$this->update_orders_with_pre_order_items($product_id);
			}
			return;
		}

		$date = yoohw_pre_orders_wc_parse_available_date(yoohw_pre_orders_wc_get_product_available_date($product));
		if ('onpreorder' !== $product->get_stock_status() || !$date || $date->getTimestamp() > time()) {
			return;
		}

		$product->set_stock_status('instock');
		$product->save();

		yoohw_pre_orders_wc_update_product_available_date($product_id, '');

		// If it is a variation, let WooCommerce derive the parent status from all children.
		$parent_id = wp_get_post_parent_id($product_id);
		if ($parent_id && class_exists('WC_Product_Variable')) {
			WC_Product_Variable::sync_stock_status($parent_id);
		}

		$retry_args = array($product_id, true);
		if ($this->update_orders_with_pre_order_items($product_id) && !wp_next_scheduled('wc_pre_orders_update_stock_status', $retry_args)) {
			wp_schedule_single_event(time() + 10 * MINUTE_IN_SECONDS, 'wc_pre_orders_update_stock_status', $retry_args);
		}
		do_action('yoohw_pre_orders_wc_auto_updated_to_instock', $product_id);
	}

	private function update_orders_with_pre_order_items($product_id) {
		$retry = false;
		$order_ids = array();
		$page = 1;
		do {
			$query = wc_get_orders(
				array(
					'limit'      => 250,
					'page'       => $page,
					'paginate'   => true,
					'return'     => 'ids',
					'status'     => apply_filters('yoohw_pre_orders_wc_open_order_statuses', array('pending', 'processing', 'on-hold')),
					'meta_query' => array(
						array(
							'key'     => '_pre_order_items',
							'value'   => (string) absint($product_id),
							'compare' => 'LIKE',
						),
					),
				)
			);
			$found_ids = is_object($query) && isset($query->orders) ? (array) $query->orders : (array) $query;
			$max_pages = is_object($query) && isset($query->max_num_pages) ? absint($query->max_num_pages) : 0;
			$order_ids = array_merge($order_ids, array_map('absint', $found_ids));
			$page++;
		} while ($page <= $max_pages);

		foreach (array_values(array_unique($order_ids)) as $order_id) {
			$order = wc_get_order($order_id);
			if (!$order instanceof WC_Order) {
				continue;
			}
			$tracked_ids = yoohw_pre_orders_wc_get_tracked_product_ids($order);
			if (!in_array(absint($product_id), $tracked_ids, true)) {
				continue;
			}
			$has_product = false;
			foreach ($order->get_items() as $item) {
				if ((int) $item->get_product_id() === (int) $product_id || (int) $item->get_variation_id() === (int) $product_id) {
					$has_product = true;
					break;
				}
			}

			$sent = $has_product ? $this->send_pre_order_email($order->get_id()) : null;
			if (false === $sent) {
				$retry = true;
			} else {
				$updated_ids = array_values(array_diff($tracked_ids, array(absint($product_id))));
				if ($updated_ids) {
					$order->update_meta_data('_pre_order_items', $updated_ids);
				} else {
					$order->delete_meta_data('_pre_order_items');
				}
				$order->save();
			}

			if ($has_product) {
				$product = wc_get_product($product_id);
				$product_name = $product ? $product->get_name() : __('Unknown product', 'pre-orders-wc');

				/* translators: %s: product name. */
				$message = __('Pre-order item "%s" is now in stock, and the customer has been notified.', 'pre-orders-wc');
				if (false === $sent) {
					/* translators: %s: product name. */
					$message = __('Pre-order item "%s" is now in stock. The customer notification could not be sent.', 'pre-orders-wc');
				} elseif (null === $sent) {
					/* translators: %s: product name. */
					$message = __('Pre-order item "%s" is now in stock.', 'pre-orders-wc');
				}
				$order->add_order_note(sprintf($message, $product_name));
			}
		}
		return $retry;
	}
	
	/**
	 * Send the pre-order email notification.
	 *
	 * @param int $order_id The order ID.
	 * @return bool|null True on success, false on failure, null when skipped.
	 */
	private function send_pre_order_email($order_id) {
		if (class_exists('WC_Emails')) {
			$emails = WC()->mailer()->get_emails();
	
			if (isset($emails['YoOhw_Pre_Orders_WC_Pre_Order_Email'])) {
				// Trigger the email
				return $emails['YoOhw_Pre_Orders_WC_Pre_Order_Email']->trigger($order_id);
			}
		}
		return null;
	}
	
}

new YoOhw_Pre_Orders_WC_Auto_Update_To_In_Stock();
