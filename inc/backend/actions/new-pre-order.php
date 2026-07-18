<?php

if (!defined('ABSPATH')) {
	exit;
}

class YoOhw_Pre_Orders_WC_New_Pre_Order {

    /**
     * Constructor
     */
	    public function __construct() {
	        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'update_pre_order_meta' ), 10, 4 );
	    }

	    /**
	     * Update the '_pre_order_items' order meta.
	     *
	     * @param WC_Order_Item_Product $item          Order item object.
	     * @param string                $cart_item_key Cart item key.
	     * @param array                 $values        Cart item values.
	     * @param WC_Order              $order         The order object.
	     */
		    public function update_pre_order_meta( $item, $cart_item_key, $values, $order ) {
		        unset( $cart_item_key );

		        $product = isset( $values['data'] ) ? $values['data'] : null;
		        if ( ! $product instanceof WC_Product || 'onpreorder' !== $product->get_stock_status() ) {
		            return;
		        }

		        $product_id = $product->get_id();
		        $item->add_meta_data( '_wcess_stock_status', 'onpreorder', true );
		        $available_date = yoohw_pre_orders_wc_get_product_available_date( $product );
		        if ( '' !== $available_date ) {
		            $item->add_meta_data( '_wcess_available_date', $available_date, true );
		        }

		        $pre_order_items   = yoohw_pre_orders_wc_get_tracked_product_ids( $order );
		        $pre_order_items[] = absint( $product_id );
		        $order->update_meta_data( '_pre_order_marked', '1' );
		        $order->update_meta_data( '_pre_order_items', array_values( array_unique( array_filter( $pre_order_items ) ) ) );
		    }
	}

// Initialize the class
new YoOhw_Pre_Orders_WC_New_Pre_Order();
