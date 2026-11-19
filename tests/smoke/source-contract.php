<?php
/**
 * Pure source-contract checks that do not require WordPress.
 */

$root = dirname( __DIR__, 2 );

function yoohw_preorders_source_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

$main          = file_get_contents( $root . '/pre-orders-wc.php' );
$compatibility = file_get_contents( $root . '/inc/cores/compatibility.php' );
$overview      = file_get_contents( $root . '/inc/backend/preorder-overview.php' );
$blocks        = file_get_contents( $root . '/inc/frontend/customer-actions-blocks.php' );
$readme        = file_get_contents( $root . '/readme.txt' );

yoohw_preorders_source_assert( 1 === preg_match( '/^\h*\*\h*Version:\h*([0-9]+(?:\.[0-9]+){1,2})\h*$/m', $main, $plugin_version ), 'Plugin Version header is missing or invalid.' );
yoohw_preorders_source_assert( 1 === preg_match( '/^Stable tag:\h*([^\s]+)\h*$/m', $readme, $stable_tag ), 'Readme Stable tag is missing.' );
yoohw_preorders_source_assert( $plugin_version[1] === $stable_tag[1], 'Plugin Version and readme Stable tag differ.' );
yoohw_preorders_source_assert( false !== strpos( $main, "declare_compatibility('custom_order_tables'" ), 'HPOS compatibility is not declared.' );
yoohw_preorders_source_assert( false !== strpos( $main, "declare_compatibility('cart_checkout_blocks'" ), 'Cart/Checkout Blocks compatibility is not declared.' );
yoohw_preorders_source_assert( false !== strpos( $compatibility, '_pre_order_available_date' ) && false !== strpos( $compatibility, '_extended_status_available_date' ), 'Free and Stock Manager date compatibility is incomplete.' );
yoohw_preorders_source_assert( false !== strpos( $compatibility, '_wcess_stock_status' ) && false !== strpos( $compatibility, '_wcess_available_date' ), 'Order item snapshots are missing.' );
yoohw_preorders_source_assert( false !== strpos( $overview, 'WP_List_Table' ) && false !== strpos( $overview, 'send_delay_notifications' ), 'Preorder overview or delay notification is missing.' );
yoohw_preorders_source_assert( false !== strpos( $blocks, 'woocommerce_store_api_checkout_update_order_from_request' ), 'Block checkout delivery preference persistence is missing.' );

echo "Pre-Orders for WooCommerce source contracts passed.\n";
