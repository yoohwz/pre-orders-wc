<?php
/**
 * Checkout, variation and scheduling regressions from the PO 1.3.0 audit.
 * Run through WP-CLI on a disposable WordPress database.
 */

use Automattic\WooCommerce\StoreApi\Exceptions\InvalidCartException;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Utilities\CartController;
use Automattic\WooCommerce\StoreApi\Utilities\OrderController;

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this file through WP-CLI so WordPress and WooCommerce are loaded.\n" );
	exit( 1 );
}

function yoohw_po_audit_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function yoohw_po_audit_simple_product( $name, $stock_status ) {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_status( 'publish' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$product->set_stock_status( $stock_status );
	$product->save();

	return $product;
}

function yoohw_po_audit_save_date( $id, $date ) {
	$posted = $_POST;
	try {
		$_POST = [
			'wc_pre_orders_nonce'       => wp_create_nonce( 'wc_pre_orders_nonce_action' ),
			'_pre_order_available_date' => $date,
		];
		do_action( 'woocommerce_process_product_meta', $id );
	} finally {
		$_POST = $posted;
	}
}

function yoohw_po_audit_assert_limits( $product, $cart ) {
	$product_id = $product->get_id();
	update_post_meta( $product_id, '_max_purchase_qty', 2 );

	$first  = $cart->add_to_cart( $product_id, 1 );
	$second = $cart->add_to_cart( $product_id, 1, 0, [], [ 'po_audit_line' => 'second' ] );
	yoohw_po_audit_assert( $first && $second && $first !== $second, 'Cart fixture must have two rows for the same preorder.' );
	$line = $cart->get_cart()[ $first ];

	yoohw_po_audit_assert( apply_filters( 'woocommerce_update_cart_validation', true, $first, $line, 1 ), 'Classic rejected quantity at the preorder limit.' );
	yoohw_po_audit_assert( ! apply_filters( 'woocommerce_update_cart_validation', true, $first, $line, 2 ), 'Classic cart update accepted an aggregate preorder quantity over the limit.' );
	wc_clear_notices();

	$controller = new CartController();
	$controller->set_cart_item_quantity( $first, 1 );
	try {
		$controller->set_cart_item_quantity( $first, 2 );
		throw new RuntimeException( 'Blocks cart update accepted an aggregate preorder quantity over the limit.' );
	} catch ( RouteException $exception ) {
		yoohw_po_audit_assert( 1 === (int) $cart->get_cart()[ $first ]['quantity'], 'Rejected Blocks update changed the preorder quantity.' );
	}

	// Model a cart already above the limit when checkout starts, for example after an admin lowers it.
	update_post_meta( $product_id, '_max_purchase_qty', 1 );
	do_action( 'woocommerce_check_cart_items' );
	yoohw_po_audit_assert( 1 === wc_notice_count( 'error' ), 'Classic checkout did not report an over-limit preorder once.' );
	wc_clear_notices();
	try {
		$controller->validate_cart();
		throw new RuntimeException( 'Blocks checkout accepted a cart already over the preorder limit.' );
	} catch ( InvalidCartException $exception ) {
		yoohw_po_audit_assert( 1 === count( $exception->getError()->get_error_messages() ), 'Blocks checkout should report this preorder limit once.' );
	}

	update_post_meta( $product_id, '_max_purchase_qty', 2 );
	$controller->validate_cart();
	wc_clear_notices();
	do_action( 'woocommerce_check_cart_items' );
	yoohw_po_audit_assert( 0 === wc_notice_count( 'error' ), 'Classic checkout rejected a cart at the preorder limit.' );
	$cart->empty_cart();
}

function yoohw_po_audit_assert_history( $order, $regular_id ) {
	foreach ( $order->get_items() as $item ) {
		if ( $regular_id === $item->get_product_id() ) {
			yoohw_po_audit_assert( 'instock' === $item->get_meta( '_wcess_stock_status', true ), 'A regular order line did not store its stock status.' );
			yoohw_po_audit_assert( 'instock' === yoohw_pre_orders_wc_get_order_item_stock_status( $item ), 'Historical stock classification followed a later catalog change.' );
			return;
		}
	}
	throw new RuntimeException( 'The checkout order did not contain the regular fixture.' );
}

function yoohw_po_audit_run_variation_save( $parent, $variation, $date ) {
	if ( ! class_exists( 'WC_Admin_Meta_Boxes', false ) ) {
		require_once WC_ABSPATH . 'includes/admin/class-wc-admin-meta-boxes.php';
	}
	if ( ! class_exists( 'WC_Meta_Box_Product_Data', false ) ) {
		require_once WC_ABSPATH . 'includes/admin/meta-boxes/class-wc-meta-box-product-data.php';
	}

	$posted        = $_POST;
	$request       = $_REQUEST;
	$handler       = static function () {
		return static function ( $message ) {
			throw new RuntimeException( 'Variation AJAX response: ' . $message );
		};
	};
	$buffers       = ob_get_level();
	$variation_id  = $variation->get_id();

	add_filter( 'wp_doing_ajax', '__return_true' );
	add_filter( 'wp_die_ajax_handler', $handler );
	try {
		$_POST = [
			'action'                    => 'woocommerce_save_variations',
			'security'                  => wp_create_nonce( 'save-variations' ),
			'product_id'                => $parent->get_id(),
			'variable_post_id'          => [ $variation_id ],
			'variable_enabled'          => [ 'on' ],
			'variable_regular_price'    => [ '10' ],
			'variable_stock_status'     => [ 'onpreorder' ],
			'attribute_size'            => [ 'Small' ],
			'_pre_order_available_date' => [ $variation_id => $date ],
		];
		$_REQUEST = $_POST;
		WC_AJAX::save_variations();
		throw new RuntimeException( 'WooCommerce AJAX handler did not produce a response.' );
	} catch ( RuntimeException $exception ) {
		if ( 'Variation AJAX response: ' !== $exception->getMessage() ) {
			throw $exception;
		}

		$editor                                              = get_current_user_id();
		$_POST['_pre_order_available_date'][ $variation_id ] = '2035-01-01T12:00';

		// The plugin callback must reject an invalid nonce even for a product editor.
		$_POST['security'] = 'invalid';
		do_action( 'woocommerce_save_product_variation', $variation_id, 0 );
		yoohw_po_audit_assert( $date === yoohw_pre_orders_wc_get_product_available_date( $variation ), 'Variation date changed with an invalid AJAX nonce.' );

		// A valid nonce is insufficient without permission to edit the variation.
		wp_set_current_user( 0 );
		try {
			$_POST['security'] = wp_create_nonce( 'save-variations' );
			do_action( 'woocommerce_save_product_variation', $variation_id, 0 );
		} finally {
			wp_set_current_user( $editor );
		}
	} finally {
		while ( ob_get_level() > $buffers ) {
			ob_end_clean();
		}
		remove_filter( 'wp_die_ajax_handler', $handler );
		remove_filter( 'wp_doing_ajax', '__return_true' );
		$_POST    = $posted;
		$_REQUEST = $request;
	}
}

$products   = [];
$orders     = [];
$cart       = null;
$failure    = null;
$stock_hook = 'wc_pre_orders_update_stock_status';
$admin_hook = 'wc_pre_orders_email_admin_preorder_availability';
$old_user   = get_current_user_id();
$options    = [
	'woocommerce_hide_out_of_stock_items'     => get_option( 'woocommerce_hide_out_of_stock_items' ),
	'yoess_pre_order_email_to_admin'          => get_option( 'yoess_pre_order_email_to_admin' ),
	'yoess_pre_order_email_to_admin_timer'    => get_option( 'yoess_pre_order_email_to_admin_timer' ),
	'woocommerce_pre_order_schedule_settings' => get_option( 'woocommerce_pre_order_schedule_settings' ),
	'yoohw_pre_orders_wc_variable_visibility_repaired' => get_option( 'yoohw_pre_orders_wc_variable_visibility_repaired' ),
];
$mail              = [];
$delivery_succeeds = true;
$mail_filter       = static function ( $return, $message ) use ( &$mail, &$delivery_succeeds ) {
	$mail[] = $message;
	return $delivery_succeeds;
};
add_filter( 'pre_wp_mail', $mail_filter, 10, 2 );

try {
	yoohw_po_audit_assert( class_exists( 'YoOhw_Pre_Orders_WC_Preorder_Limits' ), 'Activate Pre-Orders and WooCommerce before running this test.' );
	yoohw_po_audit_assert( class_exists( 'YoOhw_Pre_Orders_WC_Customer_Actions_Blocks', false ), 'The checkout block did not register the delivery preference script integration.' );
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	yoohw_po_audit_assert( ! empty( $admins ), 'The disposable site needs an administrator for variation saves.' );
	wp_set_current_user( $admins[0] );
	wc_load_cart();
	$cart = WC()->cart;

	$products[]  = $preorder = yoohw_po_audit_simple_product( 'PO audit preorder fixture', 'onpreorder' );
	$products[]  = $regular = yoohw_po_audit_simple_product( 'PO audit regular fixture', 'instock' );
	$preorder_id = $preorder->get_id();
	$regular_id  = $regular->get_id();
	$future      = wp_date( 'Y-m-d\TH:i', time() + 10 * DAY_IN_SECONDS );
	$later       = wp_date( 'Y-m-d\TH:i', time() + 15 * DAY_IN_SECONDS );
	$past        = wp_date( 'Y-m-d\TH:i', time() - 2 * MINUTE_IN_SECONDS );

	yoohw_po_audit_assert_limits( $preorder, $cart );

	update_option( 'yoess_pre_order_email_to_admin', 'yes' );
	update_option( 'yoess_pre_order_email_to_admin_timer', 3 );
	yoohw_po_audit_save_date( $preorder_id, $future );
	$initial_stock_event = wp_next_scheduled( $stock_hook, [ $preorder_id ] );
	$initial_admin_event = wp_next_scheduled( $admin_hook, [ $preorder_id ] );
	yoohw_po_audit_assert( $initial_stock_event && $initial_admin_event, 'A future preorder date must schedule both events.' );
	yoohw_po_audit_save_date( $preorder_id, $later );
	yoohw_po_audit_assert( $initial_stock_event < wp_next_scheduled( $stock_hook, [ $preorder_id ] ) && $initial_admin_event < wp_next_scheduled( $admin_hook, [ $preorder_id ] ), 'Postponing availability did not move both cron events.' );
	$mail = [];
	do_action( $stock_hook, $preorder_id );
	do_action( $admin_hook, $preorder_id );
	yoohw_po_audit_assert( 'onpreorder' === wc_get_product( $preorder_id )->get_stock_status() && [] === $mail, 'Stale callbacks acted before the current schedule.' );
	yoohw_po_audit_save_date( $preorder_id, '' );
	yoohw_po_audit_assert( ! wp_next_scheduled( $stock_hook, [ $preorder_id ] ) && ! wp_next_scheduled( $admin_hook, [ $preorder_id ] ), 'Clearing the date left scheduled preorder events.' );
	do_action( $stock_hook, $preorder_id );
	do_action( $admin_hook, $preorder_id );
	yoohw_po_audit_assert( 'onpreorder' === wc_get_product( $preorder_id )->get_stock_status() && [] === $mail, 'A stale callback changed stock or sent mail after the date was cleared.' );

	$products[] = $same_day = yoohw_po_audit_simple_product( 'PO audit same-day reminder fixture', 'onpreorder' );
	$same_day_id = $same_day->get_id();
	$past_time   = yoohw_pre_orders_wc_parse_available_date( $past )->getTimestamp();
	update_option( 'yoess_pre_order_email_to_admin_timer', 0 );
	yoohw_pre_orders_wc_update_product_available_date( $same_day_id, $past );
	wp_schedule_single_event( $past_time, $admin_hook, [ $same_day_id ] );
	do_action( $stock_hook, $same_day_id );
	do_action( $admin_hook, $same_day_id );
	yoohw_po_audit_assert( 1 === count( $mail ) && 'instock' === wc_get_product( $same_day_id )->get_stock_status(), 'The old same-day schedule did not send one admin reminder before the stock transition.' );
	update_option( 'yoess_pre_order_email_to_admin_timer', 3 );

	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Size' );
	$attribute->set_options( [ 'Small' ] );
	$attribute->set_variation( true );
	$products[] = $parent = new WC_Product_Variable();
	$parent->set_name( 'PO audit variable fixture' );
	$parent->set_status( 'publish' );
	$parent->set_attributes( [ $attribute ] );
	$parent->save();
	$products[] = $variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent->get_id() );
	$variation->set_status( 'publish' );
	$variation->set_attributes( [ 'size' => 'Small' ] );
	$variation->set_regular_price( '10' );
	$variation->set_stock_status( 'onpreorder' );
	$variation->save();

	update_option( 'woocommerce_hide_out_of_stock_items', 'yes' );
	WC_Product_Variable::sync( $parent->get_id() );
	$parent = wc_get_product( $parent->get_id() );
	yoohw_po_audit_assert( 'onpreorder' === $parent->get_stock_status() && $parent->is_visible(), 'An orderable preorder-only variable product disappeared from the catalog.' );
	yoohw_po_audit_assert( ! has_term( 'outofstock', 'product_visibility', $parent->get_id() ), 'The preorder-only parent retained the catalog exclusion term.' );

	// Persist the parent state produced by the previous PO version.
	$old_parent_id = $parent->get_id();
	$old_sync      = static function ( $product ) use ( $old_parent_id ) {
		if ( $old_parent_id === $product->get_id() ) {
			$product->set_stock_status( 'outofstock' );
		}
	};
	add_action( 'woocommerce_before_product_object_save', $old_sync, 99 );
	WC_Product_Variable::sync_stock_status( $parent );
	remove_action( 'woocommerce_before_product_object_save', $old_sync, 99 );
	yoohw_po_audit_assert( has_term( 'outofstock', 'product_visibility', $old_parent_id ), 'The upgrade fixture must have the old catalog exclusion term.' );
	delete_option( 'yoohw_pre_orders_wc_variable_visibility_repaired' );
	YoOhw_Pre_Orders_WC_Single_Product_Stock_Status::repair_variable_preorder_visibility();
	$parent = wc_get_product( $old_parent_id );
	yoohw_po_audit_assert( 'onpreorder' === $parent->get_stock_status() && $parent->is_visible(), 'The upgrade did not repair a previously hidden preorder-only product.' );

	yoohw_po_audit_run_variation_save( $parent, $variation, $future );
	yoohw_po_audit_assert( $future === get_post_meta( $variation->get_id(), '_pre_order_available_date', true ) && $future === get_post_meta( $variation->get_id(), '_extended_status_available_date', true ), 'WooCommerce AJAX did not save both variation date keys, or a caller without edit rights overwrote them.' );
	yoohw_po_audit_assert( wp_next_scheduled( $stock_hook, [ $variation->get_id() ] ), 'Saving the variation date did not schedule its stock transition.' );
	$variation = wc_get_product( $variation->get_id() );
	$variation->set_stock_status( 'outofstock' );
	$variation->save();
	$parent = WC_Product_Variable::sync_stock_status( $parent->get_id() );
	yoohw_po_audit_assert( 'outofstock' === $parent->get_stock_status() && ! $parent->is_visible(), 'Parent stayed visible after its last preorder variation became unavailable.' );

	$preorder_key = $cart->add_to_cart( $preorder_id, 1 );
	$cart->add_to_cart( $regular_id, 1 );
	$checkout    = new OrderController();
	$orders[]    = $draft = $checkout->create_order_from_cart();
	$classic_id  = WC()->checkout()->create_order( [ 'payment_method' => '', 'billing_email' => 'po-audit-classic@example.com' ] );
	yoohw_po_audit_assert( ! is_wp_error( $classic_id ) && $classic_id > 0, 'Classic checkout did not create an order fixture.' );
	$orders[] = $classic_order = wc_get_order( $classic_id );
	yoohw_po_audit_assert( [ $preorder_id ] === yoohw_pre_orders_wc_get_tracked_product_ids( $draft ) && [ $preorder_id ] === yoohw_pre_orders_wc_get_tracked_product_ids( $classic_order ), 'Classic and Blocks should track the preorder at checkout.' );

	yoohw_pre_orders_wc_update_product_available_date( $preorder_id, $later );
	$regular->set_stock_status( 'onpreorder' );
	$regular->save();
	foreach ( [ $draft, $classic_order ] as $order ) {
		$order = wc_get_order( $order->get_id() );
		yoohw_po_audit_assert_history( $order, $regular_id );
		yoohw_po_audit_assert( yoohw_pre_orders_wc_order_is_mixed( $order ), 'A later stock change altered the historical mixed-order classification.' );
		foreach ( $order->get_items() as $item ) {
			if ( $preorder_id === $item->get_product_id() ) {
				yoohw_po_audit_assert( '' === yoohw_pre_orders_wc_get_order_item_available_date( $item ), 'Adding a catalog date changed a historical preorder that had no date.' );
			}
		}
	}
	foreach ( $classic_order->get_items() as $item ) {
		if ( $regular_id === $item->get_product_id() ) {
			$item->delete_meta_data( '_wcess_stock_status' );
			$item->save();
			yoohw_po_audit_assert( 'onpreorder' !== yoohw_pre_orders_wc_get_order_item_stock_status( $item ), 'An older mixed order reclassified its regular product after the catalog changed.' );
		}
	}
	$regular->set_stock_status( 'instock' );
	$regular->save();

	$cart->remove_cart_item( $preorder_key );
	$checkout->update_order_from_cart( $draft );
	$draft->save();
	$draft = wc_get_order( $draft->get_id() );
	yoohw_po_audit_assert( 1 === count( $draft->get_items() ) && [] === yoohw_pre_orders_wc_get_tracked_product_ids( $draft ) && '' === $draft->get_meta( '_pre_order_marked', true ), 'A regular-only Blocks draft retained preorder tracking.' );
	$draft->update_meta_data( '_pre_order_items', [ $preorder_id ] );
	$draft->update_meta_data( '_pre_order_marked', '1' );
	$draft->save();
	$checkout->update_order_from_cart( $draft );
	do_action( 'woocommerce_store_api_checkout_update_order_meta', $draft );
	yoohw_po_audit_assert( [] === yoohw_pre_orders_wc_get_tracked_product_ids( wc_get_order( $draft->get_id() ) ), 'An older Blocks draft kept stale tracking when its cart hash was unchanged.' );
	$cart->add_to_cart( $preorder_id, 1 );
	$checkout->update_order_from_cart( $draft );
	$draft->save();
	yoohw_po_audit_assert( [ $preorder_id ] === yoohw_pre_orders_wc_get_tracked_product_ids( wc_get_order( $draft->get_id() ) ), 'Blocks draft did not rebuild tracking when a preorder returned to the cart.' );
	$cart->empty_cart();
	$checkout->update_order_from_cart( $draft );
	$draft->save();
	$draft = wc_get_order( $draft->get_id() );
	yoohw_po_audit_assert( [] === $draft->get_items() && [] === yoohw_pre_orders_wc_get_tracked_product_ids( $draft ) && '' === $draft->get_meta( '_pre_order_marked', true ), 'An empty Blocks draft retained preorder tracking.' );

	$classic_order->set_status( 'completed' );
	$classic_order->save();
	$orders[] = $mail_order = wc_create_order();
	$mail_order->set_billing_email( 'po-audit-mail@example.com' );
	$mail_order->add_product( wc_get_product( $preorder_id ), 1 );
	$mail_order->update_meta_data( '_pre_order_items', [ $preorder_id, $regular_id ] );
	$mail_order->save();
	update_option( 'woocommerce_pre_order_schedule_settings', [ 'enabled' => 'yes' ] );
	yoohw_pre_orders_wc_update_product_available_date( $preorder_id, $past );
	$mail              = [];
	$delivery_succeeds = false;
	do_action( $stock_hook, $preorder_id );
	yoohw_po_audit_assert( 'instock' === wc_get_product( $preorder_id )->get_stock_status(), 'A mail failure blocked the due stock transition.' );
	yoohw_po_audit_assert( 1 === count( $mail ) && [ $preorder_id, $regular_id ] === yoohw_pre_orders_wc_get_tracked_product_ids( wc_get_order( $mail_order->get_id() ) ), 'A failed send lost the pending notification or caused multiple immediate sends.' );
	yoohw_po_audit_assert( wp_next_scheduled( $stock_hook, [ $preorder_id, true ] ), 'A failed send did not schedule the notification retry.' );
	$notes = wp_list_pluck( wc_get_order_notes( [ 'order_id' => $mail_order->get_id() ] ), 'content' );
	yoohw_po_audit_assert( false === strpos( implode( "\n", $notes ), 'the customer has been notified' ), 'The order falsely claimed that the failed message was sent.' );

	// Run the retry callback as WP-Cron does after removing the single event.
	wp_clear_scheduled_hook( $stock_hook, [ $preorder_id, true ] );
	$delivery_succeeds = true;
	$other_update      = static function ( $sent ) use ( $mail_order, $preorder_id ) {
		$fresh = wc_get_order( $mail_order->get_id() );
		$fresh->update_meta_data( '_pre_order_items', [ $preorder_id ] );
		$fresh->save();
		return $sent;
	};
	add_filter( 'pre_wp_mail', $other_update, 20 );
	try {
		do_action( $stock_hook, $preorder_id, true );
	} finally {
		remove_filter( 'pre_wp_mail', $other_update, 20 );
	}
	do_action( $stock_hook, $preorder_id, true );
	yoohw_po_audit_assert( 2 === count( $mail ) && [] === yoohw_pre_orders_wc_get_tracked_product_ids( wc_get_order( $mail_order->get_id() ) ), 'The retry did not acknowledge a successful send exactly once.' );
	yoohw_po_audit_assert( ! wp_next_scheduled( $stock_hook, [ $preorder_id, true ] ), 'Successful notification kept scheduling retries.' );

	echo "Pre-Orders audit regression workflows passed.\n";
} catch ( Throwable $exception ) {
	$failure = $exception;
} finally {
	if ( $cart ) {
		$cart->empty_cart();
	}
	foreach ( $orders as $order ) {
		$order->delete( true );
	}
	foreach ( array_reverse( $products ) as $product ) {
		$id = $product->get_id();
		wp_clear_scheduled_hook( $stock_hook, [ $id ] );
		wp_clear_scheduled_hook( $stock_hook, [ $id, true ] );
		wp_clear_scheduled_hook( $admin_hook, [ $id ] );
		$product->delete( true );
	}
	foreach ( $options as $option => $value ) {
		if ( false === $value ) {
			delete_option( $option );
		} else {
			update_option( $option, $value );
		}
	}
	remove_filter( 'pre_wp_mail', $mail_filter, 10 );
	wp_set_current_user( $old_user );
}

if ( $failure instanceof Throwable ) {
	fwrite( STDERR, 'Pre-Orders audit regression workflows failed: ' . $failure->getMessage() . "\n" );
	exit( 1 );
}
