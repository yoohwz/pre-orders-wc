<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoOhw_Pre_Orders_WC_Upsell_Events {
	const OPTION_EVENTS = 'yoohw_pre_orders_wc_upsell_events';
	const OPTION_PRODUCT_COUNT = 'yoohw_pre_orders_wc_preorder_product_count';
	const OPTION_LAST_RECOUNT = 'yoohw_pre_orders_wc_preorder_recount_at';
	const META_COUNTED_PRODUCT = '_yoohw_pre_orders_wc_counted_preorder';
	const USER_META_SEEN = 'yoohw_pre_orders_wc_seen_upsell_triggers';
	const USER_META_LAST_SHOWN = 'yoohw_pre_orders_wc_last_upsell_at';
	const DISPLAY_INTERVAL = 1209600; // 14 days.

	public function __construct() {
		add_action( 'admin_init', [ $this, 'maybe_recount_preorder_products' ] );
		add_action( 'admin_notices', [ $this, 'maybe_render_notice' ] );
		add_action( 'admin_post_yoohw_pre_orders_wc_dismiss_upsell', [ $this, 'dismiss_notice' ] );
		add_action( 'woocommerce_admin_process_product_object', [ $this, 'track_product_status' ], 120 );
		add_action( 'woocommerce_save_product_variation', [ $this, 'track_variation_status' ], 120, 2 );
		add_action( 'updated_option', [ $this, 'track_email_setting' ], 10, 3 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'track_mixed_preorder_order' ], 30, 4 );
		add_action( 'yoohw_pre_orders_wc_auto_updated_to_instock', [ $this, 'track_auto_update' ] );
	}

	public function maybe_recount_preorder_products() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'wc_get_products' ) ) {
			return;
		}

		$last_recount = (int) get_option( self::OPTION_LAST_RECOUNT, 0 );
		if ( $last_recount && ( time() - $last_recount ) < DAY_IN_SECONDS ) {
			return;
		}

		update_option( self::OPTION_LAST_RECOUNT, time(), false );

		$product_ids = wc_get_products(
			[
				'limit'        => 3,
				'return'       => 'ids',
				'status'       => [ 'publish', 'private' ],
				'stock_status' => 'onpreorder',
			]
		);

		$count = count( $product_ids );
		update_option( self::OPTION_PRODUCT_COUNT, $count, false );

		if ( $count >= 3 ) {
			$this->record_event( 'bulk_scheduler' );
		}
	}

	public function track_product_status( $product ) {
		if ( $product instanceof WC_Product ) {
			$this->sync_product_count( $product );
		}
	}

	public function track_variation_status( $variation_id, $i ) {
		$product = wc_get_product( $variation_id );
		if ( $product instanceof WC_Product ) {
			$this->sync_product_count( $product );
		}
	}

	public function track_email_setting( $option, $old_value, $value ) {
		if ( 'yoess_pre_order_email_to_admin' === $option && 'yes' !== $old_value && 'yes' === $value ) {
			$this->record_event( 'email_sequences' );
		}
	}

	public function track_mixed_preorder_order( $item, $cart_item_key, $values, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$product = isset( $values['data'] ) ? $values['data'] : null;
		if ( ! $product ) {
			return;
		}

		if ( 'onpreorder' === $product->get_stock_status() ) {
			$order->update_meta_data( '_yoohw_pre_orders_wc_has_preorder_item', 'yes' );
		} else {
			$order->update_meta_data( '_yoohw_pre_orders_wc_has_regular_item', 'yes' );
		}

		if (
			'yes' === $order->get_meta( '_yoohw_pre_orders_wc_has_preorder_item', true ) &&
			'yes' === $order->get_meta( '_yoohw_pre_orders_wc_has_regular_item', true )
		) {
			$this->record_event( 'mixed_orders' );
		}
	}

	public function track_auto_update( $product_id ) {
		$this->record_event( 'reports' );
	}

	public function maybe_render_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		$last_shown = (int) get_user_meta( $user_id, self::USER_META_LAST_SHOWN, true );
		if ( $last_shown && ( time() - $last_shown ) < self::DISPLAY_INTERVAL ) {
			return;
		}

		$trigger = $this->get_next_notice_trigger( $user_id );
		if ( ! $trigger ) {
			return;
		}

		$notice = $this->get_notice_content( $trigger );
		if ( empty( $notice ) ) {
			return;
		}

		$this->mark_trigger_seen( $user_id, $trigger );
		update_user_meta( $user_id, self::USER_META_LAST_SHOWN, time() );

		$premium_url = (string) apply_filters( 'yoohw_pre_orders_wc_premium_url', YOOHW_PRE_ORDERS_WC_PREMIUM_URL );
		$dismiss_url = wp_nonce_url(
			add_query_arg(
				[
					'action'  => 'yoohw_pre_orders_wc_dismiss_upsell',
					'trigger' => $trigger,
				],
				admin_url( 'admin-post.php' )
			),
			'yoohw_pre_orders_wc_dismiss_upsell_' . $trigger
		);
		?>
		<div class="notice notice-info yoohw-pre-orders-upsell-notice">
			<p><strong><?php echo esc_html( $notice['title'] ); ?></strong></p>
			<p><?php echo esc_html( $notice['message'] ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $premium_url ); ?>" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Purchase premium plugin', 'pre-orders-wc' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( $dismiss_url ); ?>">
					<?php esc_html_e( 'Dismiss', 'pre-orders-wc' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	public function dismiss_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to dismiss this notice.', 'pre-orders-wc' ) );
		}

		$trigger = isset( $_GET['trigger'] ) ? sanitize_key( wp_unslash( $_GET['trigger'] ) ) : '';
		if ( ! $trigger || ! $this->is_valid_trigger( $trigger ) ) {
			wp_safe_redirect( admin_url() );
			exit;
		}

		check_admin_referer( 'yoohw_pre_orders_wc_dismiss_upsell_' . $trigger );
		$this->mark_trigger_seen( get_current_user_id(), $trigger );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	private function sync_product_count( $product ) {
		$product_id = $product->get_id();
		if ( ! $product_id ) {
			return;
		}

		$is_preorder = 'onpreorder' === $product->get_stock_status();
		$is_counted  = 'yes' === get_post_meta( $product_id, self::META_COUNTED_PRODUCT, true );
		$count       = max( 0, (int) get_option( self::OPTION_PRODUCT_COUNT, 0 ) );

		if ( $is_preorder && ! $is_counted ) {
			update_post_meta( $product_id, self::META_COUNTED_PRODUCT, 'yes' );
			$count++;
		} elseif ( ! $is_preorder && $is_counted ) {
			delete_post_meta( $product_id, self::META_COUNTED_PRODUCT );
			$count = max( 0, $count - 1 );
		}

		update_option( self::OPTION_PRODUCT_COUNT, $count, false );

		if ( $count >= 3 ) {
			$this->record_event( 'bulk_scheduler' );
		}
	}

	private function record_event( $trigger ) {
		if ( ! $this->is_valid_trigger( $trigger ) ) {
			return;
		}

		$events = get_option( self::OPTION_EVENTS, [] );
		if ( ! is_array( $events ) ) {
			$events = [];
		}

		if ( empty( $events[ $trigger ] ) ) {
			$events[ $trigger ] = time();
			update_option( self::OPTION_EVENTS, $events, false );
		}
	}

	private function get_next_notice_trigger( $user_id ) {
		$events = get_option( self::OPTION_EVENTS, [] );
		if ( empty( $events ) || ! is_array( $events ) ) {
			return '';
		}

		$seen = get_user_meta( $user_id, self::USER_META_SEEN, true );
		if ( ! is_array( $seen ) ) {
			$seen = [];
		}

		foreach ( [ 'bulk_scheduler', 'email_sequences', 'mixed_orders', 'reports' ] as $trigger ) {
			if ( ! empty( $events[ $trigger ] ) && empty( $seen[ $trigger ] ) ) {
				return $trigger;
			}
		}

		return '';
	}

	private function mark_trigger_seen( $user_id, $trigger ) {
		$seen = get_user_meta( $user_id, self::USER_META_SEEN, true );
		if ( ! is_array( $seen ) ) {
			$seen = [];
		}

		$seen[ $trigger ] = time();
		update_user_meta( $user_id, self::USER_META_SEEN, $seen );
	}

	private function get_notice_content( $trigger ) {
		$notices = [
			'bulk_scheduler'  => [
				'title'   => __( 'Managing multiple preorder products?', 'pre-orders-wc' ),
				'message' => __( 'You now have several preorder products. Pro workflows can help bulk update preorder status and availability dates from one place.', 'pre-orders-wc' ),
			],
			'email_sequences' => [
				'title'   => __( 'Preorder email reminders are active.', 'pre-orders-wc' ),
				'message' => __( 'Need reminder, delay, and available-now email sequences? Pro workflows are planned for automated preorder communication.', 'pre-orders-wc' ),
			],
			'mixed_orders'    => [
				'title'   => __( 'Mixed preorder orders detected.', 'pre-orders-wc' ),
				'message' => __( 'This store is receiving orders with preorder and in-stock items together. Pro workflows are planned to help automate split shipment handling.', 'pre-orders-wc' ),
			],
			'reports'         => [
				'title'   => __( 'Preorder products are moving to in-stock.', 'pre-orders-wc' ),
				'message' => __( 'Track upcoming releases, pending preorder items, and preorder revenue with the reporting workflows planned for Pro.', 'pre-orders-wc' ),
			],
		];

		return isset( $notices[ $trigger ] ) ? $notices[ $trigger ] : [];
	}

	private function is_valid_trigger( $trigger ) {
		return in_array( $trigger, [ 'bulk_scheduler', 'email_sequences', 'mixed_orders', 'reports' ], true );
	}
}

new YoOhw_Pre_Orders_WC_Upsell_Events();
