<?php

if (!defined('ABSPATH')) {
	exit;
}

class YoOhw_Pre_Orders_WC_Auto_Update_To_In_Stock {

	public function __construct() {
		add_action('wc_pre_orders_update_stock_status', array($this, 'update_pre_order_stock_status'), 10, 1);
	}

	// Common function to schedule events for both simple products and variations
	public function schedule_event_for_product($product_id) {
		$product = wc_get_product($product_id);
	
		if (!$product) {
			return;
		}
	
		if ($product->get_stock_status() !== 'onpreorder') {
			return;
		}
	
		$pre_order_available_date = $product->get_meta('_pre_order_available_date');
	
		if (empty($pre_order_available_date)) {
			return;
		}
	
		// Convert date to timestamp
		try {
			$datetime = new DateTime($pre_order_available_date, new DateTimeZone(wp_timezone_string()));
			$timestamp_site = $datetime->getTimestamp();
		} catch (Exception $e) {
			return;
		}
	
		// Get current timestamp
		$current_timestamp = (new DateTime('now', new DateTimeZone(wp_timezone_string())))->getTimestamp();
	
		// Unschedule any existing event
		$this->unschedule_event($product_id);
	
		// Schedule the event
		if ($timestamp_site > $current_timestamp) {
			wp_schedule_single_event($timestamp_site, 'wc_pre_orders_update_stock_status', array($product_id));
		}
	}

	// Unschedule the event if needed (for example, if the date is changed)
	public function unschedule_event($product_id) {
		$timestamp = wp_next_scheduled('wc_pre_orders_update_stock_status', array($product_id));
		if ($timestamp) {
			wp_unschedule_event($timestamp, 'wc_pre_orders_update_stock_status', array($product_id));
		}
	}

	// Function to update stock status when the scheduled event fires
	public function update_pre_order_stock_status($product_id) {
		$product = wc_get_product($product_id);

		// Check if product exists and is still on pre-order status
		if ($product && $product->get_stock_status() === 'onpreorder') {
			// Update stock status to 'instock'
			$product->set_stock_status('instock');
			$product->save();

			// Delete the _pre_order_available_date meta after stock status changes to "instock"
			delete_post_meta($product_id, '_pre_order_available_date');

			// If it's a variation, update the parent product too
			$parent_id = wp_get_post_parent_id($product_id);
			if ($parent_id) {
				$parent_product = wc_get_product($parent_id);
				if ($parent_product) {
					$parent_product->set_stock_status('instock');
					$parent_product->save();
				}
				}

				$this->update_orders_with_pre_order_items($product_id);
				do_action('yoohw_pre_orders_wc_auto_updated_to_instock', $product_id);
			}
		}

	private function update_orders_with_pre_order_items($product_id) {
		$args = array(
			'limit'        => -1,
			'return'       => 'ids',
			'meta_query'   => array(
				array(
					'key'     => '_pre_order_items',
					'value'   => $product_id,
					'compare' => 'LIKE',
				),
			),
		);

		$order_ids = wc_get_orders($args);

		if (empty($order_ids)) {
			return;
		}

		foreach ($order_ids as $order_id) {
			$pre_order_items = get_post_meta($order_id, '_pre_order_items', true);
			$items_array = explode(',', $pre_order_items);

			$updated_items_array = array_filter($items_array, function ($item) use ($product_id) {
				return trim($item) != $product_id;
			});

			if (!empty($updated_items_array)) {
				update_post_meta($order_id, '_pre_order_items', implode(',', $updated_items_array));
			} else {
				delete_post_meta($order_id, '_pre_order_items');
			}

			$order = wc_get_order($order_id);
			if ($order) {
					$has_product = false;
					foreach ($order->get_items() as $item) {
						if ((int) $item->get_product_id() === (int) $product_id || (int) $item->get_variation_id() === (int) $product_id) {
							$has_product = true;
							break;
						}
					}

				if ($has_product) {
					$this->send_pre_order_email($order_id, $product_id);

					$product = wc_get_product($product_id);
					$product_name = $product ? $product->get_name() : __('Unknown product', 'pre-orders-wc');

					$note = sprintf(
						/* translators: %s: product name. */
						__('Pre-order item "%s" is now in stock, and the customer has been notified.', 'pre-orders-wc'),
						$product_name
					);
					$order->add_order_note($note);
				}
			}
		}
	}
	
	/**
	 * Send the pre-order email notification.
	 *
	 * @param int $order_id The order ID.
	 * @param int $product_id The product ID that became available.
	 */
	private function send_pre_order_email($order_id, $product_id) {
		if (class_exists('WC_Emails')) {
			$emails = WC()->mailer()->get_emails();
	
			if (isset($emails['YoOhw_Pre_Orders_WC_Pre_Order_Email'])) {
				// Trigger the email
				$emails['YoOhw_Pre_Orders_WC_Pre_Order_Email']->trigger($order_id);
			}
		}
	}
	
}

new YoOhw_Pre_Orders_WC_Auto_Update_To_In_Stock();
