<?php

if (!defined('ABSPATH')) {
	exit;
}

class YoOhw_Pre_Orders_WC_Email_Available_Product_Admin {

    public function __construct() {
        // Add hooks
        add_action('wc_pre_orders_email_admin_preorder_availability', array($this, 'notify_admin_preorder_availability_handler'));
    }

    public function schedule_event_for_available_product_admin($product_id) {
		if (get_option('yoess_pre_order_email_to_admin', 'no') !== 'yes') {
            return;
        }
		
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

        // Subtract 7 days (7 * 24 * 60 * 60 seconds)
        $days_before = get_option('yoess_pre_order_email_to_admin_timer', 7);

        // Subtract the specified number of days
        $reminder_timestamp = $timestamp_site - ($days_before * 24 * 60 * 60);

        // Get current timestamp
        $current_timestamp = (new DateTime('now', new DateTimeZone(wp_timezone_string())))->getTimestamp();

        // Unschedule any existing event
        $this->unschedule_event($product_id);

        // Schedule the event if the reminder timestamp is in the future
        if ($reminder_timestamp > $current_timestamp) {
            wp_schedule_single_event($reminder_timestamp, 'wc_pre_orders_email_admin_preorder_availability', array($product_id));
        }
    }

    public function unschedule_event($product_id) {
        $timestamp = wp_next_scheduled('wc_pre_orders_email_admin_preorder_availability', array($product_id));

        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wc_pre_orders_email_admin_preorder_availability', array($product_id));
        }
    }

	public function notify_admin_preorder_availability_handler($product_id) {
		$product = wc_get_product($product_id);
	
		if ($product) {
			$admin_email = get_option('admin_email');
			$product_name = $product->get_name();
            $days_before = get_option('yoess_pre_order_email_to_admin_timer', 7);
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
			wp_mail(
				$admin_email,
				__('A pre-order product is going to be available soon', 'pre-orders-wc'),
				$styled_message,
				$headers
			);
		}
	}	
}

// Initialize the class
new YoOhw_Pre_Orders_WC_Email_Available_Product_Admin();
