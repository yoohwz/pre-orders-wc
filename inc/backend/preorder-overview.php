<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class YoOhw_Pre_Orders_WC_Overview_List_Table extends WP_List_Table {
	private $manager;
	private $campaigns;

	public function __construct( $manager, array $campaigns ) {
		parent::__construct( [ 'singular' => 'preorder', 'plural' => 'preorders', 'ajax' => false ] );
		$this->manager   = $manager;
		$this->campaigns = $campaigns;
	}

	public function get_columns() {
		return [
			'product'       => __( 'Product', 'pre-orders-wc' ),
			'available'     => __( 'Available date', 'pre-orders-wc' ),
			'status'        => __( 'Schedule status', 'pre-orders-wc' ),
			'orders'        => __( 'Open orders', 'pre-orders-wc' ),
			'units'         => __( 'Preorder units', 'pre-orders-wc' ),
			'notifications' => __( 'Customer updates', 'pre-orders-wc' ),
			'actions'       => __( 'Actions', 'pre-orders-wc' ),
		];
	}

	protected function get_table_classes() {
		$classes   = parent::get_table_classes();
		$classes[] = 'yoohw-preorders-table';

		return $classes;
	}

	public function prepare_items() {
		$per_page     = $this->get_items_per_page( 'yoohw_preorders_per_page', 20 );
		$current_page = $this->get_pagenum();
		$total_items  = count( $this->campaigns );

		$this->_column_headers = [ $this->get_columns(), [], [] ];
		$this->items           = array_slice( $this->campaigns, ( $current_page - 1 ) * $per_page, $per_page );
		$this->set_pagination_args(
			[
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			]
		);
	}

	public function no_items() {
		esc_html_e( 'No preorder products match this view.', 'pre-orders-wc' );
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'available':
				return '<strong>' . esc_html( $item['available_label'] ) . '</strong><div class="yoohw-preorders-muted">' . esc_html( $item['distance_label'] ) . '</div>';
			case 'status':
				return '<span class="yoohw-preorders-status yoohw-preorders-status--' . esc_attr( $item['schedule_status'] ) . '">' . esc_html( $item['schedule_label'] ) . '</span>';
			case 'orders':
				return esc_html( number_format_i18n( $item['orders_count'] ) );
			case 'units':
				return esc_html( number_format_i18n( $item['units'] ) );
			case 'notifications':
				return $this->column_notifications( $item );
			case 'actions':
				return $this->column_actions( $item );
		}

		return '';
	}

	public function column_product( $item ) {
		$name = $item['edit_url']
			? '<a href="' . esc_url( $item['edit_url'] ) . '">' . esc_html( $item['name'] ) . '</a>'
			: esc_html( $item['name'] );
		$details = $item['sku']
			/* translators: %s: product SKU. */
			? sprintf( __( 'SKU: %s', 'pre-orders-wc' ), $item['sku'] )
			/* translators: %d: WooCommerce product ID. */
			: sprintf( __( 'Product ID: %d', 'pre-orders-wc' ), $item['product_id'] );

		return '<strong>' . $name . '</strong><div class="yoohw-preorders-muted">' . esc_html( $details ) . '</div>';
	}

	private function column_notifications( $item ) {
		if ( $item['customers_to_notify'] < 1 ) {
			return '<span class="yoohw-preorders-muted">' . esc_html__( 'Up to date', 'pre-orders-wc' ) . '</span>';
		}

		return sprintf(
			/* translators: %d: number of orders awaiting a delay notification. */
			esc_html__( '%d order(s) to notify', 'pre-orders-wc' ),
			absint( $item['customers_to_notify'] )
		);
	}

	private function column_actions( $item ) {
		ob_start();
		?>
		<div class="yoohw-preorders-actions">
			<?php if ( $item['edit_url'] ) : ?>
				<a class="button button-small" href="<?php echo esc_url( $item['edit_url'] ); ?>"><?php esc_html_e( 'Edit product', 'pre-orders-wc' ); ?></a>
			<?php endif; ?>
			<?php if ( $item['customers_to_notify'] > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="yoohw_pre_orders_notify_delay" />
					<input type="hidden" name="product_id" value="<?php echo esc_attr( $item['product_id'] ); ?>" />
					<?php wp_nonce_field( 'yoohw_pre_orders_notify_delay_' . $item['product_id'] ); ?>
					<button class="button button-small" type="submit"><?php esc_html_e( 'Notify customers', 'pre-orders-wc' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}

class YoOhw_Pre_Orders_WC_Overview {
	const PAGE_SLUG       = 'yoohw-pre-orders';
	const DELAY_ITEM_META = '_yoohw_preorder_delay_notice_date';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_menu' ], 35 );
		add_action( 'admin_post_yoohw_pre_orders_notify_delay', [ $this, 'handle_delay_notification' ] );
		add_filter( 'set-screen-option', [ $this, 'save_screen_option' ], 10, 3 );
	}

	public function add_menu() {
		$hook = add_submenu_page(
			'edit.php?post_type=product',
			__( 'Preorder overview', 'pre-orders-wc' ),
			__( 'Pre-orders', 'pre-orders-wc' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[ $this, 'render_page' ]
		);
		if ( $hook ) {
			add_action( 'load-' . $hook, [ $this, 'add_screen_option' ] );
		}
	}

	public function add_screen_option() {
		add_screen_option(
			'per_page',
			[
				'label'   => __( 'Preorders per page', 'pre-orders-wc' ),
				'default' => 20,
				'option'  => 'yoohw_preorders_per_page',
			]
		);
	}

	public function save_screen_option( $status, $option, $value ) {
		if ( 'yoohw_preorders_per_page' === $option ) {
			return max( 1, min( 200, absint( $value ) ) );
		}

		return $status;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to view preorder operations.', 'pre-orders-wc' ) );
		}

		$aggregates = $this->get_open_order_aggregates();
		$campaigns  = $this->get_active_preorders( $aggregates );
		$summary    = $this->summarize( $campaigns );
		$filter     = isset( $_GET['preorder_view'] ) ? sanitize_key( wp_unslash( $_GET['preorder_view'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$search     = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		$filtered   = $this->filter_campaigns( $campaigns, $filter, $search );
		$table      = new YoOhw_Pre_Orders_WC_Overview_List_Table( $this, $filtered );
		$table->prepare_items();
		?>
		<div class="wrap yoohw-preorders-overview">
			<h1><?php esc_html_e( 'Preorder overview', 'pre-orders-wc' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Track active preorder products, open preorder demand, upcoming availability, and customer date updates.', 'pre-orders-wc' ); ?></p>
			<?php $this->render_notice(); ?>
			<div class="yoohw-preorders-summary">
				<?php $this->render_metric( __( 'Active products', 'pre-orders-wc' ), $summary['active'] ); ?>
				<?php $this->render_metric( __( 'Open orders', 'pre-orders-wc' ), $summary['orders'] ); ?>
				<?php $this->render_metric( __( 'Preorder units', 'pre-orders-wc' ), $summary['units'] ); ?>
				<?php $this->render_metric( __( 'Due in 7 days', 'pre-orders-wc' ), $summary['due_soon'] ); ?>
				<?php $this->render_metric( __( 'Overdue', 'pre-orders-wc' ), $summary['overdue'] ); ?>
				<?php $this->render_metric( __( 'Customers to notify', 'pre-orders-wc' ), $summary['notifications'] ); ?>
			</div>
			<form class="yoohw-preorders-filters" method="get">
				<input type="hidden" name="post_type" value="product" />
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<label for="preorder-view"><?php esc_html_e( 'View', 'pre-orders-wc' ); ?></label>
				<select id="preorder-view" name="preorder_view">
					<?php foreach ( $this->filter_options() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filter, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="preorder-search"><?php esc_html_e( 'Search preorders', 'pre-orders-wc' ); ?></label>
				<input id="preorder-search" type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search product or SKU', 'pre-orders-wc' ); ?>" />
				<button class="button" type="submit"><?php esc_html_e( 'Filter', 'pre-orders-wc' ); ?></button>
			</form>
			<?php $table->display(); ?>
			<div class="yoohw-preorders-upgrade-inline">
				<strong><?php esc_html_e( 'Need purchasing and inventory workflows?', 'pre-orders-wc' ); ?></strong>
				<span><?php esc_html_e( 'Stock Manager adds restock batches, receiving, inventory movements, suppliers, purchase orders, allocation, and multi-location stock.', 'pre-orders-wc' ); ?></span>
				<a href="<?php echo esc_url( YOOHW_PRE_ORDERS_WC_PREMIUM_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Explore Stock Manager', 'pre-orders-wc' ); ?></a>
			</div>
		</div>
		<?php
	}

	public function handle_delay_notification() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to notify preorder customers.', 'pre-orders-wc' ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		check_admin_referer( 'yoohw_pre_orders_notify_delay_' . $product_id );
		$product = wc_get_product( $product_id );
		if ( ! $product || 'onpreorder' !== $product->get_stock_status() ) {
			$this->redirect_after_notify( 0, 0, 'invalid_product' );
		}

		$result = $this->send_delay_notifications( $product );
		$this->redirect_after_notify( $result['sent'], $result['skipped'], '' );
	}

	private function get_active_preorders( array $aggregates ) {
		$products = [];
		foreach ( [ '', 'variation' ] as $type ) {
			$page = 1;
			do {
				$args = [
					'limit'        => 250,
					'page'         => $page,
					'paginate'     => true,
					'return'       => 'objects',
					'status'       => [ 'publish', 'private' ],
					'stock_status' => 'onpreorder',
				];
				if ( '' !== $type ) {
					$args['type'] = $type;
				}
				$query = wc_get_products( $args );
				$found = is_object( $query ) && isset( $query->products ) ? (array) $query->products : (array) $query;
				foreach ( $found as $product ) {
					if ( $product instanceof WC_Product ) {
						$products[ $product->get_id() ] = $product;
					}
				}
				$max_pages = is_object( $query ) && isset( $query->max_num_pages ) ? absint( $query->max_num_pages ) : 0;
				++$page;
			} while ( $page <= $max_pages );
		}

		$campaigns = [];
		foreach ( $products as $product ) {
			$product_id = $product->get_id();
			$date_value = yoohw_pre_orders_wc_get_product_available_date( $product );
			$date       = yoohw_pre_orders_wc_parse_available_date( $date_value );
			$status     = $this->schedule_status( $date );
			$aggregate  = $aggregates[ $product_id ] ?? [ 'order_ids' => [], 'units' => 0, 'customers_to_notify' => 0 ];
			$edit_id    = $product instanceof WC_Product_Variation ? $product->get_parent_id() : $product_id;
			$campaigns[] = [
				'product_id'          => $product_id,
				'name'                => yoohw_pre_orders_wc_get_product_name( $product ),
				'sku'                 => (string) $product->get_sku(),
				'available_date'      => $date_value,
				'available_timestamp' => $date ? $date->getTimestamp() : 0,
				'available_label'     => $date ? yoohw_pre_orders_wc_format_available_date( $date_value, true ) : __( 'No date set', 'pre-orders-wc' ),
				'distance_label'      => $this->date_distance( $date ),
				'schedule_status'     => $status,
				'schedule_label'      => $this->schedule_label( $status ),
				'orders_count'        => count( $aggregate['order_ids'] ),
				'order_ids'           => array_values( $aggregate['order_ids'] ),
				'units'               => absint( $aggregate['units'] ),
				'customers_to_notify' => absint( $aggregate['customers_to_notify'] ),
				'edit_url'            => get_edit_post_link( $edit_id, '' ) ?: '',
			];
		}

		usort(
			$campaigns,
			function( $left, $right ) {
				$left_date  = $left['available_timestamp'] ?: PHP_INT_MAX;
				$right_date = $right['available_timestamp'] ?: PHP_INT_MAX;
				return $left_date === $right_date ? strcasecmp( $left['name'], $right['name'] ) : ( $left_date < $right_date ? -1 : 1 );
			}
		);

		return $campaigns;
	}

	private function get_open_order_aggregates() {
		$aggregates = [];
		$page       = 1;
		do {
			$query = wc_get_orders(
				[
					'limit'      => 250,
					'page'       => $page,
					'paginate'   => true,
					'return'     => 'objects',
					'status'     => apply_filters( 'yoohw_pre_orders_wc_open_order_statuses', [ 'pending', 'processing', 'on-hold' ] ),
					'meta_query' => [ [ 'key' => '_pre_order_items', 'compare' => 'EXISTS' ] ],
				]
			);
			$orders    = is_object( $query ) && isset( $query->orders ) ? (array) $query->orders : (array) $query;
			$max_pages = is_object( $query ) && isset( $query->max_num_pages ) ? absint( $query->max_num_pages ) : 0;
			foreach ( $orders as $order ) {
				if ( ! $order instanceof WC_Order ) {
					continue;
				}
				$tracked_ids = yoohw_pre_orders_wc_get_tracked_product_ids( $order );
				$notify_ids  = [];
				foreach ( $order->get_items() as $item ) {
					if ( ! $item instanceof WC_Order_Item_Product ) {
						continue;
					}
					$product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
					$product    = $item->get_product();
					if ( 'onpreorder' !== yoohw_pre_orders_wc_get_order_item_stock_status( $item, $product ) && ! in_array( $product_id, $tracked_ids, true ) ) {
						continue;
					}
					if ( ! isset( $aggregates[ $product_id ] ) ) {
						$aggregates[ $product_id ] = [ 'order_ids' => [], 'units' => 0, 'customers_to_notify' => 0 ];
					}
					$aggregates[ $product_id ]['order_ids'][ $order->get_id() ] = $order->get_id();
					$aggregates[ $product_id ]['units'] += absint( $item->get_quantity() );
					if ( $this->item_needs_delay_notice( $item, $product_id ) ) {
						$notify_ids[ $product_id ] = true;
					}
				}
				foreach ( array_keys( $notify_ids ) as $product_id ) {
					$aggregates[ $product_id ]['customers_to_notify']++;
				}
			}
			++$page;
		} while ( $page <= $max_pages );

		return $aggregates;
	}

	private function item_needs_delay_notice( $item, $product_id ) {
		$current  = yoohw_pre_orders_wc_get_product_available_date( $product_id );
		$snapshot = (string) $item->get_meta( '_wcess_available_date', true );
		$sent     = (string) $item->get_meta( self::DELAY_ITEM_META, true );
		$current_date  = yoohw_pre_orders_wc_parse_available_date( $current );
		$snapshot_date = yoohw_pre_orders_wc_parse_available_date( $snapshot );

		return $current_date && $snapshot_date && $current_date->getTimestamp() > $snapshot_date->getTimestamp() && yoohw_pre_orders_wc_normalize_available_date( $sent ) !== yoohw_pre_orders_wc_normalize_available_date( $current );
	}

	private function send_delay_notifications( $product ) {
		$current_date = yoohw_pre_orders_wc_get_product_available_date( $product );
		if ( '' === $current_date ) {
			return [ 'sent' => 0, 'skipped' => 0 ];
		}
		$sent = 0;
		$skipped = 0;
		foreach ( $this->get_orders_for_product( $product->get_id() ) as $order ) {
			$items    = [];
			$old_date = '';
			foreach ( $order->get_items() as $item ) {
				$item_product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
				if ( $item_product_id !== $product->get_id() || ! $this->item_needs_delay_notice( $item, $product->get_id() ) ) {
					continue;
				}
				$items[] = $item;
				$last_notified_date = (string) $item->get_meta( self::DELAY_ITEM_META, true );
				$old_date = $old_date ?: ( $last_notified_date ?: (string) $item->get_meta( '_wcess_available_date', true ) );
			}
			if ( empty( $items ) ) {
				continue;
			}
			if ( ! $this->send_delay_email( $order, $product, $old_date, $current_date ) ) {
				$skipped++;
				continue;
			}
			foreach ( $items as $item ) {
				$item->update_meta_data( self::DELAY_ITEM_META, $current_date );
				$item->save();
			}
			$order->add_order_note(
				sprintf(
					/* translators: 1: product name, 2: previous date, 3: new date. */
					__( 'Preorder availability update sent for “%1$s”. Previous date: %2$s. New date: %3$s.', 'pre-orders-wc' ),
					yoohw_pre_orders_wc_get_product_name( $product ),
					yoohw_pre_orders_wc_format_available_date( $old_date, true ),
					yoohw_pre_orders_wc_format_available_date( $current_date, true )
				)
			);
			$sent++;
		}

		return [ 'sent' => $sent, 'skipped' => $skipped ];
	}

	private function get_orders_for_product( $product_id ) {
		$orders = [];
		$page   = 1;
		do {
			$query = wc_get_orders(
				[
					'limit'      => 250,
					'page'       => $page,
					'paginate'   => true,
					'return'     => 'objects',
					'status'     => apply_filters( 'yoohw_pre_orders_wc_open_order_statuses', [ 'pending', 'processing', 'on-hold' ] ),
					'meta_query' => [ [ 'key' => '_pre_order_items', 'value' => (string) absint( $product_id ), 'compare' => 'LIKE' ] ],
				]
			);
			$found     = is_object( $query ) && isset( $query->orders ) ? (array) $query->orders : (array) $query;
			$max_pages = is_object( $query ) && isset( $query->max_num_pages ) ? absint( $query->max_num_pages ) : 0;
			foreach ( $found as $order ) {
				if ( $order instanceof WC_Order && in_array( absint( $product_id ), yoohw_pre_orders_wc_get_tracked_product_ids( $order ), true ) ) {
					$orders[ $order->get_id() ] = $order;
				}
			}
			++$page;
		} while ( $page <= $max_pages );

		return array_values( $orders );
	}

	private function send_delay_email( $order, $product, $old_date, $new_date ) {
		$recipient = $order->get_billing_email();
		if ( ! is_email( $recipient ) ) {
			return false;
		}
		$subject = sprintf(
			/* translators: %s: order number. */
			__( 'Preorder availability update for order %s', 'pre-orders-wc' ),
			$order->get_order_number()
		);
		$message = sprintf(
			'<p>%1$s</p><p><strong>%2$s</strong> %3$s</p><p><strong>%4$s</strong> %5$s<br><strong>%6$s</strong> %7$s</p><p><a href="%8$s">%9$s</a></p>',
			esc_html__( 'The expected availability date for one of your preorder items has changed.', 'pre-orders-wc' ),
			esc_html__( 'Item:', 'pre-orders-wc' ),
			esc_html( yoohw_pre_orders_wc_get_product_name( $product ) ),
			esc_html__( 'Previous date:', 'pre-orders-wc' ),
			esc_html( yoohw_pre_orders_wc_format_available_date( $old_date, true ) ),
			esc_html__( 'New expected date:', 'pre-orders-wc' ),
			esc_html( yoohw_pre_orders_wc_format_available_date( $new_date, true ) ),
			esc_url( $order->get_view_order_url() ),
			esc_html__( 'View your order', 'pre-orders-wc' )
		);
		$mailer  = WC()->mailer();
		$template = new WC_Email();
		$content = $template->style_inline( $mailer->wrap_message( __( 'Preorder availability updated', 'pre-orders-wc' ), $message ) );

		return (bool) $mailer->send( $recipient, $subject, $content, [ 'Content-Type: text/html; charset=UTF-8' ], [] );
	}

	private function filter_campaigns( array $campaigns, $filter, $search ) {
		return array_values(
			array_filter(
				$campaigns,
				function( $campaign ) use ( $filter, $search ) {
					if ( 'all' !== $filter && $campaign['schedule_status'] !== $filter ) {
						return false;
					}
					if ( '' !== $search && false === stripos( $campaign['name'] . ' ' . $campaign['sku'], $search ) ) {
						return false;
					}
					return true;
				}
			)
		);
	}

	private function summarize( array $campaigns ) {
		$orders = [];
		$summary = [ 'active' => count( $campaigns ), 'orders' => 0, 'units' => 0, 'due_soon' => 0, 'overdue' => 0, 'notifications' => 0 ];
		foreach ( $campaigns as $campaign ) {
			foreach ( $campaign['order_ids'] as $order_id ) {
				$orders[ $order_id ] = true;
			}
			$summary['units'] += $campaign['units'];
			$summary['notifications'] += $campaign['customers_to_notify'];
			if ( 'due_soon' === $campaign['schedule_status'] ) {
				$summary['due_soon']++;
			} elseif ( 'overdue' === $campaign['schedule_status'] ) {
				$summary['overdue']++;
			}
		}
		$summary['orders'] = count( $orders );

		return $summary;
	}

	private function schedule_status( $date ) {
		if ( ! $date instanceof DateTimeImmutable ) {
			return 'no_date';
		}
		if ( $date->getTimestamp() < time() ) {
			return 'overdue';
		}
		return $date->getTimestamp() <= time() + WEEK_IN_SECONDS ? 'due_soon' : 'scheduled';
	}

	private function schedule_label( $status ) {
		$labels = [
			'overdue'  => __( 'Overdue', 'pre-orders-wc' ),
			'due_soon' => __( 'Due soon', 'pre-orders-wc' ),
			'scheduled'=> __( 'Scheduled', 'pre-orders-wc' ),
			'no_date'  => __( 'No date', 'pre-orders-wc' ),
		];

		return $labels[ $status ] ?? $status;
	}

	private function date_distance( $date ) {
		if ( ! $date instanceof DateTimeImmutable ) {
			return __( 'No availability date configured', 'pre-orders-wc' );
		}
		if ( $date->getTimestamp() < time() ) {
			/* translators: %s: human-readable amount of time. */
			return sprintf( __( 'Overdue by %s', 'pre-orders-wc' ), human_time_diff( $date->getTimestamp(), time() ) );
		}

		/* translators: %s: human-readable amount of time. */
		return sprintf( __( 'Due in %s', 'pre-orders-wc' ), human_time_diff( time(), $date->getTimestamp() ) );
	}

	private function filter_options() {
		return [
			'all'       => __( 'All active preorders', 'pre-orders-wc' ),
			'due_soon'  => __( 'Due in 7 days', 'pre-orders-wc' ),
			'overdue'   => __( 'Overdue', 'pre-orders-wc' ),
			'scheduled' => __( 'Scheduled later', 'pre-orders-wc' ),
			'no_date'   => __( 'No date set', 'pre-orders-wc' ),
		];
	}

	private function render_metric( $label, $value ) {
		echo '<div class="yoohw-preorders-metric"><span>' . esc_html( $label ) . '</span><strong>' . esc_html( number_format_i18n( $value ) ) . '</strong></div>';
	}

	private function render_notice() {
		$status = isset( $_GET['preorder_notice'] ) ? sanitize_key( wp_unslash( $_GET['preorder_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect notice only.
		if ( '' === $status ) {
			return;
		}
		$sent    = isset( $_GET['sent'] ) ? absint( wp_unslash( $_GET['sent'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect notice only.
		$skipped = isset( $_GET['skipped'] ) ? absint( wp_unslash( $_GET['skipped'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Redirect notice only.
		if ( 'invalid_product' === $status ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The preorder product could not be found.', 'pre-orders-wc' ) . '</p></div>';
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(
			sprintf(
				/* translators: 1: emails sent, 2: emails skipped. */
				__( '%1$d customer update(s) sent; %2$d skipped.', 'pre-orders-wc' ),
				$sent,
				$skipped
			)
		) . '</p></div>';
	}

	private function redirect_after_notify( $sent, $skipped, $error ) {
		$url = add_query_arg(
			[
				'post_type'       => 'product',
				'page'            => self::PAGE_SLUG,
				'preorder_notice' => $error ?: 'sent',
				'sent'            => absint( $sent ),
				'skipped'         => absint( $skipped ),
			],
			admin_url( 'edit.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}

new YoOhw_Pre_Orders_WC_Overview();
