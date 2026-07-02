<?php

if (!defined('ABSPATH')) {
	exit;
}

class YoOhw_Pre_Orders_WC_Edit_Order {
	public function __construct() {
		add_action('woocommerce_before_order_itemmeta', array($this, 'display_pre_order_status_in_order'), 10, 3);
		add_action('woocommerce_admin_order_data_after_order_details', [$this, 'add_pre_order_inline_css']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
	}

	public function display_pre_order_status_in_order($item_id, $item, $order) {
		// Ensure the item is a product
		if (is_a($item, 'WC_Order_Item_Product')) {
			$product = $item->get_product();
			if ($product && $product->get_stock_status() === 'onpreorder') {
				$pre_order_message = '<div class="item-meta-preorder"><strong>' . esc_html__('Pre-order', 'pre-orders-wc') . '</strong>';
				$pre_order_date = get_post_meta($product->get_id(), '_pre_order_available_date', true);
				if ($pre_order_date) {
					/* translators: %s: the available date of the pre-order product. */
					$pre_order_message .= '<strong>:</strong> ' . sprintf(esc_html__('Available on %s', 'pre-orders-wc'), esc_html(date_i18n(get_option('date_format'), strtotime($pre_order_date))));
				}
				$pre_order_message .= '</div>';
				echo wp_kses_post($pre_order_message);
			}
		}
	}

	public function add_pre_order_inline_css($order) {
		// Initialize flags for stock statuses
		$only_preorder = true;
		$has_preorder = false;
	
		foreach ($order->get_items() as $item) {
			$product = $item->get_product();
			if ($product) {
				$stock_status = $product->get_stock_status();
	
				if ($stock_status === 'onpreorder') {
					$has_preorder = true;
				} else {
					$only_preorder = false;
				}
			}
		}
	
		// Apply styles based on the stock status conditions
		if ($only_preorder) {
			// If only 'onpreorder' products
			echo '<style>
				h2.woocommerce-order-data__heading::before {
					content: url("data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4KPHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHhtbG5zOnhsaW5rPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5L3hsaW5rIiB3aWR0aD0iMjRweCIgaGVpZ2h0PSIyNHB4IiB2aWV3Qm94PSIwIDAgMjQgMjQiIHZlcnNpb249IjEuMSI+CjxnIGlkPSJzdXJmYWNlMSI+CjxwYXRoIHN0eWxlPSIgc3Ryb2tlOm5vbmU7ZmlsbC1ydWxlOm5vbnplcm87ZmlsbDpyZ2IoMjMuMTM3MjU1JSw1Ny42NDcwNjElLDg1Ljg4MjM1NCUpO2ZpbGwtb3BhY2l0eToxOyIgZD0iTSA5IDI0IEwgMSAyNCBDIDAuNDQ5MjE5IDI0IDAgMjMuNTUwNzgxIDAgMjMgQyAwIDIyLjQ0OTIxOSAwLjQ0OTIxOSAyMiAxIDIyIEwgOSAyMiBDIDkuNTUwNzgxIDIyIDEwIDIyLjQ0OTIxOSAxMCAyMyBDIDEwIDIzLjU1MDc4MSA5LjU1MDc4MSAyNCA5IDI0IFogTSA5IDI0ICIvPgo8cGF0aCBzdHlsZT0iIHN0cm9rZTpub25lO2ZpbGwtcnVsZTpub256ZXJvO2ZpbGw6cmdiKDIzLjEzNzI1NSUsNTcuNjQ3MDYxJSw4NS44ODIzNTQlKTtmaWxsLW9wYWNpdHk6MTsiIGQ9Ik0gNyAyMCBMIDEgMjAgQyAwLjQ0OTIxOSAyMCAwIDE5LjU1MDc4MSAwIDE5IEMgMCAxOC40NDkyMTkgMC40NDkyMTkgMTggMSAxOCBMIDcgMTggQyA3LjU1MDc4MSAxOCA4IDE4LjQ0OTIxOSA4IDE5IEMgOCAxOS41NTA3ODEgNy41NTA3ODEgMjAgNyAyMCBaIE0gNyAyMCAiLz4KPHBhdGggc3R5bGU9IiBzdHJva2U6bm9uZTtmaWxsLXJ1bGU6bm9uemVybztmaWxsOnJnYigyMy4xMzcyNTUlLDU3LjY0NzA2MSUsODUuODgyMzU0JSk7ZmlsbC1vcGFjaXR5OjE7IiBkPSJNIDUgMTYgTCAxIDE2IEMgMC40NDkyMTkgMTYgMCAxNS41NTA3ODEgMCAxNSBDIDAgMTQuNDQ5MjE5IDAuNDQ5MjE5IDE0IDEgMTQgTCA1IDE0IEMgNS41NTA3ODEgMTQgNiAxNC40NDkyMTkgNiAxNSBDIDYgMTUuNTUwNzgxIDUuNTUwNzgxIDE2IDUgMTYgWiBNIDUgMTYgIi8+CjxwYXRoIHN0eWxlPSIgc3Ryb2tlOm5vbmU7ZmlsbC1ydWxlOm5vbnplcm87ZmlsbDpyZ2IoMjMuMTM3MjU1JSw1Ny42NDcwNjElLDg1Ljg4MjM1NCUpO2ZpbGwtb3BhY2l0eToxOyIgZD0iTSAxMyAyMy45NTMxMjUgQyAxMi40NDkyMTkgMjMuOTgwNDY5IDExLjk4MDQ2OSAyMy41NTA3ODEgMTEuOTU3MDMxIDIzIEMgMTEuOTI5Njg4IDIyLjQ0NTMxMiAxMi4zNTkzNzUgMjEuOTgwNDY5IDEyLjkxMDE1NiAyMS45NTMxMjUgQyAxOC4xNzU3ODEgMjEuNDcyNjU2IDIyLjE1NjI1IDE2Ljk3NjU2MiAyMS45OTYwOTQgMTEuNjkxNDA2IEMgMjEuODMyMDMxIDYuNDA2MjUgMTcuNTg1OTM4IDIuMTYwMTU2IDEyLjMwMDc4MSAyIEMgNy4wMTU2MjUgMS44NDM3NSAyLjUxOTUzMSA1LjgyNDIxOSAyLjAzOTA2MiAxMS4wODk4NDQgQyAxLjk5MjE4OCAxMS42NDA2MjUgMS41MDM5MDYgMTIuMDQ2ODc1IDAuOTUzMTI1IDExLjk5NjA5NCBDIDAuNDA2MjUgMTEuOTQ1MzEyIDAgMTEuNDYwOTM4IDAuMDUwNzgxMiAxMC45MTAxNTYgQyAwLjYyODkwNiA0LjUxOTUzMSA2LjEzMjgxMiAtMC4yODUxNTYgMTIuNTQ2ODc1IDAuMDAzOTA2MjUgQyAxOC45NTcwMzEgMC4yOTY4NzUgMjQuMDAzOTA2IDUuNTgyMDMxIDI0IDEyIEMgMjQuMDMxMjUgMTguMjE4NzUgMTkuMjg1MTU2IDIzLjQxNzk2OSAxMy4wODk4NDQgMjMuOTQ5MjE5IEMgMTMuMDU4NTk0IDIzLjk1MzEyNSAxMy4wMjczNDQgMjMuOTUzMTI1IDEzIDIzLjk1MzEyNSBaIE0gMTMgMjMuOTUzMTI1ICIvPgo8cGF0aCBzdHlsZT0iIHN0cm9rZTpub25lO2ZpbGwtcnVsZTpub256ZXJvO2ZpbGw6cmdiKDIzLjEzNzI1NSUsNTcuNjQ3MDYxJSw4NS44ODIzNTQlKTtmaWxsLW9wYWNpdHk6MTsiIGQ9Ik0gMTIgNiBDIDExLjQ0OTIxOSA2IDExIDYuNDQ5MjE5IDExIDcgTCAxMSAxMiBDIDExIDEyLjI2NTYyNSAxMS4xMDU0NjkgMTIuNTE5NTMxIDExLjI5Mjk2OSAxMi43MDcwMzEgTCAxNC4yOTI5NjkgMTUuNzA3MDMxIEMgMTQuNjgzNTk0IDE2LjA4NTkzOCAxNS4zMDg1OTQgMTYuMDgyMDMxIDE1LjY5NTMxMiAxNS42OTUzMTIgQyAxNi4wODIwMzEgMTUuMzA4NTk0IDE2LjA4NTkzOCAxNC42ODM1OTQgMTUuNzA3MDMxIDE0LjI5Mjk2OSBMIDEzIDExLjU4NTkzOCBMIDEzIDcgQyAxMyA2LjQ0OTIxOSAxMi41NTA3ODEgNiAxMiA2IFogTSAxMiA2ICIvPgo8L2c+Cjwvc3ZnPgo=");
					display: inline-block;
					transform: scale(1.2);
					margin-right: 10px;
				}
			</style>';
		} elseif ($has_preorder) {
			// If mixed 'onpreorder' and other stock statuses
			echo '<style>
				h2.woocommerce-order-data__heading::before {
					content: url("data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIGlkPSJMYXllcl8xIiBkYXRhLW5hbWU9IkxheWVyIDEiIHZpZXdCb3g9IjAgMCAyNCAyNCIgaGVpZ2h0PSIyNCIgd2lkdGg9IjI0Ij48cGF0aCBkPSJtMTkuNSwwSDQuNUMyLjAxOSwwLDAsMi4wMTksMCw0LjV2LjVjMCwuODgzLjM5MSwxLjY3LDEsMi4yMnYxMS43OGMwLDIuNzU3LDIuMjQzLDUsNSw1aDNjLjU1MiwwLDEtLjQ0OCwxLTFzLS40NDgtMS0xLTFoLTNjLTEuNjU0LDAtMy0xLjM0Ni0zLTN2LTExaDE4djFjMCwuNTUyLjQ0NywxLDEsMXMxLS40NDgsMS0xdi0xLjc4Yy42MDktLjU0OSwxLTEuMzM3LDEtMi4yMnYtLjVjMC0yLjQ4MS0yLjAxOS00LjUtNC41LTQuNVpNMyw2Yy0uNTUxLDAtMS0uNDQ5LTEtMXYtLjVjMC0xLjM3OCwxLjEyMi0yLjUsMi41LTIuNWgxNWMxLjM3OSwwLDIuNSwxLjEyMiwyLjUsMi41di41YzAsLjU1MS0uNDQ4LDEtMSwxSDNabTE0LDRjLTMuODU5LDAtNywzLjE0LTcsN3MzLjE0MSw3LDcsNyw3LTMuMTQsNy03LTMuMTQxLTctNy03Wm0wLDEyYy0yLjc1NywwLTUtMi4yNDMtNS01czIuMjQzLTUsNS01LDUsMi4yNDMsNSw1LTIuMjQzLDUtNSw1Wm0yLjIwNy00LjIwN2MuMzkxLjM5MS4zOTEsMS4wMjMsMCwxLjQxNC0uMTk1LjE5NS0uNDUxLjI5My0uNzA3LjI5M3MtLjUxMi0uMDk4LS43MDctLjI5M2wtMS41LTEuNWMtLjE4OC0uMTg4LS4yOTMtLjQ0Mi0uMjkzLS43MDd2LTJjMC0uNTUyLjQ0Ny0xLDEtMXMxLC40NDgsMSwxdjEuNTg2bDEuMjA3LDEuMjA3WiIgZmlsbD0iIzNiOTNkYiIgLz48L3N2Zz4=");
					display: inline-block;
					transform: scale(1.2);
					margin-right: 10px;
				}
			</style>';
		}
	}

	public function enqueue_admin_scripts($hook) {
		// Load script only on the WooCommerce orders list page
		if ('edit.php' === $hook || 'woocommerce_page_wc-orders' === $hook) {
	
			wp_enqueue_script('yopo_wc_orders_script', plugin_dir_url(__FILE__) . '../../js/orders.js', ['jquery'], '1.0', true);

			// Pass plugin URL to JavaScript
			wp_localize_script('yopo_wc_orders_script', 'myPluginData', [
				'pluginUrl' => plugin_dir_url(__FILE__),
			]);
	

			// Get the user's preference for items per page from Screen Options or default to 20
			$user_id = get_current_user_id();
			$per_page = (int) get_user_meta($user_id, 'edit_shop_order_per_page', true) ?: 20;

			// Get the current page number
			$current_page = filter_input(INPUT_GET, 'paged', FILTER_VALIDATE_INT);
			$current_page = $current_page ? absint($current_page) : 1;
	
			// Get orders for the current page
			$orders = wc_get_orders([
				'limit' => $per_page,
				'paged' => $current_page,
			]);
	
			$order_data = [];
	
			foreach ($orders as $order) {
				$only_preorder = true;
				$has_preorder = false;
	
				foreach ($order->get_items() as $item) {
					$product = $item->get_product();
					if ($product) {
						$stock_status = $product->get_stock_status();
	
						if ($stock_status === 'onpreorder') {
							$has_preorder = true;
						} else {
							$only_preorder = false;
						}
					}
				}
	
				// Add the data to be used in JavaScript
				$order_data[$order->get_id()] = [
					'only_preorder' => $only_preorder,
					'has_preorder' => $has_preorder && !$only_preorder,
				];
			}
	
			wp_localize_script('yopo_wc_orders_script', 'yopowc_order_data', $order_data);
		}
	}	
}

new YoOhw_Pre_Orders_WC_Edit_Order();
