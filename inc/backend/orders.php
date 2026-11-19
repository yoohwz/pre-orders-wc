<?php

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;

class YoOhw_Pre_Orders_WC_Orders {

	const FILTER = 'yoohw_pre_orders_filter';

	public function __construct() {
		add_action( 'restrict_manage_posts', [ $this, 'render_filter' ], 10, 2 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', [ $this, 'render_filter' ], 10, 2 );
		add_filter( 'posts_where', [ $this, 'filter_legacy_orders' ], 10, 2 );
		add_filter( 'woocommerce_shop_order_list_table_prepare_items_query_args', [ $this, 'mark_hpos_list_query' ] );
		add_filter( 'woocommerce_orders_table_query_clauses', [ $this, 'filter_hpos_orders' ], 10, 3 );
		add_filter( 'views_edit-shop_order', [ $this, 'keep_filter_in_status_views' ] );
		add_filter( 'views_woocommerce_page_wc-orders', [ $this, 'keep_filter_in_status_views' ] );
		add_filter( 'post_class', [ $this, 'add_legacy_order_class' ], 10, 3 );
		add_filter( 'woocommerce_shop_order_list_table_order_css_classes', [ $this, 'add_order_class' ], 10, 2 );
		add_action( 'admin_enqueue_scripts', [ $this, 'add_order_icon_styles' ], 20 );
	}

	public function render_filter( $order_type, $position ) {
		if ( 'shop_order' !== $order_type || 'top' !== $position ) {
			return;
		}

		$options = [
			''    => __( 'All orders', 'pre-orders-wc' ),
			'yes' => __( 'Orders with preorder items', 'pre-orders-wc' ),
			'no'  => __( 'Orders without preorder items', 'pre-orders-wc' ),
		];

		$selected = $this->get_filter();
		?>
		<label class="screen-reader-text" for="yoohw-pre-orders-filter"><?php esc_html_e( 'Filter by preorder status', 'pre-orders-wc' ); ?></label>
		<select name="<?php echo esc_attr( self::FILTER ); ?>" id="yoohw-pre-orders-filter">
			<?php foreach ( $options as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $selected ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private function get_filter() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$value = isset( $_GET[ self::FILTER ] ) ? sanitize_key( wp_unslash( $_GET[ self::FILTER ] ) ) : '';
		return in_array( $value, [ 'yes', 'no' ], true ) ? $value : '';
	}

	public function keep_filter_in_status_views( $views ) {
		$filter = $this->get_filter();
		if ( '' === $filter ) {
			return $views;
		}

		foreach ( $views as $key => $view ) {
			$link = new WP_HTML_Tag_Processor( $view );
			if ( $link->next_tag( 'a' ) && is_string( $link->get_attribute( 'href' ) ) ) {
				$link->set_attribute( 'href', add_query_arg( self::FILTER, $filter, $link->get_attribute( 'href' ) ) );
				$views[ $key ] = $link->get_updated_html();
			}
		}
		return $views;
	}

	private function is_legacy_orders_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'edit-shop_order' === $screen->id;
	}

	public function filter_legacy_orders( $where, $query ) {
		global $wpdb;

		if ( ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) || ! $this->is_legacy_orders_screen() ) {
			return $where;
		}
		$filter = $this->get_filter();
		if ( '' === $filter ) {
			return $where;
		}
		return $where . $this->order_condition( $filter, $wpdb->posts, 'ID', $wpdb->postmeta, 'post_id' );
	}

	public function mark_hpos_list_query( $args ) {
		$filter = $this->get_filter();
		if ( '' !== $filter ) {
			$args[ self::FILTER ] = $filter;
		}
		return $args;
	}

	public function filter_hpos_orders( $clauses, $query, $args ) {
		$filter = $args[ self::FILTER ] ?? '';
		if ( ! in_array( $filter, [ 'yes', 'no' ], true ) ) {
			return $clauses;
		}

		$clauses['where'] .= $this->order_condition( $filter, OrdersTableDataStore::get_orders_table_name(), 'id', OrdersTableDataStore::get_meta_table_name(), 'order_id' );
		return $clauses;
	}

	private function order_condition( $filter, $orders_table, $id_column, $meta_table, $meta_id_column ) {
		global $wpdb;

		// Extend the native row and count queries without duplicating rows or loading IDs.
		// All tables and columns come from WordPress/WooCommerce, and both storage modes share item tables.
		$evidence = $wpdb->prepare(
			'(EXISTS (
				SELECT 1 FROM %i AS po_order_meta
				WHERE po_order_meta.%i = %i.%i
				AND ((po_order_meta.meta_key = %s AND po_order_meta.meta_value = %s)
					OR (po_order_meta.meta_key = %s AND po_order_meta.meta_value NOT IN (%s, %s, %s)))
			) OR EXISTS (
				SELECT 1 FROM %i AS po_item
				INNER JOIN %i AS po_item_meta ON po_item_meta.order_item_id = po_item.order_item_id
				WHERE po_item.order_id = %i.%i AND po_item.order_item_type = %s
					AND po_item_meta.meta_key = %s AND po_item_meta.meta_value = %s
			))',
			$meta_table, $meta_id_column, $orders_table, $id_column,
			'_pre_order_marked', '1', '_pre_order_items', '', '0', 'a:0:{}',
			$wpdb->prefix . 'woocommerce_order_items', $wpdb->prefix . 'woocommerce_order_itemmeta',
			$orders_table, $id_column, 'line_item', '_wcess_stock_status', 'onpreorder'
		);

		return ( 'no' === $filter ? ' AND NOT ' : ' AND ' ) . $evidence;
	}

	public function add_legacy_order_class( $classes, $class, $post_id ) {
		if ( in_array( 'type-shop_order', $classes, true ) && $this->is_legacy_orders_screen() ) {
			return $this->add_order_class( $classes, wc_get_order( $post_id ) );
		}
		return $classes;
	}

	public function add_order_class( $classes, $order ) {
		$class = self::get_order_class( $order );
		if ( '' !== $class ) {
			$classes[] = $class;
		}
		return $classes;
	}

	public static function get_order_class( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		$pending_ids = yoohw_pre_orders_wc_get_tracked_product_ids( $order );
		$has_preorder = '1' === (string) $order->get_meta( '_pre_order_marked', true ) || $pending_ids;
		$all_preorder = true;
		$item_count = 0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			++$item_count;
			if ( 'onpreorder' === self::get_checkout_status( $item, $pending_ids ) ) {
				$has_preorder = true;
			} else {
				$all_preorder = false;
			}
		}

		if ( ! $has_preorder ) {
			return '';
		}
		return $all_preorder && $item_count ? 'yoohw-only-preorder' : 'yoohw-mixed-preorder';
	}

	public static function get_checkout_status( $item, array $pending_ids ) {
		$snapshot = (string) $item->get_meta( '_wcess_stock_status', true );
		if ( '' !== $snapshot ) {
			return $snapshot;
		}

		$product_id = $item->get_variation_id() ?: $item->get_product_id();
		return in_array( $product_id, $pending_ids, true ) ? 'onpreorder' : '';
	}

	public static function get_icon_url( $class ) {
		$image = 'yoohw-only-preorder' === $class ? 'only-preorder.svg' : 'mixed-preorder.svg';
		return plugins_url( 'img/' . $image, dirname( __DIR__, 2 ) . '/pre-orders-wc.php' );
	}

	public function add_order_icon_styles() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, [ 'edit-shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
			return;
		}
		$styles = '';
		foreach ( [ 'yoohw-only-preorder', 'yoohw-mixed-preorder' ] as $class ) {
			$styles .= sprintf(
				'tr.type-shop_order.%1$s a.order-view::before {content:url("%2$s");display:inline-block;margin-right:5px;}',
				$class,
				esc_url( self::get_icon_url( $class ) )
			);
		}
		wp_add_inline_style( 'pre-orders-css', $styles );
	}
}

new YoOhw_Pre_Orders_WC_Orders();
