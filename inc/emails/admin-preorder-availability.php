<?php

if (!defined('ABSPATH')) {
	exit;
}

class YoOhw_Pre_Orders_WC_Email_Available_Product_Admin {

    public function __construct() {
        // Add hooks
        add_action('wc_pre_orders_email_admin_preorder_availability', array(__CLASS__, 'notify_admin_preorder_availability_handler'));
    }

	public static function schedule_event_for_available_product_admin($product_id) {
		self::unschedule_event($product_id);
		$timestamp = self::get_reminder_timestamp(wc_get_product($product_id));
		if ($timestamp > time()) {
			wp_schedule_single_event($timestamp, 'wc_pre_orders_email_admin_preorder_availability', array($product_id));
		}
    }

	public static function unschedule_event($product_id) {
		wp_clear_scheduled_hook('wc_pre_orders_email_admin_preorder_availability', array($product_id));
    }

	private static function get_reminder_timestamp($product) {
		if (!$product || 'onpreorder' !== $product->get_stock_status() || 'yes' !== get_option('yoess_pre_order_email_to_admin', 'no')) {
			return null;
		}

		$date = yoohw_pre_orders_wc_parse_available_date(yoohw_pre_orders_wc_get_product_available_date($product));
		$days_before = absint(get_option('yoess_pre_order_email_to_admin_timer', 3));
		return $date ? $date->getTimestamp() - $days_before * DAY_IN_SECONDS : null;
	}

	public static function notify_admin_preorder_availability_handler($product_id) {
		$product = wc_get_product($product_id);
	
		$timestamp = self::get_reminder_timestamp($product);
		if (null !== $timestamp && $timestamp <= time()) {
			$admin_email = (string) get_option('woocommerce_stock_email_recipient', get_option('admin_email'));
			$product_name = $product->get_name();
            $days_before = absint(get_option('yoess_pre_order_email_to_admin_timer', 3));
			$confirmation_link = admin_url('post.php?post=' . $product_id . '&action=edit');
	
			// Generate the email content using WooCommerce methods
			$mailer = WC()->mailer();
			$heading = __('Pre-order product reminder', 'pre-orders-wc');
	
			$message = sprintf(
				/* translators: 1: product name, 2: days before availability, 3: product edit link. */
				__(
					'Hi there,<br><br>The product "<strong>%1$s</strong>" is scheduled to become available in %2$d days.<br><br>You can review the product here:<br><br><a href="%3$s">Go to Edit Product page</a><br><br>Thank you.',
					'pre-orders-wc'
				),
				esc_html($product_name),
                $days_before,
				esc_url($confirmation_link)
			);
	
			// Wrap the message in WooCommerce's email template
			$wrapped_message = $mailer->wrap_message($heading, $message);
	
			// Inline the styles
			$email = new WC_Email();
			$styled_message = $email->style_inline($wrapped_message);
	
			// Email headers
			$headers = array('Content-Type: text/html; charset=UTF-8');
	
			// Send the email
			$mailer->send(
				$admin_email,
				__('A pre-order product is going to be available soon', 'pre-orders-wc'),
				$styled_message,
				$headers,
				array()
			);
		}
	}	
}

// Initialize the class
new YoOhw_Pre_Orders_WC_Email_Available_Product_Admin();
