<?php

if (!defined('ABSPATH')) {
	exit;
}

class YoOhw_Pre_Orders_WC_Notices {

	public function __construct() {
		add_action('admin_notices', [$this, 'display_notices']);
		add_action('wp_ajax_never_show_wc_pre_orders_notice', [$this, 'never_show_notice']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_inline_scripts']);
		add_action('wp_ajax_dismiss_settings_notice', [$this, 'dismiss_settings_notice']);
	}

	public function display_notices() {
		$this->settings_notice();
		$this->admin_notice();
	}

	public function settings_notice() {
		$user_id = get_current_user_id();

		if ( current_user_can( 'manage_options' ) && get_user_meta( $user_id, 'wcpo_pre_orders_settings_notice', true ) !== 'yes' ) {
			$settings_url = admin_url( 'admin.php?page=wc-settings&tab=products&section=stock_status' );
			echo '<div class="notice notice-info wcpo-settings is-dismissible">';
			echo '<p><strong>' . esc_html__( 'Before using Pre-Orders for WooCommerce,', 'pre-orders-wc' ) . '</strong> ' . esc_html__( 'please configure your settings.', 'pre-orders-wc' ) . '</p>';
			echo '<p><a href="#" onclick="YoOhw_Pre_Orders_WC_Notice.dismissSettingsNotice()" class="button-secondary">' . esc_html__( 'Dismiss this notice', 'pre-orders-wc' ) . '</a> <a href="' . esc_url( $settings_url ) . '" class="button-primary">' . esc_html__( 'Go to the settings', 'pre-orders-wc' ) . '</a></p>';
			echo '</div>';
		}
	}

	public function admin_notice() {
		$user_id = get_current_user_id();
		$activation_time = get_user_meta($user_id, 'wc_pre_orders_activation_time', true);
		$current_time = current_time('timestamp');
	
		if (get_user_meta($user_id, 'wc_pre_orders_never_show_again', true) === 'yes') {
			return;
		}
	
		if (!$activation_time) {
			update_user_meta($user_id, 'wc_pre_orders_activation_time', $current_time);
			return;
		}
	
		$time_since_activation = $current_time - $activation_time;
		$days_since_activation = floor($time_since_activation / DAY_IN_SECONDS);
	
		if (current_user_can('manage_options') && $days_since_activation >= 1) {
			echo '<div class="notice notice-info is-dismissible">';
			echo '<p>' . wp_kses_post(
				sprintf(
					/* translators: %s: plugin review link. */
					__( 'Thank you for using Pre-Orders for WooCommerce. If the plugin helps your store, please consider %s to support continued maintenance.', 'pre-orders-wc' ),
					'<a href="https://wordpress.org/plugins/pre-orders-wc/#reviews/#new-post" target="_blank" rel="noopener noreferrer">' . esc_html__( 'leaving a review', 'pre-orders-wc' ) . '</a>'
				)
			) . ' <span style="color: #e26f56;">&#9733;&#9733;&#9733;&#9733;&#9733;</span></p>';
			echo '<p><a href="#" onclick="YoOhw_Pre_Orders_WC_Notice.dismissForever()">' . esc_html__( 'Never show this again', 'pre-orders-wc' ) . '</a></p>';
			echo '</div>';
		}
	}

	public function enqueue_inline_scripts() {
		$nonce_settings = wp_create_nonce('settings_nonce');
		$nonce_never_show = wp_create_nonce('never_show_wc_pre_orders_notice_nonce');

		$script = "
			var YoOhw_Pre_Orders_WC_Notice = {
				dismissSettingsNotice: function() {
                    jQuery.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'dismiss_settings_notice',
                            security: '{$nonce_settings}'
                        },
                        success: function() {
                            jQuery('.notice.notice-info.wcpo-settings').hide();
                        }
                    });
                },
				dismissForever: function() {
					jQuery.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'never_show_wc_pre_orders_notice',
							security: '{$nonce_never_show}'
						},
						success: function() {
							jQuery('.notice.notice-info').hide();
						}
					});
					}
				};
			";

		wp_add_inline_script('jquery', $script);
	}

	public function dismiss_settings_notice() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error();
		}

        check_ajax_referer('settings_nonce', 'security');
		$user_id = get_current_user_id();

        update_user_meta($user_id, 'wcpo_pre_orders_settings_notice', 'yes');
		wp_send_json_success();
    }

	public function never_show_notice() {
		if (!current_user_can('manage_options')) {
			wp_send_json_error();
		}

		check_ajax_referer('never_show_wc_pre_orders_notice_nonce', 'security');
		$user_id = get_current_user_id();
		update_user_meta($user_id, 'wc_pre_orders_never_show_again', 'yes');
		wp_send_json_success();
	}	
}

new YoOhw_Pre_Orders_WC_Notices();
