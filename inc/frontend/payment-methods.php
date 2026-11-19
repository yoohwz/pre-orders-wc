<?php

defined( 'ABSPATH' ) || exit;

class YoOhw_Pre_Orders_WC_Payment_Methods {
	const OPTION = 'yoohw_pre_orders_excluded_gateways';

	public function __construct() {
		add_filter( 'woocommerce_available_payment_gateways', [ $this, 'filter_gateways' ], PHP_INT_MAX );
		add_filter( 'woocommerce_no_available_payment_methods_message', [ $this, 'empty_methods_message' ] );
	}

	public function filter_gateways( $gateways ) {
		$excluded = (array) get_option( self::OPTION, [] );
		if ( $excluded && $this->cart_has_preorder() ) {
			foreach ( $excluded as $gateway_id ) {
				if ( is_string( $gateway_id ) ) {
					unset( $gateways[ $gateway_id ] );
				}
			}
		}

		return $gateways;
	}

	public function empty_methods_message( $message ) {
		if ( get_option( self::OPTION, [] ) && $this->cart_has_preorder() ) {
			return __( 'No payment methods are available for this preorder cart. Please contact the store or remove the preorder items to continue.', 'pre-orders-wc' );
		}
		return $message;
	}

	private function cart_has_preorder() {
		if ( ! WC()->cart || is_checkout_pay_page() || is_add_payment_method_page() || ( is_admin() && ! wp_doing_ajax() ) ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			if ( $product instanceof WC_Product && 'onpreorder' === $product->get_stock_status() ) {
				return true;
			}
		}
		return false;
	}
}

new YoOhw_Pre_Orders_WC_Payment_Methods();
