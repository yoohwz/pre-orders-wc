<?php
/**
 * Product-level completion and payment restriction integration tests.
 * Run with WP-CLI on a disposable WordPress installation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this file through WP-CLI.\n" );
	exit( 1 );
}

function yoohw_po_operations_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function yoohw_po_operations_product( $name, $status, $price = '12' ) {
	$product = new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_status( 'publish' );
	$product->set_virtual( true );
	$product->set_regular_price( $price );
	$product->set_stock_status( $status );
	$product->save();

	return $product;
}

function yoohw_po_operations_order( $product ) {
	$order = wc_create_order( [ 'status' => 'pending' ] );
	$order->set_billing_email( 'preorder-operations@example.test' );
	$order->add_product( $product, 1 );
	// Earlier Free orders used CSV tracking and no permanent marker.
	$order->update_meta_data( '_pre_order_items', (string) $product->get_id() );
	$order->save();

	return $order;
}

function yoohw_po_operations_classic_errors( $payment_method, array $billing ) {
	$data = [
		'payment_method'            => $payment_method,
		'terms'                     => 1,
		'ship_to_different_address' => 0,
	];
	foreach ( $billing as $name => $value ) {
		$data[ 'billing_' . $name ] = $value;
	}

	$errors = new WP_Error();
	$validate = new ReflectionMethod( WC_Checkout::class, 'validate_checkout' );
	$validate->setAccessible( true );
	$validate->invokeArgs( WC()->checkout(), [ &$data, &$errors ] );

	return $errors;
}

function yoohw_po_operations_store_response( $method, $route, array $body = [] ) {
	$request = new WP_REST_Request( $method, '/wc/store/v1/' . $route );
	if ( 'POST' === $method ) {
		$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
	}

	return rest_do_request( $request );
}

$products      = [];
$orders        = [];
$cart          = null;
$failure       = null;
$messages      = [];
$delivery      = true;
$email_enabled = true;
$previous_user = get_current_user_id();
$stock_hook    = 'wc_pre_orders_update_stock_status';
$reminder_hook = 'wc_pre_orders_email_admin_preorder_availability';
$mode_option   = 'yoohw_pre_orders_completion_mode';
$payments      = 'yoohw_pre_orders_excluded_gateways';
$reconcile     = 'yoohw_pre_orders_reconcile_auto_schedule';
$options       = [];
foreach ( [
	$mode_option, $payments, 'yoess_pre_order_email_to_admin', 'yoess_pre_order_email_to_admin_timer',
	'woocommerce_bacs_settings', 'woocommerce_cheque_settings',
] as $option ) {
	$options[ $option ] = get_option( $option );
}
$mail_filter   = static function ( $return, $message ) use ( &$messages, &$delivery ) {
	$messages[] = $message;
	return $delivery;
};
$email_filter  = static function () use ( &$email_enabled ) {
	return $email_enabled;
};
$order_capture = static function ( $order ) use ( &$orders ) {
	if ( $order instanceof WC_Order && $order->get_id() ) {
		$orders[ $order->get_id() ] = $order;
	}
};
add_filter( 'pre_wp_mail', $mail_filter, 10, 2 );
add_filter( 'woocommerce_email_enabled_pre_order_schedule', $email_filter );
add_action( 'woocommerce_store_api_checkout_update_order_meta', $order_capture );
add_action( 'woocommerce_store_api_checkout_order_processed', $order_capture );

try {
	yoohw_po_operations_assert( class_exists( 'YoOhw_Pre_Orders_WC_Payment_Methods' ), 'WooCommerce and Pre-Orders must be active.' );
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	yoohw_po_operations_assert( ! empty( $admins ), 'The test site needs an administrator.' );
	wp_set_current_user( $admins[0] );

	$scheduler = YoOhw_Pre_Orders_WC_Auto_Update_To_In_Stock::class;
	delete_option( $mode_option );
	yoohw_po_operations_assert( ! $scheduler::is_manual(), 'Missing completion setting must default to automatic.' );
	$products[] = $dated = yoohw_po_operations_product( 'PO due by date', 'onpreorder' );
	$products[] = $future = yoohw_po_operations_product( 'PO later date', 'onpreorder' );
	$products[] = $manual = yoohw_po_operations_product( 'PO awaiting confirmation', 'onpreorder' );
	$past_date  = wp_date( 'Y-m-d\TH:i', time() - HOUR_IN_SECONDS );
	$future_date = wp_date( 'Y-m-d\TH:i', time() + 5 * DAY_IN_SECONDS );
	yoohw_pre_orders_wc_update_product_available_date( $dated->get_id(), $past_date );
	yoohw_pre_orders_wc_update_product_available_date( $future->get_id(), $future_date );
	$scheduler::schedule_event_for_product( $dated->get_id() );
	yoohw_po_operations_assert( wp_next_scheduled( $stock_hook, [ $dated->get_id() ] ), 'Automatic mode did not schedule the dated product.' );
	yoohw_po_operations_assert( is_wp_error( $scheduler::confirm_available( $dated->get_id() ) ), 'Manual confirmation was available in automatic mode.' );

	update_option( $mode_option, 'manual' );
	do_action( $stock_hook, $dated->get_id() );
	yoohw_po_operations_assert( 'onpreorder' === wc_get_product( $dated->get_id() )->get_stock_status(), 'A previously queued cron completed a preorder in manual mode.' );
	yoohw_po_operations_assert( $past_date === yoohw_pre_orders_wc_get_product_available_date( $dated->get_id() ), 'A stale cron cleared the expected date in manual mode.' );
	$scheduler::schedule_event_for_product( $dated->get_id() );
	yoohw_po_operations_assert( ! wp_next_scheduled( $stock_hook, [ $dated->get_id() ] ), 'Saving a date in manual mode queued an automatic transition.' );

	update_option( 'yoess_pre_order_email_to_admin', 'yes' );
	update_option( 'yoess_pre_order_email_to_admin_timer', 2 );
	YoOhw_Pre_Orders_WC_Email_Available_Product_Admin::schedule_event_for_available_product_admin( $future->get_id() );
	yoohw_po_operations_assert( wp_next_scheduled( $reminder_hook, [ $future->get_id() ] ), 'Manual mode suppressed an administrator date reminder.' );
	do_action( $reminder_hook, $dated->get_id() );
	yoohw_po_operations_assert( 1 === count( $messages ), 'A due administrator reminder did not send in manual mode.' );

	wp_set_current_user( 0 );
	yoohw_po_operations_assert( is_wp_error( $scheduler::confirm_available( $manual->get_id() ) ), 'An unauthorized caller completed a preorder.' );
	wp_set_current_user( $admins[0] );

	$products[] = $managed = yoohw_po_operations_product( 'PO managed stock without inventory', 'instock' );
	$managed->set_manage_stock( true );
	$managed->set_stock_quantity( 0 );
	$managed->save();
	// Recreate managed preorder metadata already present in a shop catalog.
	update_post_meta( $managed->get_id(), '_stock_status', 'onpreorder' );
	$insufficient = $scheduler::confirm_available( $managed->get_id() );
	$managed_now = wc_get_product( $managed->get_id() );
	yoohw_po_operations_assert( is_wp_error( $insufficient ) && 'preorder_stock_insufficient' === $insufficient->get_error_code(), 'Manual confirmation accepted a stock-managed product without available stock.' );
	yoohw_po_operations_assert( 'onpreorder' === $managed_now->get_stock_status() && 0 === (int) $managed_now->get_stock_quantity(), 'Refusing a managed preorder modified its status or quantity.' );

	// Hold the real lock on a distinct database connection.
	global $wpdb;
	$lock_name = 'yoohw_po_' . md5( DB_NAME . ':' . $wpdb->prefix );
	$connection = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	yoohw_po_operations_assert( '1' === (string) $connection->get_var( $connection->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) ), 'Could not acquire the fixture advisory lock.' );
	try {
		$busy = $scheduler::confirm_available( $manual->get_id() );
		yoohw_po_operations_assert( is_wp_error( $busy ) && 'preorder_completion_busy' === $busy->get_error_code(), 'An overlapping completion proceeded while the site lock was held.' );
		yoohw_po_operations_assert( 'onpreorder' === wc_get_product( $manual->get_id() )->get_stock_status(), 'Lock contention changed the product.' );
	} finally {
		$connection->get_var( $connection->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		$connection->close();
	}

	$order = yoohw_po_operations_order( $manual );
	$orders[ $order->get_id() ] = $order;
	$messages = [];
	$delivery = false;
	yoohw_po_operations_assert( true === $scheduler::confirm_available( $manual->get_id() ), 'Manual confirmation of an undated product failed.' );
	yoohw_po_operations_assert( 'instock' === wc_get_product( $manual->get_id() )->get_stock_status() && null === wc_get_product( $manual->get_id() )->get_stock_quantity(), 'Manual completion did not update the stock status safely.' );
	yoohw_po_operations_assert( 1 === count( $messages ) && wp_next_scheduled( $stock_hook, [ $manual->get_id(), true ] ), 'Manual mail failure did not leave a pending retry.' );
	yoohw_po_operations_assert( [ $manual->get_id() ] === yoohw_pre_orders_wc_get_tracked_product_ids( wc_get_order( $order->get_id() ) ), 'Manual failure erased pending order tracking.' );
	yoohw_po_operations_assert( is_wp_error( $scheduler::confirm_available( $manual->get_id() ) ), 'A repeated confirmation succeeded for a completed preorder.' );
	wp_clear_scheduled_hook( $stock_hook, [ $manual->get_id(), true ] );
	$delivery = true;
	do_action( $stock_hook, $manual->get_id(), true );
	do_action( $stock_hook, $manual->get_id(), true );
	$order = wc_get_order( $order->get_id() );
	yoohw_po_operations_assert( 2 === count( $messages ), 'Completion or retry sent duplicate customer notifications.' );
	yoohw_po_operations_assert( [] === yoohw_pre_orders_wc_get_tracked_product_ids( $order ) && '1' === $order->get_meta( '_pre_order_marked' ), 'A completed legacy order lost its permanent preorder marker.' );
	$legacy_line = current( $order->get_items() );
	yoohw_po_operations_assert( 'onpreorder' === $legacy_line->get_meta( '_wcess_stock_status' ) && ! $legacy_line->meta_exists( '_wcess_available_date' ), 'Completion lost verified legacy line status or invented a checkout date.' );
	yoohw_po_operations_assert( 'yoohw-only-preorder' === YoOhw_Pre_Orders_WC_Orders::get_order_class( $order ), 'Legacy preorder-only badge changed after completion.' );
	yoohw_po_operations_assert( 'pending' === $order->get_status(), 'Completion modified the order status.' );

	$products[] = $interrupted = yoohw_po_operations_product( 'PO interrupted notification', 'onpreorder' );
	$order = yoohw_po_operations_order( $interrupted );
	$orders[ $order->get_id() ] = $order;
	$interrupt_mail = static function () {
		throw new RuntimeException( 'Fixture interruption before mail delivery.' );
	};
	add_filter( 'pre_wp_mail', $interrupt_mail, 20 );
	try {
		yoohw_po_operations_assert( true === $scheduler::confirm_available( $interrupted->get_id() ), 'A mail interruption prevented reporting the committed stock transition.' );
	} finally {
		remove_filter( 'pre_wp_mail', $interrupt_mail, 20 );
	}
	$interrupted_args = [ $interrupted->get_id(), true ];
	yoohw_po_operations_assert( wp_next_scheduled( $stock_hook, $interrupted_args ) && [ $interrupted->get_id() ] === yoohw_pre_orders_wc_get_tracked_product_ids( wc_get_order( $order->get_id() ) ), 'An interrupted mail pass had no pending recovery.' );
	wp_clear_scheduled_hook( $stock_hook, $interrupted_args );
	do_action( $stock_hook, ...$interrupted_args );
	yoohw_po_operations_assert( [] === yoohw_pre_orders_wc_get_tracked_product_ids( wc_get_order( $order->get_id() ) ), 'Recovery did not finish the interrupted order tracking.' );

	$products[] = $parent = new WC_Product_Variable();
	$parent->set_name( 'PO variable parent' );
	$parent->set_status( 'publish' );
	$attribute = new WC_Product_Attribute();
	$attribute->set_name( 'Size' );
	$attribute->set_options( [ 'Small', 'Large' ] );
	$attribute->set_variation( true );
	$parent->set_attributes( [ $attribute ] );
	$parent->save();
	foreach ( [ 'Small', 'Large' ] as $size ) {
		$products[] = $variation = new WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_status( 'publish' );
		$variation->set_attributes( [ 'size' => $size ] );
		$variation->set_virtual( true );
		$variation->set_regular_price( '12' );
		$variation->set_stock_status( 'onpreorder' );
		$variation->save();
		$variations[ $size ] = $variation;
	}
	WC_Product_Variable::sync( $parent->get_id() );
	$variation = $variations['Small'];
	$order = yoohw_po_operations_order( $variation );
	$orders[ $order->get_id() ] = $order;
	$email_enabled = false;
	$messages = [];
	yoohw_po_operations_assert( true === $scheduler::confirm_available( $variation->get_id() ), 'Manual confirmation failed for a variation.' );
	yoohw_po_operations_assert( [] === $messages && [] === yoohw_pre_orders_wc_get_tracked_product_ids( wc_get_order( $order->get_id() ) ), 'Disabled mail affected variation completion or sent a message.' );
	yoohw_po_operations_assert( 'instock' === wc_get_product( $variation->get_id() )->get_stock_status() && 'onpreorder' === wc_get_product( $variations['Large']->get_id() )->get_stock_status(), 'Completing a variation changed a different variation.' );

	update_option( $mode_option, 'auto_by_date' );
	yoohw_po_operations_assert( wp_next_scheduled( $reconcile, [ 0 ] ), 'Returning to automatic mode did not queue reconciliation.' );
	wp_clear_scheduled_hook( $reconcile, [ 0 ] );
	do_action( $reconcile, 0 );
	yoohw_po_operations_assert( wp_next_scheduled( $stock_hook, [ $dated->get_id() ] ) && wp_next_scheduled( $stock_hook, [ $future->get_id() ] ), 'Reconciliation did not queue current dated preorders.' );
	yoohw_po_operations_assert( ! wp_next_scheduled( $stock_hook, [ $variations['Large']->get_id() ] ), 'Reconciliation queued an undated variation.' );
	do_action( $stock_hook, $future->get_id() );
	do_action( $stock_hook, $dated->get_id() );
	yoohw_po_operations_assert( 'onpreorder' === wc_get_product( $future->get_id() )->get_stock_status() && 'instock' === wc_get_product( $dated->get_id() )->get_stock_status(), 'Automatic mode did not distinguish upcoming from overdue dates.' );

	$products[] = $moved = yoohw_po_operations_product( 'PO date moved during reminder', 'onpreorder' );
	$moved_id = $moved->get_id();
	$moved_to = wp_date( 'Y-m-d\TH:i', time() + DAY_IN_SECONDS );
	update_option( 'yoess_pre_order_email_to_admin', 'yes' );
	update_option( 'yoess_pre_order_email_to_admin_timer', 0 );
	yoohw_pre_orders_wc_update_product_available_date( $moved_id, wp_date( 'Y-m-d\TH:i', time() - 2 * MINUTE_IN_SECONDS ) );
	wp_schedule_single_event( time() - MINUTE_IN_SECONDS, $reminder_hook, [ $moved_id ] );
	$move_during_mail = static function ( $result ) use ( $scheduler, $moved_id, $moved_to ) {
		yoohw_pre_orders_wc_update_product_available_date( $moved_id, $moved_to );
		$scheduler::schedule_event_for_product( $moved_id );
		return $result;
	};
	add_filter( 'pre_wp_mail', $move_during_mail, 20 );
	try {
		do_action( $stock_hook, $moved_id );
	} finally {
		remove_filter( 'pre_wp_mail', $move_during_mail, 20 );
	}
	yoohw_po_operations_assert( 'onpreorder' === wc_get_product( $moved_id )->get_stock_status() && $moved_to === yoohw_pre_orders_wc_get_product_available_date( $moved_id ), 'An in-flight reminder allowed the old cron to finish a postponed preorder.' );

	wc_load_cart();
	$cart = WC()->cart;
	$products[] = $regular = yoohw_po_operations_product( 'PO ordinary cart line', 'instock' );
	$products[] = $free = yoohw_po_operations_product( 'PO zero total line', 'onpreorder', '0' );
	// Built-in offline methods are registered in Classic and Blocks even on the minimum stack.
	$gateways = [ 'bacs', 'cheque' ];
	update_option( 'woocommerce_bacs_settings', [ 'enabled' => 'yes' ] );
	update_option( 'woocommerce_cheque_settings', [ 'enabled' => 'yes' ] );
	add_filter( 'woocommerce_available_payment_gateways', static function ( $available ) use ( $gateways ) {
		return array_intersect_key( $available, array_flip( $gateways ) );
	}, 1 );
	WC()->payment_gateways()->init();
	$billing = [
		'first_name' => 'Test',
		'last_name'  => 'Shopper',
		'address_1'  => '1 Test Street',
		'city'       => 'New York',
		'state'      => 'NY',
		'postcode'   => '10001',
		'country'    => 'US',
		'email'      => 'preorder-operations@example.test',
		'phone'      => '2125550100',
	];
	$checkout = [ 'billing_address' => $billing, 'payment_method' => 'bacs' ];
	$cart->add_to_cart( $regular->get_id() );
	$variation = $variations['Large'];
	$preorder_key = $cart->add_to_cart( $parent->get_id(), 1, $variation->get_id(), [ 'attribute_size' => 'Large' ] );
	yoohw_po_operations_assert( $preorder_key && $cart->needs_payment(), 'The mixed paid cart fixture could not be built.' );
	update_option( $payments, [] );
	yoohw_po_operations_assert( 2 === count( WC()->payment_gateways()->get_available_payment_gateways() ), 'Empty exclusions changed native availability.' );
	update_option( $payments, [ 'bacs' ] );
	yoohw_po_operations_assert( [ 'cheque' ] === array_keys( WC()->payment_gateways()->get_available_payment_gateways() ), 'Classic displayed the excluded method for a mixed cart with a preorder variation.' );
	yoohw_po_operations_assert( yoohw_po_operations_classic_errors( 'bacs', $billing )->get_error_message( 'payment' ), 'Classic server validation accepted an excluded method.' );
	yoohw_po_operations_assert( ! yoohw_po_operations_classic_errors( 'cheque', $billing )->get_error_message( 'payment' ), 'Classic server validation rejected an available method.' );
	$response = yoohw_po_operations_store_response( 'GET', 'cart' );
	yoohw_po_operations_assert( 200 === $response->get_status() && [ 'cheque' ] === ( $response->get_data()['payment_methods'] ?? null ), 'Blocks cart did not advertise the filtered payment methods.' );
	$response = yoohw_po_operations_store_response( 'POST', 'checkout', $checkout );
	yoohw_po_operations_assert( 400 === $response->get_status() && 'woocommerce_rest_checkout_payment_method_disabled' === ( $response->get_data()['code'] ?? null ), 'Blocks checkout did not reject the excluded method on the server: ' . wp_json_encode( $response->get_data() ) );

	$existing_checkout_route = array_filter( array_keys( rest_get_server()->get_routes() ), static function ( $route ) {
		return 0 === strpos( $route, '/wc/store/v1/checkout/(?P<id>' );
	} );
	if ( $existing_checkout_route ) {
		$existing_order = wc_create_order( [ 'customer_id' => get_current_user_id() ] );
		$orders[ $existing_order->get_id() ] = $existing_order;
		$existing_order->add_product( $regular );
		$existing_order->set_billing_address( $billing );
		$existing_order->calculate_totals();
		$existing_order->save();
		$existing_payment = $checkout + [
			'key'              => $existing_order->get_order_key(),
			'shipping_address' => array_diff_key( $billing, [ 'email' => true ] ),
		];
		$existing_response = yoohw_po_operations_store_response( 'POST', 'checkout/' . $existing_order->get_id(), $existing_payment );
		yoohw_po_operations_assert( 200 === $existing_response->get_status() && 'bacs' === wc_get_order( $existing_order->get_id() )->get_payment_method(), 'Existing-order payment was blocked by the unrelated preorder cart: ' . wp_json_encode( $existing_response->get_data() ) );
		// The core bank transfer gateway empties the cart when it accepts an order.
		$cart = WC()->cart;
		if ( ! $cart->get_cart_item( $preorder_key ) ) {
			$cart->add_to_cart( $regular->get_id() );
			$preorder_key = $cart->add_to_cart( $parent->get_id(), 1, $variation->get_id(), [ 'attribute_size' => 'Large' ] );
		}
		yoohw_po_operations_assert( [ 'cheque' ] === array_keys( WC()->payment_gateways()->get_available_payment_gateways() ), 'Existing-order payment left exclusions disabled for the cart.' );
	}

	update_option( $payments, $gateways );
	yoohw_po_operations_assert( [] === WC()->payment_gateways()->get_available_payment_gateways(), 'Checkout silently restored an excluded gateway.' );
	$response = yoohw_po_operations_store_response( 'GET', 'cart' );
	yoohw_po_operations_assert( 200 === $response->get_status() && [] === ( $response->get_data()['payment_methods'] ?? null ), 'Blocks cart restored an excluded method when the list was empty.' );
	$notice = apply_filters( 'woocommerce_no_available_payment_methods_message', 'default notice' );
	yoohw_po_operations_assert( 'default notice' !== $notice && false !== strpos( $notice, 'preorder' ), 'Classic did not explain the unavailable-payment state.' );
	unset( $checkout['payment_method'] );
	$response = yoohw_po_operations_store_response( 'POST', 'checkout', $checkout );
	yoohw_po_operations_assert( 400 === $response->get_status() && 'woocommerce_rest_checkout_missing_payment_method' === ( $response->get_data()['code'] ?? null ), 'Blocks did not reject a payable preorder with no available gateway: ' . wp_json_encode( $response->get_data() ) );

	$cart->remove_cart_item( $preorder_key );
	$response = yoohw_po_operations_store_response( 'GET', 'cart' );
	$available = $response->get_data()['payment_methods'] ?? [];
	sort( $available );
	yoohw_po_operations_assert( [ 'bacs', 'cheque' ] === $available, 'Removing preorder items did not restore WooCommerce gateway availability.' );

	$cart->empty_cart();
	$cart->add_to_cart( $free->get_id() );
	yoohw_po_operations_assert( ! $cart->needs_payment() && ! yoohw_po_operations_classic_errors( '', $billing )->get_error_message( 'payment' ), 'A zero-total preorder incorrectly required payment in Classic.' );
	$response = yoohw_po_operations_store_response( 'POST', 'checkout', $checkout );
	yoohw_po_operations_assert( 200 === $response->get_status(), 'A zero-total preorder incorrectly required payment in Blocks: ' . wp_json_encode( $response->get_data() ) );

	echo "Preorder completion and payment integration tests passed.\n";
} catch ( Throwable $error ) {
	$failure = $error;
} finally {
	if ( $cart ) {
		$cart->empty_cart();
		if ( WC()->session ) {
			$draft = wc_get_order( WC()->session->get( 'store_api_draft_order', 0 ) );
			if ( $draft && $draft->has_status( 'checkout-draft' ) ) {
				$orders[ $draft->get_id() ] = $draft;
			}
			WC()->session->set( 'store_api_draft_order', null );
		}
	}
	foreach ( $orders as $order ) {
		$order->delete( true );
	}
	foreach ( array_reverse( $products ) as $product ) {
		$id = $product->get_id();
		wp_clear_scheduled_hook( $stock_hook, [ $id ] );
		wp_clear_scheduled_hook( $stock_hook, [ $id, true ] );
		wp_clear_scheduled_hook( $reminder_hook, [ $id ] );
		wp_clear_scheduled_hook( $reconcile, [ $id ] );
		$product->delete( true );
	}
	foreach ( $options as $option => $value ) {
		if ( false === $value ) {
			delete_option( $option );
		} else {
			update_option( $option, $value );
		}
	}
	wp_clear_scheduled_hook( $reconcile, [ 0 ] );
	remove_filter( 'pre_wp_mail', $mail_filter, 10 );
	remove_filter( 'woocommerce_email_enabled_pre_order_schedule', $email_filter );
	wp_set_current_user( $previous_user );
}
if ( $failure ) {
	fwrite( STDERR, 'Preorder completion and payment integration failed: ' . $failure->getMessage() . "\n" );
	exit( 1 );
}
