<?php

defined( 'ABSPATH' ) || exit;

class YoOhw_Pre_Orders_WC_Edit_Order {

	public function __construct() {
		add_action( 'woocommerce_before_order_itemmeta', [ $this, 'display_pre_order_status_in_order' ], 10, 3 );
		add_action( 'woocommerce_admin_order_data_after_order_details', [ $this, 'add_pre_order_inline_css' ] );
	}

	public function display_pre_order_status_in_order( $item_id, $item, $product ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return;
		}

		$pending_ids      = '' === (string) $item->get_meta( '_wcess_stock_status', true ) ? yoohw_pre_orders_wc_get_tracked_product_ids( $item->get_order() ) : [];
		$checkout_status  = YoOhw_Pre_Orders_WC_Orders::get_checkout_status( $item, $pending_ids );
		$has_checkout_eta = $item->meta_exists( '_wcess_available_date' );
		$checkout_eta     = $has_checkout_eta ? (string) $item->get_meta( '_wcess_available_date', true ) : '';
		$product_id       = $item->get_variation_id() ?: $item->get_product_id();
		if ( ! $product instanceof WC_Product || $product_id !== $product->get_id() ) {
			$product = wc_get_product( $product_id );
		}
		$post_status      = $product_id ? get_post_status( $product_id ) : false;
		$product_exists   = $product instanceof WC_Product && $post_status && 'trash' !== $post_status;
		$current_status   = $product_exists ? $product->get_stock_status() : '';
		$current_eta      = $product_exists ? yoohw_pre_orders_wc_get_product_available_date( $product ) : '';

		$changes = [];
		if ( $product_exists ) {
			if ( '' !== $checkout_status && $checkout_status !== $current_status ) {
				$changes[] = __( 'Stock status changed', 'pre-orders-wc' );
			}

			$recorded_date = yoohw_pre_orders_wc_normalize_available_date( $checkout_eta );
			if ( $has_checkout_eta && ( '' === $checkout_eta || '' !== $recorded_date ) && $recorded_date !== $current_eta ) {
				if ( '' === $current_eta ) {
					$changes[] = __( 'Expected date removed', 'pre-orders-wc' );
				} elseif ( '' === $recorded_date ) {
					$changes[] = __( 'Expected date added', 'pre-orders-wc' );
				} elseif ( $current_eta > $recorded_date ) {
					$changes[] = __( 'Expected date postponed', 'pre-orders-wc' );
				} else {
					$changes[] = __( 'Expected date moved earlier', 'pre-orders-wc' );
				}
			}
		}
		?>
		<div class="item-meta-preorder">
			<div>
				<strong><?php esc_html_e( 'At checkout', 'pre-orders-wc' ); ?></strong>
				<?php echo esc_html( $this->get_status_label( $checkout_status ) ); ?>
				&middot; <?php echo esc_html( $this->get_date_label( $checkout_eta, $has_checkout_eta ) ); ?>
			</div>
			<div>
				<strong><?php esc_html_e( 'Current product', 'pre-orders-wc' ); ?></strong>
				<?php if ( $product_exists ) : ?>
					<?php echo esc_html( $this->get_status_label( $current_status ) ); ?>
					&middot; <?php echo esc_html( $this->get_date_label( $current_eta, true ) ); ?>
				<?php else : ?>
					<?php esc_html_e( 'Product unavailable', 'pre-orders-wc' ); ?>
				<?php endif; ?>
			</div>
			<?php if ( $changes ) : ?>
				<div><?php echo esc_html( implode( ' · ', $changes ) ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function get_status_label( $status ) {
		if ( '' === $status ) {
			return __( 'Status not recorded', 'pre-orders-wc' );
		}
		$statuses = wc_get_product_stock_status_options();
		return $statuses[ $status ] ?? $status;
	}

	private function get_date_label( $date, $recorded ) {
		if ( ! $recorded ) {
			return __( 'Expected date not recorded', 'pre-orders-wc' );
		}
		if ( '' === $date ) {
			return __( 'No expected date', 'pre-orders-wc' );
		}
		$formatted = yoohw_pre_orders_wc_format_available_date( $date, true );
		return '' !== $formatted ? $formatted : __( 'Expected date unavailable', 'pre-orders-wc' );
	}

	public function add_pre_order_inline_css( $order ) {
		$class = YoOhw_Pre_Orders_WC_Orders::get_order_class( $order );
		if ( '' !== $class ) {
			?>
			<style>
				h2.woocommerce-order-data__heading::before {
					content: url("<?php echo esc_url( YoOhw_Pre_Orders_WC_Orders::get_icon_url( $class ) ); ?>");
					display: inline-block;
					transform: scale(1.2);
					margin-right: 14px;
				}
			</style>
			<?php
		}
	}
}

new YoOhw_Pre_Orders_WC_Edit_Order();
