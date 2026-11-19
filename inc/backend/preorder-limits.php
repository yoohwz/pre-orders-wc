<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoOhw_Pre_Orders_WC_Preorder_Limits {
	public function __construct() {
		add_action( 'woocommerce_product_options_stock_status', [ $this, 'render_product_field' ], 20 );
		add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_product_field' ], 20 );
		add_action( 'woocommerce_variation_options_pricing', [ $this, 'render_variation_field' ], 20, 3 );
		add_action( 'woocommerce_save_product_variation', [ $this, 'save_variation_field' ], 20, 2 );
		add_filter( 'woocommerce_available_variation', [ $this, 'add_variation_data' ] );
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_cart_quantity' ], 10, 5 );
		add_filter( 'woocommerce_update_cart_validation', [ $this, 'validate_cart_update' ], 10, 4 );
		add_action( 'woocommerce_check_cart_items', [ $this, 'validate_classic_cart' ] );
		add_filter( 'woocommerce_store_api_product_quantity_maximum', [ $this, 'limit_store_api_quantity' ], 10, 3 );
		add_action( 'woocommerce_store_api_cart_errors', [ $this, 'validate_store_api_cart' ], 10, 2 );
	}

	public function render_product_field() {
		global $post;
		if ( ! $post ) {
			return;
		}

		woocommerce_wp_text_input(
			[
				'id'                => '_max_purchase_qty',
				'label'             => __( 'Preorder limit', 'pre-orders-wc' ),
				'description'       => __( 'Limit how many units of this preorder product a customer can place in one order. Enter 0 for no limit.', 'pre-orders-wc' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'value'             => get_post_meta( $post->ID, '_max_purchase_qty', true ),
				'wrapper_class'     => 'pre-order-limit-input',
				'custom_attributes' => [ 'min' => '0', 'step' => '1' ],
			]
		);
	}

	public function save_product_field( $product ) {
		if ( ! $product instanceof WC_Product || ! current_user_can( 'edit_post', $product->get_id() ) || ! isset( $_POST['_max_purchase_qty'] ) ) {
			return;
		}

		$product->update_meta_data( '_max_purchase_qty', absint( wp_unslash( $_POST['_max_purchase_qty'] ) ) );
	}

	public function render_variation_field( $loop, $variation_data, $variation ) {
		unset( $loop, $variation_data );

		woocommerce_wp_text_input(
			[
				'id'                => 'variation_max_purchase_qty_' . $variation->ID,
				'label'             => __( 'Preorder limit', 'pre-orders-wc' ),
				'description'       => __( 'Leave blank to inherit the parent product limit. Enter 0 for no limit.', 'pre-orders-wc' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'value'             => get_post_meta( $variation->ID, '_max_purchase_qty', true ),
				'wrapper_class'     => 'form-row form-row-full pre-order-limit-input',
				'custom_attributes' => [ 'min' => '0', 'step' => '1' ],
			]
		);
	}

	public function save_variation_field( $variation_id, $loop ) {
		unset( $loop );
		$key = 'variation_max_purchase_qty_' . absint( $variation_id );
		if ( ! current_user_can( 'edit_post', $variation_id ) || ! isset( $_POST[ $key ] ) ) {
			return;
		}

		$value = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
		if ( '' === $value ) {
			delete_post_meta( $variation_id, '_max_purchase_qty' );
		} else {
			update_post_meta( $variation_id, '_max_purchase_qty', absint( $value ) );
		}
	}

	public function add_variation_data( $variation ) {
		$variation['_max_purchase_qty'] = $this->get_limit( absint( $variation['variation_id'] ?? 0 ) );

		return $variation;
	}

	public function validate_cart_quantity( $passed, $product_id, $quantity, $variation_id = 0, $variations = [] ) {
		unset( $variations );

		return $this->validate_quantity( $passed, wc_get_product( $variation_id ?: $product_id ), $quantity );
	}

	public function validate_cart_update( $passed, $cart_item_key, $cart_item, $quantity ) {
		return $this->validate_quantity( $passed, $cart_item['data'] ?? null, $quantity, $cart_item_key );
	}

	private function validate_quantity( $passed, $product, $quantity, $excluded_key = '' ) {
		$limit = $this->get_preorder_limit( $product );
		if ( ! $passed || $quantity <= 0 || ! $limit || $this->get_cart_quantity( WC()->cart, $product->get_id(), $excluded_key ) + $quantity <= $limit ) {
			return $passed;
		}

		wc_add_notice( $this->get_limit_message( $limit ), 'error' );

		return false;
	}

	public function limit_store_api_quantity( $maximum, $product, $cart_item = [] ) {
		$limit = $this->get_preorder_limit( $product );
		if ( ! $limit ) {
			return $maximum;
		}

		$other_quantity = ! empty( $cart_item['key'] )
			? $this->get_cart_quantity( WC()->cart, $product->get_id(), $cart_item['key'] )
			: 0;

		return min( $maximum, max( 1, $limit - $other_quantity ) );
	}

	public function validate_classic_cart() {
		foreach ( $this->get_cart_limit_errors( WC()->cart ) as $message ) {
			if ( ! wc_has_notice( $message, 'error' ) ) {
				wc_add_notice( $message, 'error' );
			}
		}
	}

	public function validate_store_api_cart( $errors, $cart ) {
		foreach ( $this->get_cart_limit_errors( $cart ) as $product_id => $message ) {
			$errors->add( 'yoohw_preorder_quantity_' . $product_id, $message );
		}
	}

	private function get_cart_limit_errors( $cart ) {
		$totals   = [];
		$products = [];
		$errors   = [];
		foreach ( $cart ? $cart->get_cart() : [] as $item ) {
			$product = $item['data'] ?? null;
			if ( $product instanceof WC_Product && 'onpreorder' === $product->get_stock_status() ) {
				$id              = $product->get_id();
				$products[ $id ] = $product;
				$totals[ $id ]   = ( $totals[ $id ] ?? 0 ) + $item['quantity'];
			}
		}
		foreach ( $totals as $id => $total ) {
			$limit = $this->get_preorder_limit( $products[ $id ] );
			if ( $limit && $total > $limit ) {
				$errors[ $id ] = $this->get_limit_message( $limit );
			}
		}

		return $errors;
	}

	private function get_cart_quantity( $cart, $product_id, $excluded_key ) {
		$total = 0;
		foreach ( $cart ? $cart->get_cart() : [] as $key => $item ) {
			$item_id = absint( $item['variation_id'] ?: $item['product_id'] );
			if ( $key !== $excluded_key && $item_id === $product_id ) {
				$total += $item['quantity'];
			}
		}

		return $total;
	}

	private function get_preorder_limit( $product ) {
		return $product instanceof WC_Product && 'onpreorder' === $product->get_stock_status() ? $this->get_limit( $product->get_id() ) : 0;
	}

	private function get_limit_message( $limit ) {
		return sprintf(
			/* translators: %d: maximum preorder quantity per order. */
			__( 'You can add a maximum of %d unit(s) of this preorder product to one order.', 'pre-orders-wc' ),
			$limit
		);
	}

	private function get_limit( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return 0;
		}

		$value = get_post_meta( $product_id, '_max_purchase_qty', true );
		if ( '' === (string) $value ) {
			$product = wc_get_product( $product_id );
			if ( $product instanceof WC_Product_Variation ) {
				$value = get_post_meta( $product->get_parent_id(), '_max_purchase_qty', true );
			}
		}

		return absint( $value );
	}
}

new YoOhw_Pre_Orders_WC_Preorder_Limits();
