<?php

if (!defined('ABSPATH')) {
	exit;
}

class YoOhw_Pre_Orders_WC_Products_Page {

    public function __construct() {
        // Hook into the 'manage_edit-product_columns' filter to modify the product list columns
        add_filter('manage_edit-product_columns', array($this, 'add_custom_stock_status_to_product_list'));

        // Hook into the 'manage_product_posts_custom_column' action to display custom stock status
        add_action('manage_product_posts_custom_column', array($this, 'custom_stock_status_column_content'), 10, 2);
    }

    // Add a custom stock status column to the product list
    public function add_custom_stock_status_to_product_list($columns) {
        // Remove the existing stock column
        unset($columns['is_in_stock']);
        
        // Add the custom stock status column before the price column
        $new_columns = array();
        foreach ($columns as $key => $column) {
            if ('price' === $key) {
                $new_columns['stock_status'] = __('Stock', 'pre-orders-wc');
            }
            $new_columns[$key] = $column;
        }
        return $new_columns;
    }

    // Display custom stock status in the product list
    public function custom_stock_status_column_content($column, $post_id) {
        if ('stock_status' === $column) {
            $product = wc_get_product($post_id);
            $stock_status = $product->get_stock_status();
            $stock_status_label = wc_get_product_stock_status_options()[$stock_status];
            $class = '';

            switch ($stock_status) {
                case 'instock':
                    $class = 'instock';
                    break;
                case 'outofstock':
                    $class = 'outofstock';
                    break;
                case 'onbackorder':
                    $class = 'onbackorder';
                    break;
                case 'onpreorder':
                    $class = 'onpreorder';
                    break;
            }

            // Display the stock status
            echo '<mark class="' . esc_attr($class) . '">' . esc_html($stock_status_label) . '</mark>';

            // If stock status is "onpreorder," display the _pre_order_available_date
            if ($stock_status === 'onpreorder') {
                $pre_order_available_date = yoohw_pre_orders_wc_get_product_available_date($post_id);
                if (!empty($pre_order_available_date)) {
                    echo '<br><small>' . esc_html(yoohw_pre_orders_wc_format_available_date($pre_order_available_date, true)) . '</small>';
                }
            }
        }
    }
}

new YoOhw_Pre_Orders_WC_Products_Page();
