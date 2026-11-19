<?php

if (!defined('ABSPATH')) {
	exit;
}

class YoOhw_Pre_Orders_WC_Auto_Update_To_In_Stock {
	const MODE_OPTION = 'yoohw_pre_orders_completion_mode';
	const RECONCILE_HOOK = 'yoohw_pre_orders_reconcile_auto_schedule';

	public function __construct() {
		add_action('wc_pre_orders_update_stock_status', array($this, 'update_pre_order_stock_status'), 10, 2);
		add_action('update_option_' . self::MODE_OPTION, array(__CLASS__, 'completion_mode_changed'), 10, 2);
		add_action(self::RECONCILE_HOOK, array(__CLASS__, 'reconcile_schedules'));
	}

	public static function is_manual() {
		return 'manual' === get_option(self::MODE_OPTION, 'auto_by_date');
	}

	public static function completion_mode_changed($previous, $current) {
		if ('manual' === $previous && 'auto_by_date' === $current && !wp_next_scheduled(self::RECONCILE_HOOK, array(0))) {
			wp_schedule_single_event(time(), self::RECONCILE_HOOK, array(0));
		}
	}

	public static function reconcile_schedules($after_id) {
		if (self::is_manual()) {
			return;
		}

		global $wpdb;
		// Use an ID cursor because completing an overdue product removes it from this set.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the current batch of eligible product IDs.
		$product_ids = $wpdb->get_col($wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} stock ON stock.post_id = p.ID AND stock.meta_key = '_stock_status' AND stock.meta_value = 'onpreorder'
			 WHERE p.ID > %d AND p.post_type IN ('product', 'product_variation') AND p.post_status NOT IN ('trash', 'auto-draft')
			 ORDER BY p.ID LIMIT 100",
			$after_id
		));

		foreach ($product_ids as $product_id) {
			self::schedule_event_for_product((int) $product_id);
		}
		if (100 === count($product_ids)) {
			wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::RECONCILE_HOOK, array((int) end($product_ids)));
		}
	}

	// Common function to schedule events for both simple products and variations
	public static function schedule_event_for_product($product_id) {
		self::unschedule_event($product_id);
		if (self::is_manual()) {
			return;
		}

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

	public function update_pre_order_stock_status($product_id, $retry_notification = false) {
		if (true !== $retry_notification && self::is_manual()) {
			return;
		}
		$result = self::run_completion($product_id, false, true === $retry_notification);
		if (is_wp_error($result) && 'preorder_completion_busy' === $result->get_error_code()) {
			wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'wc_pre_orders_update_stock_status', true === $retry_notification ? array($product_id, true) : array($product_id));
		}
	}

	public static function can_confirm($product) {
		return self::is_manual() && $product instanceof WC_Product &&
			'onpreorder' === $product->get_stock_status() &&
			(!$product->is_type('variable') || $product->get_manage_stock('edit')) &&
			current_user_can('manage_woocommerce') && current_user_can('edit_post', $product->get_id()) &&
			(!$product->is_type('variation') || current_user_can('edit_post', $product->get_parent_id()));
	}

	public static function confirm_available($product_id) {
		return self::run_completion($product_id, true);
	}

	private static function run_completion($product_id, $manual, $retry_notification = false) {
		if ($manual && (!self::is_manual() || !current_user_can('manage_woocommerce') || !current_user_can('edit_post', $product_id))) {
			return new WP_Error('preorder_confirmation_unavailable', __('This preorder can no longer be confirmed. Check the completion mode and product status.', 'pre-orders-wc'));
		}

		global $wpdb;
		// A site-wide lock also serializes two products updating one order's tracking.
		$lock = 'yoohw_po_' . md5(DB_NAME . ':' . $wpdb->prefix);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A connection-owned MySQL/MariaDB advisory lock.
		if ('1' !== (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock))) {
			return new WP_Error('preorder_completion_busy', __('Another preorder is being completed. Please try again shortly.', 'pre-orders-wc'));
		}

		try {
			$product = wc_get_product($product_id);
			if (!$product) {
				return false;
			}

			if ($retry_notification) {
				if ('instock' === $product->get_stock_status()) {
					self::update_orders_with_pre_order_items($product_id);
				}
				return false;
			}

			if ($manual) {
				if (!self::can_confirm($product)) {
					return new WP_Error('preorder_confirmation_unavailable', __('This preorder can no longer be confirmed. Check the completion mode and product status.', 'pre-orders-wc'));
				}
			} elseif (!self::is_due($product)) {
				return false;
			}

			return self::complete_product($product, $manual);
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release this connection's lock even on failure.
			$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
		}
	}

	private static function is_due($product) {
		if (self::is_manual() || !$product || 'onpreorder' !== $product->get_stock_status()) {
			return false;
		}
		$date = yoohw_pre_orders_wc_parse_available_date(yoohw_pre_orders_wc_get_product_available_date($product));
		return $date && $date->getTimestamp() <= time();
	}

	private static function complete_product($product, $manual) {
		$product_id = $product->get_id();
		// Older schedules may run the stock event before the same-day reminder.
		$reminder_hook = 'wc_pre_orders_email_admin_preorder_availability';
		$reminder_time = wp_next_scheduled($reminder_hook, array($product_id));
		if (!$manual && $reminder_time && $reminder_time <= time() && 0 === absint(get_option('yoess_pre_order_email_to_admin_timer', 3))) {
			wp_clear_scheduled_hook($reminder_hook, array($product_id));
			YoOhw_Pre_Orders_WC_Email_Available_Product_Admin::notify_admin_preorder_availability_handler($product_id);
			// A mail transport or hook can run while an administrator changes the schedule.
			clean_post_cache($product_id);
			wp_cache_delete('alloptions', 'options');
			wp_cache_delete(self::MODE_OPTION, 'options');
			$product = wc_get_product($product_id);
			if (!self::is_due($product)) {
				return false;
			}
		}

		if ($product->get_manage_stock() && (int) $product->get_stock_quantity() <= absint(get_option('woocommerce_notify_no_stock_amount', 0))) {
			return new WP_Error('preorder_stock_insufficient', __('WooCommerce stock rules prevent confirming this preorder as in stock.', 'pre-orders-wc'));
		}

		$retry_args = array($product_id, true);
		$stock_hook = 'wc_pre_orders_update_stock_status';
		// Arm recovery before committing the status, so an interrupted mail loop can resume.
		if (!wp_next_scheduled($stock_hook, $retry_args) &&
			!wp_schedule_single_event(time() + 10 * MINUTE_IN_SECONDS, $stock_hook, $retry_args)
		) {
			return new WP_Error('preorder_recovery_not_scheduled', __('The preorder could not be completed because its notification retry could not be scheduled.', 'pre-orders-wc'));
		}

		$product->set_stock_status('instock');
		$product->save();
		$stored = wc_get_product($product_id);
		if (!$stored || 'instock' !== $stored->get_stock_status('edit')) {
			return new WP_Error('preorder_stock_not_updated', __('WooCommerce did not mark this product as in stock.', 'pre-orders-wc'));
		}

		self::unschedule_event($product_id);
		YoOhw_Pre_Orders_WC_Email_Available_Product_Admin::unschedule_event($product_id);
		yoohw_pre_orders_wc_update_product_available_date($product_id, '');

		// If it is a variation, let WooCommerce derive the parent status from all children.
		$parent_id = wp_get_post_parent_id($product_id);
		if ($parent_id && class_exists('WC_Product_Variable')) {
			WC_Product_Variable::sync_stock_status($parent_id);
		}

		try {
			if (!self::update_orders_with_pre_order_items($product_id)) {
				wp_clear_scheduled_hook($stock_hook, $retry_args);
			}
		} catch (Throwable $exception) {
			wc_get_logger()->warning(
				'Preorder notification processing was interrupted. The scheduled retry remains active.',
				array('source' => 'pre-orders-wc', 'product_id' => $product_id)
			);
		}
		if (!$manual) {
			do_action('yoohw_pre_orders_wc_auto_updated_to_instock', $product_id);
		}
		return true;
	}

	private static function update_orders_with_pre_order_items($product_id) {
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
					$item_product_id = $item->get_variation_id() ?: $item->get_product_id();
					if ((int) $item_product_id === (int) $product_id && !$item->meta_exists('_wcess_stock_status')) {
						$item->add_meta_data('_wcess_stock_status', 'onpreorder', true);
						$item->save_meta_data();
					}
				}
			}

			$sent = $has_product ? self::send_pre_order_email($order->get_id()) : null;
			if (false === $sent) {
				$retry = true;
			} else {
				// A mail hook can update the same order while the notification is being sent.
				$order->read_meta_data(true);
				$tracked_ids = yoohw_pre_orders_wc_get_tracked_product_ids($order);
				// Older checkouts stored only the tracked IDs.
				if ($has_product) {
					$order->update_meta_data('_pre_order_marked', '1');
				}
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
	private static function send_pre_order_email($order_id) {
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
