<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YoOhw_Pre_Orders_WC_Push_Subscription {
	const SUBSCRIBE_API_ENDPOINT = 'https://yoexpress.top/wp-json/yo-pr/v1/email-subscriptions';

	public static function push_subscription() {
		$enabled = (bool) apply_filters( 'yoohw_pre_orders_wc_enable_push_subscription', false );
		if ( ! $enabled ) {
			return false;
		}

		$emails = [ get_option( 'admin_email' ) ];

		$recipient = get_option( 'woocommerce_new_order_recipient', '' );
		if ( $recipient ) {
			$emails = array_merge( $emails, array_map( 'trim', explode( ',', $recipient ) ) );
		}

		$emails = array_filter( array_map( 'sanitize_email', $emails ), 'is_email' );
		$emails = array_unique( $emails );
		if ( empty( $emails ) ) {
			return false;
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = preg_replace( '#^www\.#i', '', (string) $host );
		if ( empty( $host ) ) {
			return false;
		}

		$endpoint = esc_url_raw(
			(string) apply_filters(
				'yoohw_pre_orders_wc_push_subscription_endpoint',
				self::SUBSCRIBE_API_ENDPOINT
			)
		);

		if ( empty( $endpoint ) ) {
			return false;
		}

		return wp_safe_remote_post(
			$endpoint,
			[
				'headers'  => [
					'Content-Type' => 'application/json; charset=utf-8',
				],
				'body'     => wp_json_encode(
					[
						'email'    => implode( ',', $emails ),
						'source'   => $host,
						'products' => [ 'wcpo' ],
						'country'  => get_option( 'woocommerce_default_country', '' ),
					]
				),
				'timeout'  => 3,
				'blocking' => false,
			]
		);
	}
}
