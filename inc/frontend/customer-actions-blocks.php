<?php

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	function() {
		wp_register_script(
			'yoohw-pre-orders-customer-actions-blocks',
			plugins_url( 'js/customer-actions-blocks.js', dirname( __DIR__, 2 ) . '/pre-orders-wc.php' ),
			[ 'wp-data', 'wp-element', 'wp-i18n' ],
			defined( 'YOOHW_PRE_ORDERS_WC_VERSION' ) ? YOOHW_PRE_ORDERS_WC_VERSION : '1.3.0',
			true
		);

		wp_localize_script(
			'yoohw-pre-orders-customer-actions-blocks',
			'yoohwPreOrdersBlockActions',
			[
				'i18n' => [
					'invalid_action'     => __( 'Invalid action. Please try again.', 'pre-orders-wc' ),
					'confirm_all'        => __( 'Got it! We’ll send all items at once when your order is placed.', 'pre-orders-wc' ),
					'confirm_separately' => __( 'Got it! We’ll ship items separately when your order is placed.', 'pre-orders-wc' ),
				],
			]
		);
	}
);

add_action(
	'woocommerce_blocks_loaded',
	function() {
		if ( ! interface_exists( '\\Automattic\\WooCommerce\\Blocks\\Integrations\\IntegrationInterface' ) ) {
			return;
		}

		class YoOhw_Pre_Orders_WC_Customer_Actions_Blocks implements \Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface {
			public function get_name() {
				return 'yoohw-pre-orders-customer-actions';
			}

			public function initialize() {}

			public function get_script_handles() {
				return 'yes' === get_option( 'yoess_pre_order_customer_actions', 'no' ) ? [ 'yoohw-pre-orders-customer-actions-blocks' ] : [];
			}

			public function get_editor_script_handles() {
				return [];
			}

			public function get_script_data() {
				return [];
			}

			public function get_editor_script_data() {
				return [];
			}
		}

		add_action(
			'woocommerce_blocks_checkout_block_registration',
			function( \Automattic\WooCommerce\Blocks\Integrations\IntegrationRegistry $registry ) {
				$registry->register( new YoOhw_Pre_Orders_WC_Customer_Actions_Blocks() );
			}
		);
	}
);

add_action(
	'woocommerce_store_api_init',
	function() {
		if (
			'yes' !== get_option( 'yoess_pre_order_customer_actions', 'no' ) ||
			! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ||
			! class_exists( '\\Automattic\\WooCommerce\\StoreApi\\Schemas\\V1\\CheckoutSchema' )
		) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			[
				'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema::IDENTIFIER,
				'namespace'       => 'yoohw-pre-orders',
				'schema_callback' => function() {
					return [
						'stock_status_customer_action' => [
							'description' => __( 'Customer preorder delivery preference.', 'pre-orders-wc' ),
							'type'        => [ 'string', 'null' ],
							'readonly'    => false,
						],
					];
				},
				'data_callback'   => function() {
					return [ 'stock_status_customer_action' => null ];
				},
				'schema_type'     => ARRAY_A,
			]
		);
	}
);

add_action(
	'woocommerce_store_api_checkout_update_order_from_request',
	function( $order, $request ) {
		if ( ! $order instanceof WC_Order || 'yes' !== get_option( 'yoess_pre_order_customer_actions', 'no' ) ) {
			return;
		}

		$extensions  = (array) $request->get_param( 'extensions' );
		$action_data = isset( $extensions['yoohw-pre-orders'] ) ? (array) $extensions['yoohw-pre-orders'] : [];
		$action_type = isset( $action_data['stock_status_customer_action'] ) ? sanitize_key( $action_data['stock_status_customer_action'] ) : '';
		if ( '' === $action_type && isset( $_COOKIE['yoohw_pre_orders_delivery_preference'] ) ) {
			$action_type = sanitize_key( wp_unslash( $_COOKIE['yoohw_pre_orders_delivery_preference'] ) );
		}

		if ( ! in_array( $action_type, [ 'all_at_once', 'separately' ], true ) || ! yoohw_pre_orders_wc_order_is_mixed( $order ) ) {
			return;
		}

		YoOhw_Pre_Orders_WC_Customer_Actions::apply_customer_action( $order, $action_type );
		if ( isset( $_COOKIE['yoohw_pre_orders_delivery_preference'] ) ) {
			setcookie( 'yoohw_pre_orders_delivery_preference', '', time() - HOUR_IN_SECONDS, '/' );
		}
	},
	10,
	2
);

if ( ! function_exists( 'yoohw_pre_orders_wc_order_is_mixed' ) ) {
	function yoohw_pre_orders_wc_order_is_mixed( $order ) {
		$has_preorder = false;
		$has_regular  = false;
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( 'onpreorder' === yoohw_pre_orders_wc_get_order_item_stock_status( $item, $product ) ) {
				$has_preorder = true;
			} else {
				$has_regular = true;
			}
		}

		return $has_preorder && $has_regular;
	}
}

add_filter(
	'render_block',
	function( $block_content, $block ) {
		if (
			is_admin() ||
			'yes' !== get_option( 'yoess_pre_order_customer_actions', 'no' ) ||
			empty( $block['blockName'] ) ||
			'woocommerce/checkout-contact-information-block' !== $block['blockName'] ||
			! function_exists( 'WC' ) ||
			! WC()->cart
		) {
			return $block_content;
		}

		$has_preorder = false;
		$has_regular  = false;
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$product = ! empty( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product
				? $cart_item['data']
				: wc_get_product( ! empty( $cart_item['variation_id'] ) ? $cart_item['variation_id'] : ( $cart_item['product_id'] ?? 0 ) );
			if ( ! $product ) {
				continue;
			}
			if ( 'onpreorder' === $product->get_stock_status() ) {
				$has_preorder = true;
			} else {
				$has_regular = true;
			}
		}

		if ( ! $has_preorder || ! $has_regular ) {
			return $block_content;
		}

		ob_start();
		?>
		<div class="stock-status-customer-notice yoohw-pre-orders-block-delivery">
			<p><?php esc_html_e( 'Your cart contains preorder and currently available items. How would you like to receive them?', 'pre-orders-wc' ); ?></p>
			<div class="stock-status-customer-actions-button">
				<button type="button" class="button button-customer-actions" data-action-type="all_at_once" aria-pressed="false"><?php esc_html_e( 'Receive all items at once', 'pre-orders-wc' ); ?></button>
				<button type="button" class="button button-customer-actions" data-action-type="separately" aria-pressed="false"><?php esc_html_e( 'Receive available items separately', 'pre-orders-wc' ); ?></button>
			</div>
			<div class="stock-status-customer-actions-note" aria-live="polite"></div>
		</div>
		<?php
		return ob_get_clean() . $block_content;
	},
	20,
	2
);
