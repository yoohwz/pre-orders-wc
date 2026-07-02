<?php

if (!defined('ABSPATH')) {
	exit;
}

class YoOhw_Pre_Orders_WC_Email_New_Order_On_Preorder {

    /**
     * Initialize the hooks.
     */
    public function __construct() {
        // Hook into WooCommerce email subject filter for customer emails.
        add_filter('woocommerce_email_subject_customer_processing_order', [$this, 'modify_email_subject'], 10, 2);
        add_filter('woocommerce_email_subject_customer_on_hold_order', [$this, 'modify_email_subject'], 10, 2);
        add_filter('woocommerce_email_subject_customer_completed_order', [$this, 'modify_email_subject'], 10, 2);

        // Hook into WooCommerce email heading filter for customer emails.
        add_filter('woocommerce_email_heading_customer_processing_order', [$this, 'modify_email_heading'], 10, 2);
        add_filter('woocommerce_email_heading_customer_on_hold_order', [$this, 'modify_email_heading'], 10, 2);
        add_filter('woocommerce_email_heading_customer_completed_order', [$this, 'modify_email_heading'], 10, 2);

        // Hook into WooCommerce email content filter before the order table.
        add_action('woocommerce_email_before_order_table', [$this, 'add_preorder_message'], 10, 4);
    }

    /**
     * Modify the email subject if the order has only 'onpreorder' products.
     *
     * @param string   $subject The original email subject.
     * @param WC_Order $order   The WooCommerce order object.
     *
     * @return string Modified email subject.
     */
    public function modify_email_subject($subject, $order) {
        if ($this->order_has_only_on_preorder_products($order)) {
            $subject = __('Your pre-order is confirmed!', 'pre-orders-wc');
        }
        return $subject;
    }

    /**
     * Modify the email heading if the order has only 'onpreorder' products.
     *
     * @param string   $heading The original email heading.
     * @param WC_Order $order   The WooCommerce order object.
     *
     * @return string Modified email heading.
     */
    public function modify_email_heading($heading, $order) {
        if ($this->order_has_only_on_preorder_products($order)) {
            $heading = __('Pre-order confirmation', 'pre-orders-wc');
        }
        return $heading;
    }

    /**
     * Add a custom message before the order table if the order has 'onpreorder' products.
     *
     * @param WC_Order $order         The WooCommerce order object.
     * @param bool     $sent_to_admin Whether the email is being sent to the admin.
     * @param bool     $plain_text    Whether the email is in plain text.
     * @param WC_Email $email         The WooCommerce email object.
     */
    public function add_preorder_message($order, $sent_to_admin, $plain_text, $email = null) {
        if ( ! ( $email instanceof WC_Email ) ) {
            return;
        }

        // Only add the message for customer processing or on-hold order emails.
        if ($email->id !== 'customer_processing_order' && $email->id !== 'customer_on_hold_order' && $email->id !== 'customer_completed_order') {
            return;
        }

        $customer_action = $order->get_meta('_stock_status_customer_action', true);

	        // If all products are on pre-order
	        if ($this->order_has_only_on_preorder_products($order)) {
	            echo '<p>' . esc_html__('This is a pre-order.', 'pre-orders-wc') . ' ';
	        }
	        // If only some products are on pre-order
	        elseif ($this->order_has_some_on_preorder_products($order)) {
	            echo '<p>' . esc_html__('Your order contains pre-order item(s).', 'pre-orders-wc') . ' ';
	        }

        // If the order contains any pre-order products
        if (
            $this->order_has_only_on_preorder_products($order)
        ) { 
	            echo esc_html__('We will notify you when the item(s) are ready, the expected availability date is shown below.', 'pre-orders-wc') . '</p>';
	        } elseif ( $this->order_has_some_on_preorder_products($order) ) {
            if (!empty($customer_action)) {
                $choice_message = ($customer_action === 'all_at_once')
                    ? esc_html__('You have previously requested to receive all items at once. If you change your mind, please contact support for assistance.', 'pre-orders-wc')
                    : esc_html__('You have previously requested to receive items separately. If you change your mind, please contact support for assistance.', 'pre-orders-wc');
                echo ' ' . wp_kses_post($choice_message) . '</p><p>';
	            } elseif ('yes' === get_option('yoess_pre_order_customer_actions', 'no')) {
	                $order_received_url = $order->get_checkout_order_received_url();
	                echo ' ' . wp_kses_post( sprintf(
	                    /* translators: %s: order received URL. */
	                    __('Let us know how you\'d like to receive your order by <a href="%s">choosing a shipping option</a>.', 'pre-orders-wc'),
	                    esc_url($order_received_url)
	                ) ) . '</p><p>';
	            }

	            echo esc_html__('We will notify you when the item(s) are ready, the expected availability date is shown below.', 'pre-orders-wc') . '</p>';            
	        }
    }

    /**
     * Check if the order contains only products with the stock status 'onpreorder'.
     *
     * @param WC_Order $order The WooCommerce order object.
     *
     * @return bool True if all products in the order are 'onpreorder', false otherwise.
     */
    private function order_has_only_on_preorder_products($order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product || $product->get_stock_status() !== 'onpreorder') {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if the order contains some products with the stock status 'onpreorder'.
     *
     * @param WC_Order $order The WooCommerce order object.
     *
     * @return bool True if some products in the order are 'onpreorder', false otherwise.
     */
    private function order_has_some_on_preorder_products($order) {
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_stock_status() === 'onpreorder') {
                return true;
            }
        }
        return false;
    }

    /**
     * Get the list of product names that have the stock status 'onpreorder'.
     *
     * @param WC_Order $order The WooCommerce order object.
     *
     * @return array List of product names.
     */
    private function get_preorder_items_list($order) {
        $preorder_items = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product && $product->get_stock_status() === 'onpreorder') {
                $preorder_items[] = $item->get_name();
            }
        }
        return $preorder_items;
    }
}

// Initialize the class.
new YoOhw_Pre_Orders_WC_Email_New_Order_On_Preorder();
