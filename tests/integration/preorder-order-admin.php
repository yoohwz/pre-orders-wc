<?php
/**
 * Administrative order filtering and snapshot presentation using disposable order storage.
 */

use Automattic\WooCommerce\Utilities\OrderUtil;

defined( 'ABSPATH' ) || exit;

function yoohw_pre_orders_admin_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function yoohw_pre_orders_admin_fixture( array &$orders, $timestamp, $status = 'processing', $name = 'POFilterMatch' ) {
	$order    = new WC_Order();
	$orders[] = $order;
	$order->set_status( $status );
	$order->set_date_created( strtotime( $timestamp . ' UTC' ) );
	$order->set_billing_first_name( $name );
	$order->save();
	return $order;
}

function yoohw_pre_orders_admin_line( $order, $product, $stock = null, $date = null ) {
	$item = $order->get_item( $order->add_product( $product ) );
	if ( null !== $stock ) {
		$item->add_meta_data( '_wcess_stock_status', $stock, true );
	}
	if ( null !== $date ) {
		$item->add_meta_data( '_wcess_available_date', $date, true );
	}
	$item->save();
	return $item;
}

function yoohw_pre_orders_admin_filter( $ids, $filter, $page = 1, $limit = 100, $search = '' ) {
	global $wp_the_query;

	$_GET[ YoOhw_Pre_Orders_WC_Orders::FILTER ] = $filter;
	if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$args = apply_filters(
			'woocommerce_shop_order_list_table_prepare_items_query_args',
			[
				'include'      => $ids,
				'status'       => [ 'wc-processing' ],
				'date_created' => strtotime( '2025-03-01 UTC' ) . '...' . strtotime( '2025-03-31 23:59:59 UTC' ),
				's'            => $search,
				'orderby'      => 'ID',
				'order'        => 'ASC',
				'page'         => $page,
				'limit'        => $limit,
				'paginate'     => true,
				'return'       => 'ids',
			]
		);
		$result = wc_get_orders( $args );
		return [ array_map( 'absint', $result->orders ), (int) $result->total ];
	}

	// The legacy list's search handler resolves matching IDs before querying posts.
	$found_ids = '' === $search ? $ids : array_intersect( $ids, wc_order_search( $search ) );
	$old_query = $wp_the_query;
	$query     = new WP_Query();
	try {
		$wp_the_query = $query;
		$query->query(
			[
				'post__in'       => $found_ids ?: [ 0 ],
				'post_type'      => 'shop_order',
				'post_status'    => 'wc-processing',
				'date_query'     => [ [ 'year' => 2025, 'month' => 3 ] ],
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'posts_per_page' => $limit,
				'paged'          => $page,
				'fields'         => 'ids',
			]
		);
		return [ array_map( 'absint', $query->posts ), (int) $query->found_posts ];
	} finally {
		$wp_the_query = $old_query;
	}
}

function yoohw_pre_orders_admin_line_markup( $item, $product ) {
	ob_start();
	do_action( 'woocommerce_before_order_itemmeta', $item->get_id(), $item, $product );
	return ob_get_clean();
}

$orders     = [];
$products   = [];
$old_get    = $_GET;
$old_screen = $GLOBALS['current_screen'] ?? null;

add_filter( 'pre_wp_mail', '__return_true', 99 );

try {
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
	require_once ABSPATH . 'wp-admin/includes/screen.php';
	set_current_screen( 'edit-shop_order' );

	$products[] = $regular = new WC_Product_Simple();
	$regular->set_name( 'Order admin regular fixture' );
	$regular->set_stock_status( 'onpreorder' ); // Changed since the order was placed.
	$regular->set_regular_price( '10' );
	$regular->save();

	$products[] = $preorder = new WC_Product_Simple();
	$preorder->set_name( 'Order admin formerly preorder fixture' );
	$preorder->set_stock_status( 'instock' );
	$preorder->set_regular_price( '10' );
	$preorder->save();
	yoohw_pre_orders_wc_update_product_available_date( $preorder->get_id(), '2030-02-20T12:00' );

	$products[] = $parent = new WC_Product_Variable();
	$parent->set_name( 'Order admin variation parent fixture' );
	$parent->save();

	$products[] = $variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent->get_id() );
	$variation->set_regular_price( '10' );
	$variation->save();

	$marked = yoohw_pre_orders_admin_fixture( $orders, '2025-03-04 12:00:00' );
	$line   = yoohw_pre_orders_admin_line( $marked, $preorder, 'onpreorder', '2030-02-01T12:00' );
	yoohw_pre_orders_admin_line( $marked, $regular, 'instock' );
	$marked->update_meta_data( '_pre_order_marked', '1' );
	$marked->save();

	$snapshot = yoohw_pre_orders_admin_fixture( $orders, '2025-03-05 12:00:00' );
	$deleted  = yoohw_pre_orders_admin_line( $snapshot, $variation, 'onpreorder', '' );
	$variation->delete( true );

	$legacy      = yoohw_pre_orders_admin_fixture( $orders, '2025-03-06 12:00:00', 'processing', 'POAlternate' );
	$legacy_line = yoohw_pre_orders_admin_line( $legacy, $preorder );
	$legacy->update_meta_data( '_pre_order_items', (string) $preorder->get_id() );
	$legacy->save();

	$ordinary = yoohw_pre_orders_admin_fixture( $orders, '2025-03-07 12:00:00' );
	yoohw_pre_orders_admin_line( $ordinary, $regular, 'instock' );
	$ordinary->update_meta_data( '_pre_order_items', [] );
	$ordinary->save();
	$unrecorded = yoohw_pre_orders_admin_fixture( $orders, '2025-03-08 12:00:00' );
	yoohw_pre_orders_admin_line( $unrecorded, $regular );

	$old = yoohw_pre_orders_admin_fixture( $orders, '2025-02-08 12:00:00' );
	$old->update_meta_data( '_pre_order_marked', '1' );
	$old->save();
	$other_status = yoohw_pre_orders_admin_fixture( $orders, '2025-03-08 12:00:00', 'completed' );
	$other_status->update_meta_data( '_pre_order_marked', '1' );
	$other_status->save();

	$ids = array_map( static function ( $order ) { return $order->get_id(); }, $orders );
	$expected_yes = [ $marked->get_id(), $snapshot->get_id(), $legacy->get_id() ];
	$expected_no  = [ $ordinary->get_id(), $unrecorded->get_id() ];
	yoohw_pre_orders_admin_assert( [ $expected_yes, 3 ] === yoohw_pre_orders_admin_filter( $ids, 'yes' ), 'Positive filter lost mixed, tracked legacy, or snapshot-only orders.' );
	yoohw_pre_orders_admin_assert( [ $expected_no, 2 ] === yoohw_pre_orders_admin_filter( $ids, 'no' ), 'Negative filter misclassified ordinary orders whose catalog products are now preorder.' );
	yoohw_pre_orders_admin_assert( [ array_merge( $expected_yes, $expected_no ), 5 ] === yoohw_pre_orders_admin_filter( $ids, '' ), 'All orders did not preserve native date and status constraints.' );
	yoohw_pre_orders_admin_assert( [ [ $snapshot->get_id() ], 2 ] === yoohw_pre_orders_admin_filter( $ids, 'yes', 2, 1, 'POFilterMatch' ), 'The order classification did not compose with native search, status, date or pagination.' );

	$views_hook = OrderUtil::custom_orders_table_usage_is_enabled() ? 'views_woocommerce_page_wc-orders' : 'views_edit-shop_order';
	$views = apply_filters( $views_hook, [ '<a href="edit.php?post_status=wc-processing">Processing</a>' ] );
	yoohw_pre_orders_admin_assert( false !== strpos( $views[0], 'yoohw_pre_orders_filter=yes' ), 'The status tabs lost the active preorder filter.' );

	if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$classes = apply_filters( 'woocommerce_shop_order_list_table_order_css_classes', [ 'type-shop_order' ], wc_get_order( $marked->get_id() ) );
	} else {
		$classes = get_post_class( '', $marked->get_id() );
	}
	yoohw_pre_orders_admin_assert( in_array( 'yoohw-mixed-preorder', $classes, true ), 'The rendered row did not receive the matching preorder icon class.' );
	yoohw_pre_orders_admin_assert( 'yoohw-only-preorder' === YoOhw_Pre_Orders_WC_Orders::get_order_class( $snapshot ), 'A deleted variation lost the order preorder icon.' );
	yoohw_pre_orders_admin_assert( '' === YoOhw_Pre_Orders_WC_Orders::get_order_class( $unrecorded ), 'An ordinary order borrowed the product’s current preorder status.' );

	$changed_markup = yoohw_pre_orders_admin_line_markup( $line, $preorder );
	yoohw_pre_orders_admin_assert( false !== strpos( $changed_markup, 'Expected date postponed' ) && false !== strpos( $changed_markup, 'Stock status changed' ), 'The comparison did not describe a date delay and catalog stock change.' );
	yoohw_pre_orders_admin_assert( false !== strpos( $changed_markup, esc_html( yoohw_pre_orders_wc_format_available_date( '2030-02-01T12:00', true ) ) ), 'The historical date was not rendered.' );
	yoohw_pre_orders_wc_update_product_available_date( $preorder->get_id(), '' );
	yoohw_pre_orders_admin_assert( false !== strpos( yoohw_pre_orders_admin_line_markup( $line, $preorder ), 'Expected date removed' ), 'Removing the current date hid the historical date change.' );
	yoohw_pre_orders_admin_assert( '2030-02-01T12:00' === $line->get_meta( '_wcess_available_date', true ), 'Rendering modified the order item snapshot.' );

	$deleted_markup = yoohw_pre_orders_admin_line_markup( $deleted, false );
	yoohw_pre_orders_admin_assert( false !== strpos( $deleted_markup, 'Product unavailable' ) && false !== strpos( $deleted_markup, 'No expected date' ) && false === strpos( $deleted_markup, 'Expected date not recorded' ), 'A deleted variation did not preserve the explicitly empty checkout date.' );
	$legacy_markup = yoohw_pre_orders_admin_line_markup( $legacy_line, $preorder );
	yoohw_pre_orders_admin_assert( false !== strpos( $legacy_markup, 'Expected date not recorded' ) && false !== strpos( $legacy_markup, 'Stock status changed' ), 'A legacy order did not distinguish missing date evidence from an explicitly empty date.' );

	echo "Preorder order admin checks passed.\n";
} finally {
	foreach ( $orders as $order ) {
		if ( $order->get_id() ) {
			$order->delete( true );
		}
	}
	foreach ( array_reverse( $products ) as $product ) {
		if ( $product->get_id() ) {
			$product->delete( true );
		}
	}
	$_GET = $old_get;
	$GLOBALS['current_screen'] = $old_screen;
	remove_filter( 'pre_wp_mail', '__return_true', 99 );
}
