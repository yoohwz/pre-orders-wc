<?php

if (!defined('ABSPATH')) {
	exit;
}

class YoOhw_Pre_Orders_WC_Settings {
	public function __construct() {
		add_filter('woocommerce_get_sections_products', [$this, 'add_subsections'], 10);
		add_filter('woocommerce_get_settings_products', [$this, 'add_subsection_settings'], 10, 2);
		add_action('woocommerce_admin_field_yoohw_pre_orders_upgrade_card', [$this, 'render_upgrade_card']);
	}

	public function add_subsections($sections) {
		$new_sections = [];

		foreach ($sections as $section_id => $section_label) {
			$new_sections[$section_id] = $section_label;

			if ('' === $section_id) {
				$new_sections['stock_status'] = __('Pre-orders', 'pre-orders-wc');
			}
		}

		if (!isset($new_sections['stock_status'])) {
			$new_sections['stock_status'] = __('Pre-orders', 'pre-orders-wc');
		}

		return $new_sections;
	}

	public function add_subsection_settings($settings, $current_section) {
		if ('stock_status' === $current_section) {
			$settings = $this->get_pre_orders_settings();
		}

		return $settings;
	}

	public function get_pre_orders_settings() {
		$order_splitter = sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url(admin_url('plugin-install.php?s=wc-order-splitter&tab=search&type=term')),
			esc_html__('Order Splitter', 'pre-orders-wc')
		);

		$settings = [
			[
				'name' => '',
				'type' => 'title',
				'id'   => 'pre_orders_support_us',
			],
			[
				'name' => esc_html__('Preorder experience', 'pre-orders-wc'),
				'type' => 'title',
				'id'   => 'stock_status_section_title',
			],
			[
				'name'     => esc_html__('Button text', 'pre-orders-wc'),
				'type'     => 'text',
				'desc_tip' => esc_html__('Replace the default \'Add to Cart\' button for the pre-order product.', 'pre-orders-wc'),
				'id'       => 'yoess_pre_order_button_text',
				'default'  => esc_html__('Pre-order now', 'pre-orders-wc'),
			],
			[
				'name'     => esc_html__('Administrator reminder', 'pre-orders-wc'),
				'type'     => 'checkbox',
				'desc'     => esc_html__('Email administrators before a preorder product becomes available', 'pre-orders-wc'),
				'desc_tip' => esc_html__('Uses the product availability date and the reminder lead time below.', 'pre-orders-wc'),
				'id'       => 'yoess_pre_order_email_to_admin',
				'default'  => 'no',
			],
			[
				'name'     => esc_html__('Reminder lead time', 'pre-orders-wc'),
				'type'     => 'number',
				'desc_tip' => esc_html__('Set how many days before the available date of the \'pre-order\' product to send the email notifications to the administrators.', 'pre-orders-wc'),
				'desc'     => esc_html__('day(s) before available date.', 'pre-orders-wc'),
				'id'       => 'yoess_pre_order_email_to_admin_timer',
				'default'  => '3',
				'css'      => 'width:60px;',
			],
			[
				'name'     => esc_html__('Delivery preference', 'pre-orders-wc'),
				'type'     => 'checkbox',
				'desc'     => esc_html__('Let customers choose how mixed preorder orders should be delivered', 'pre-orders-wc'),
				'desc_tip' => sprintf(
					/* translators: %s: Order Splitter plugin install link. */
					esc_html__('The customer can choose their receiving type on the checkout or order details pages, and administrator will receive the emails if the customers do choosing shipping type. Consider using our %s plugin (free) to split orders if they choose to receive separate orders.', 'pre-orders-wc'),
					$order_splitter
				),
				'id'       => 'yoess_pre_order_customer_actions',
				'default'  => 'no',
			],
			[
				'type' => 'yoohw_pre_orders_upgrade_card',
				'id'   => 'yoohw_pre_orders_upgrade_card',
			],
			[
				'type' => 'sectionend',
				'id'   => 'stock_status_section_end',
			],
		];

		return apply_filters('yoohw_pre_orders_wc_settings', $settings);
	}

	public function render_upgrade_card($value) {
		$premium_url = (string) apply_filters('yoohw_pre_orders_wc_premium_url', YOOHW_PRE_ORDERS_WC_PREMIUM_URL);
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<?php esc_html_e('Need advanced preorder workflows?', 'pre-orders-wc'); ?>
			</th>
			<td class="forminp">
				<div class="yoohw-pre-orders-upgrade-card">
					<div>
					<h3><?php esc_html_e('WooCommerce Stock Manager', 'pre-orders-wc'); ?></h3>
						<p><?php esc_html_e('Move from simple preorder scheduling to complete purchasing, receiving, inventory, and fulfillment workflows.', 'pre-orders-wc'); ?></p>
						<ul>
							<li><?php esc_html_e('Plan and receive incoming stock with restock batches.', 'pre-orders-wc'); ?></li>
							<li><?php esc_html_e('Manage suppliers, purchase orders, reorder suggestions, and multiple locations.', 'pre-orders-wc'); ?></li>
							<li><?php esc_html_e('Allocate incoming inventory and track fulfillment readiness.', 'pre-orders-wc'); ?></li>
						</ul>
					</div>
					<div class="yoohw-pre-orders-upgrade-actions">
						<a class="button button-primary" href="<?php echo esc_url($premium_url); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e('Explore Stock Manager', 'pre-orders-wc'); ?>
						</a>
					</div>
				</div>
			</td>
		</tr>
		<?php
	}
}

new YoOhw_Pre_Orders_WC_Settings();
