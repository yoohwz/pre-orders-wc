<?php
/**
 * WP-CLI integration coverage for free preorder workflows and upgrade compatibility.
 *
 * Run with: wp eval-file wp-content/plugins/pre-orders-wc/tests/integration/preorder-workflows.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this file through WP-CLI so WordPress and WooCommerce are loaded.\n" );
	exit( 1 );
}

function yoohw_preorders_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$plugin_dir = dirname( __DIR__, 2 );
foreach (
	[
		'inc/cores/compatibility.php'                    => 'YoOhw_Pre_Orders_WC_Compatibility',
		'inc/backend/actions/new-pre-order.php'          => 'YoOhw_Pre_Orders_WC_New_Pre_Order',
		'inc/backend/preorder-limits.php'                 => 'YoOhw_Pre_Orders_WC_Preorder_Limits',
		'inc/backend/preorder-overview.php'               => 'YoOhw_Pre_Orders_WC_Overview',
		'inc/backend/actions/auto-update-to-instock.php' => 'YoOhw_Pre_Orders_WC_Auto_Update_To_In_Stock',
	] as $relative_file => $class_name
) {
	if ( ! class_exists( $class_name, false ) ) {
		require_once $plugin_dir . '/' . $relative_file;
	}
}
if ( ! function_exists( 'yoohw_pre_orders_wc_order_is_mixed' ) ) {
	require_once $plugin_dir . '/inc/frontend/customer-actions-blocks.php';
}

$preorder       = null;
$regular        = null;
$order          = null;
$failure        = null;
$captured_email = [];

try {
	$preorder = new WC_Product_Simple();
	$preorder->set_name( 'Free preorder integration fixture' );
	$preorder->set_status( 'private' );
	$preorder->set_regular_price( '10' );
	$preorder->set_stock_status( 'onpreorder' );
	$preorder->save();

	$old_date = wp_date( 'Y-m-d\TH:i', time() + ( 10 * DAY_IN_SECONDS ), wp_timezone() );
	$new_date = wp_date( 'Y-m-d\TH:i', time() + ( 20 * DAY_IN_SECONDS ), wp_timezone() );
	yoohw_pre_orders_wc_update_product_available_date( $preorder->get_id(), $new_date );
	yoohw_preorders_test_assert( $new_date === get_post_meta( $preorder->get_id(), '_pre_order_available_date', true ), 'Legacy free date was not dual-written.' );
	yoohw_preorders_test_assert( $new_date === get_post_meta( $preorder->get_id(), '_extended_status_available_date', true ), 'Stock Manager date was not dual-written.' );

	$regular = new WC_Product_Simple();
	$regular->set_name( 'Regular integration fixture' );
	$regular->set_status( 'private' );
	$regular->set_regular_price( '5' );
	$regular->set_stock_status( 'instock' );
	$regular->save();

	$order = wc_create_order();
	yoohw_preorders_test_assert( $order instanceof WC_Order, 'The HPOS-compatible order fixture could not be created.' );
	$order->set_billing_first_name( 'Integration' );
	$order->set_billing_email( 'preorder-integration@example.com' );
	$preorder_item_id = $order->add_product( $preorder, 2 );
	$order->add_product( $regular, 1 );
	$order->set_status( 'processing' );
	$order->update_meta_data( '_pre_order_items', [ $preorder->get_id() ] );
	$order->update_meta_data( '_pre_order_marked', '1' );
	$order->save();

	$preorder_item = $order->get_item( $preorder_item_id );
	$preorder_item->update_meta_data( '_wcess_stock_status', 'onpreorder' );
	$preorder_item->update_meta_data( '_wcess_available_date', $old_date );
	$preorder_item->save();

	yoohw_preorders_test_assert( 'onpreorder' === yoohw_pre_orders_wc_get_order_item_stock_status( $preorder_item, $preorder ), 'Order item stock-status snapshot was not read.' );
	yoohw_preorders_test_assert( $old_date === yoohw_pre_orders_wc_get_order_item_available_date( $preorder_item, $preorder ), 'Order item expected-date snapshot was not read.' );
	yoohw_preorders_test_assert( yoohw_pre_orders_wc_order_is_mixed( $order ), 'Mixed preorder order detection failed.' );

	update_post_meta( $preorder->get_id(), '_max_purchase_qty', 3 );
	$limit_method = new ReflectionMethod( 'YoOhw_Pre_Orders_WC_Preorder_Limits', 'get_limit' );
	$limit_method->setAccessible( true );
	yoohw_preorders_test_assert( 3 === $limit_method->invoke( new YoOhw_Pre_Orders_WC_Preorder_Limits(), $preorder->get_id() ), 'Maximum preorder quantity was not resolved.' );

	$mail_filter = static function( $return, $attributes ) use ( &$captured_email ) {
		$captured_email[] = $attributes;
		return true;
	};
	add_filter( 'pre_wp_mail', $mail_filter, 10, 2 );
	$overview = new YoOhw_Pre_Orders_WC_Overview();
	$notify   = new ReflectionMethod( $overview, 'send_delay_notifications' );
	$notify->setAccessible( true );
	$result = $notify->invoke( $overview, $preorder );
	$repeat_result = $notify->invoke( $overview, $preorder );
	remove_filter( 'pre_wp_mail', $mail_filter, 10 );
	yoohw_preorders_test_assert( 1 === $result['sent'] && 0 === $result['skipped'], 'Expected-date delay notification was not sent exactly once.' );
	yoohw_preorders_test_assert( 0 === $repeat_result['sent'], 'The same expected-date delay notification was sent more than once.' );
	yoohw_preorders_test_assert( 1 === count( $captured_email ), 'Delay email did not pass through the WordPress mail pipeline exactly once.' );

	$preorder_item = wc_get_order( $order->get_id() )->get_item( $preorder_item_id );
	yoohw_preorders_test_assert( $new_date === $preorder_item->get_meta( YoOhw_Pre_Orders_WC_Overview::DELAY_ITEM_META, true ), 'Delay-notification deduplication marker was not saved.' );

	$auto_update = new YoOhw_Pre_Orders_WC_Auto_Update_To_In_Stock();
	$auto_update->update_pre_order_stock_status( $preorder->get_id() );
	$preorder = wc_get_product( $preorder->get_id() );
	yoohw_preorders_test_assert( 'instock' === $preorder->get_stock_status(), 'Automatic preorder stock transition failed.' );
	yoohw_preorders_test_assert( '' === get_post_meta( $preorder->get_id(), '_pre_order_available_date', true ), 'Legacy available date was not cleared after stock transition.' );
	yoohw_preorders_test_assert( '' === get_post_meta( $preorder->get_id(), '_extended_status_available_date', true ), 'Stock Manager available date was not cleared after stock transition.' );
	yoohw_preorders_test_assert( [] === yoohw_pre_orders_wc_get_tracked_product_ids( wc_get_order( $order->get_id() ) ), 'Completed preorder item remained in the order tracking list.' );

	if ( class_exists( 'WC_Extended_Stock_Status_Migration_Manager' ) ) {
		update_post_meta( $preorder->get_id(), '_pre_order_available_date', $old_date );
		delete_post_meta( $preorder->get_id(), '_extended_status_available_date' );
		$bridge = ( new WC_Extended_Stock_Status_Migration_Manager() )->migrate_free_preorders_bridge();
		yoohw_preorders_test_assert( $old_date === get_post_meta( $preorder->get_id(), '_extended_status_available_date', true ), 'Stock Manager did not import the free-plugin date.' );
		yoohw_preorders_test_assert( $old_date === get_post_meta( $preorder->get_id(), '_pre_order_available_date', true ), 'Stock Manager deleted the free-plugin date during migration.' );
		yoohw_preorders_test_assert( isset( $bridge['legacy_metadata_deleted'] ) && 0 === $bridge['legacy_metadata_deleted'], 'Upgrade bridge did not preserve legacy metadata.' );
	}

	echo "Pre-Orders for WooCommerce integration workflows passed.\n";
} catch ( Throwable $exception ) {
	$failure = $exception;
} finally {
	if ( $order instanceof WC_Order && $order->get_id() ) {
		$order->delete( true );
	}
	if ( $preorder instanceof WC_Product && $preorder->get_id() ) {
		$preorder->delete( true );
	}
	if ( $regular instanceof WC_Product && $regular->get_id() ) {
		$regular->delete( true );
	}
}

if ( $failure instanceof Throwable ) {
	fwrite( STDERR, 'Pre-Orders for WooCommerce integration workflows failed: ' . $failure->getMessage() . "\n" );
	exit( 1 );
}
