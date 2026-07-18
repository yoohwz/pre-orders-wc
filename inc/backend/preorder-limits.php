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
		$target_id = $variation_id ? absint( $variation_id ) : absint( $product_id );
		$product   = wc_get_product( $target_id );
		if ( ! $product || 'onpreorder' !== $product->get_stock_status() ) {
			return $passed;
		}

		$limit = $this->get_limit( $target_id );
		if ( $limit < 1 ) {
			return $passed;
		}

		$cart_quantity = 0;
		if ( WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				$item_id = ! empty( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : absint( $cart_item['product_id'] ?? 0 );
				if ( $item_id === $target_id ) {
					$cart_quantity += absint( $cart_item['quantity'] ?? 0 );
				}
			}
		}

		if ( $cart_quantity + absint( $quantity ) <= $limit ) {
			return $passed;
		}

		wc_add_notice(
			sprintf(
				/* translators: %d: maximum preorder quantity per order. */
				__( 'You can add a maximum of %d unit(s) of this preorder product to one order.', 'pre-orders-wc' ),
				$limit
			),
			'error'
		);

		return false;
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
