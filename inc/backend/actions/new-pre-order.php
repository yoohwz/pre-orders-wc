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
	        $product = isset( $values['data'] ) ? $values['data'] : null;
	        if ( ! $product || 'onpreorder' !== $product->get_stock_status() ) {
	            return;
	        }

	        $pre_order_items = array_filter( array_map( 'absint', explode( ',', (string) $order->get_meta( '_pre_order_items', true ) ) ) );
	        $pre_order_items[] = absint( $product->get_id() );
	        $pre_order_items = array_unique( $pre_order_items );

	        $order->update_meta_data( '_pre_order_items', implode( ',', $pre_order_items ) );
	    }
	}

// Initialize the class
new YoOhw_Pre_Orders_WC_New_Pre_Order();
