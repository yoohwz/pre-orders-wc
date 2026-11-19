<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoOhw_Pre_Orders_WC_New_Pre_Order {

	public function __construct() {
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'update_pre_order_meta' ], 10, 4 );
		add_action( 'woocommerce_removed_order_items', [ $this, 'clear_draft_preorders' ], 10, 2 );
	}

	public function clear_draft_preorders( $order, $type ) {
		if ( $order instanceof WC_Order && $order->has_status( 'checkout-draft' ) && ( null === $type || 'line_item' === $type ) ) {
			$this->clear_preorders( $order );
		}
	}

	public function update_pre_order_meta( $item, $cart_item_key, $values, $order ) {
		if ( ! $order->get_items( 'line_item' ) ) {
			$this->clear_preorders( $order );
		}

		$product = $values['data'] ?? null;
		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$status = $product->get_stock_status();
		$item->add_meta_data( '_wcess_stock_status', $status, true );
		if ( 'onpreorder' !== $status ) {
			return;
		}

		$item->add_meta_data( '_wcess_available_date', yoohw_pre_orders_wc_get_product_available_date( $product ), true );

		$tracked_ids   = yoohw_pre_orders_wc_get_tracked_product_ids( $order );
		$tracked_ids[] = $product->get_id();
		$order->update_meta_data( '_pre_order_marked', '1' );
		$order->update_meta_data( '_pre_order_items', array_values( array_unique( $tracked_ids ) ) );
	}

	private function clear_preorders( $order ) {
		$order->delete_meta_data( '_pre_order_items' );
		$order->delete_meta_data( '_pre_order_marked' );
	}
}

new YoOhw_Pre_Orders_WC_New_Pre_Order();
