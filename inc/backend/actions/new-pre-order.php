<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoOhw_Pre_Orders_WC_New_Pre_Order {

	public function __construct() {
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'update_pre_order_meta' ], 10, 4 );
		add_action( 'woocommerce_removed_order_items', [ $this, 'clear_draft_preorders' ], 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', [ $this, 'reconcile_draft_preorders' ] );
	}

	public function clear_draft_preorders( $order, $type ) {
		if ( $order instanceof WC_Order && $order->has_status( 'checkout-draft' ) && ( null === $type || 'line_item' === $type ) ) {
			$this->clear_preorders( $order );
		}
	}

	public function reconcile_draft_preorders( $order ) {
		if ( ! $order instanceof WC_Order || ! $order->has_status( 'checkout-draft' ) ) {
			return;
		}

		$previous_ids = yoohw_pre_orders_wc_get_tracked_product_ids( $order );
		$tracked_ids  = [];
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$id     = $item->get_variation_id() ?: $item->get_product_id();
			$status = $item->get_meta( '_wcess_stock_status', true );
			if ( 'onpreorder' === $status || ( '' === $status && in_array( $id, $previous_ids, true ) ) ) {
				$tracked_ids[] = $id;
			}
		}

		$tracked_ids = array_values( array_unique( $tracked_ids ) );
		$mark        = $tracked_ids ? '1' : '';
		if ( $tracked_ids !== $previous_ids || $mark !== (string) $order->get_meta( '_pre_order_marked', true ) ) {
			$this->set_preorders( $order, $tracked_ids );
			// Store API GET can return the draft without saving it again.
			if ( $order->get_id() ) {
				$order->save_meta_data();
			}
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
		$this->set_preorders( $order, $tracked_ids );
	}

	private function set_preorders( $order, array $tracked_ids ) {
		if ( ! $tracked_ids ) {
			$this->clear_preorders( $order );
			return;
		}

		$order->update_meta_data( '_pre_order_marked', '1' );
		$order->update_meta_data( '_pre_order_items', array_values( array_unique( $tracked_ids ) ) );
	}

	private function clear_preorders( $order ) {
		$order->delete_meta_data( '_pre_order_items' );
		$order->delete_meta_data( '_pre_order_marked' );
	}
}

new YoOhw_Pre_Orders_WC_New_Pre_Order();
