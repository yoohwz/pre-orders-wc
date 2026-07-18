<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'yoohw_pre_orders_wc_parse_available_date' ) ) {
	function yoohw_pre_orders_wc_parse_available_date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		try {
			return new DateTimeImmutable( $value, wp_timezone() );
		} catch ( Exception $exception ) {
			unset( $exception );
			return null;
		}
	}
}

if ( ! function_exists( 'yoohw_pre_orders_wc_normalize_available_date' ) ) {
	function yoohw_pre_orders_wc_normalize_available_date( $value ) {
		$date = yoohw_pre_orders_wc_parse_available_date( $value );

		return $date ? $date->format( 'Y-m-d\TH:i' ) : '';
	}
}

if ( ! function_exists( 'yoohw_pre_orders_wc_format_available_date' ) ) {
	function yoohw_pre_orders_wc_format_available_date( $value, $include_time = false ) {
		$date = yoohw_pre_orders_wc_parse_available_date( $value );
		if ( ! $date ) {
			return '';
		}

		$format = (string) get_option( 'date_format' );
		if ( $include_time ) {
			$format .= ' ' . (string) get_option( 'time_format' );
		}

		return wp_date( $format, $date->getTimestamp(), wp_timezone() );
	}
}

if ( ! function_exists( 'yoohw_pre_orders_wc_get_product_available_date' ) ) {
	function yoohw_pre_orders_wc_get_product_available_date( $product_or_id ) {
		$product_id = $product_or_id instanceof WC_Product ? $product_or_id->get_id() : absint( $product_or_id );
		if ( ! $product_id ) {
			return '';
		}

		$date = (string) get_post_meta( $product_id, '_extended_status_available_date', true );
		if ( '' === trim( $date ) ) {
			$date = (string) get_post_meta( $product_id, '_pre_order_available_date', true );
		}

		return yoohw_pre_orders_wc_normalize_available_date( $date );
	}
}

if ( ! function_exists( 'yoohw_pre_orders_wc_update_product_available_date' ) ) {
	function yoohw_pre_orders_wc_update_product_available_date( $product_id, $value ) {
		$product_id = absint( $product_id );
		$date       = yoohw_pre_orders_wc_normalize_available_date( $value );
		if ( ! $product_id ) {
			return '';
		}

		foreach ( [ '_pre_order_available_date', '_extended_status_available_date' ] as $meta_key ) {
			if ( '' === $date ) {
				delete_post_meta( $product_id, $meta_key );
			} else {
				update_post_meta( $product_id, $meta_key, $date );
			}
		}

		return $date;
	}
}

if ( ! function_exists( 'yoohw_pre_orders_wc_get_order_item_stock_status' ) ) {
	function yoohw_pre_orders_wc_get_order_item_stock_status( $item, $fallback_product = null ) {
		if ( $item instanceof WC_Order_Item_Product ) {
			$snapshot = (string) $item->get_meta( '_wcess_stock_status', true );
			if ( '' !== $snapshot ) {
				return $snapshot;
			}
			if ( ! $fallback_product ) {
				$fallback_product = $item->get_product();
			}
		}

		return $fallback_product instanceof WC_Product ? (string) $fallback_product->get_stock_status() : '';
	}
}

if ( ! function_exists( 'yoohw_pre_orders_wc_get_order_item_available_date' ) ) {
	function yoohw_pre_orders_wc_get_order_item_available_date( $item, $fallback_product = null ) {
		if ( $item instanceof WC_Order_Item_Product ) {
			$snapshot = (string) $item->get_meta( '_wcess_available_date', true );
			if ( '' !== $snapshot ) {
				return yoohw_pre_orders_wc_normalize_available_date( $snapshot );
			}
			if ( ! $fallback_product ) {
				$fallback_product = $item->get_product();
			}
		}

		return $fallback_product instanceof WC_Product ? yoohw_pre_orders_wc_get_product_available_date( $fallback_product ) : '';
	}
}

if ( ! function_exists( 'yoohw_pre_orders_wc_get_tracked_product_ids' ) ) {
	function yoohw_pre_orders_wc_get_tracked_product_ids( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return [];
		}

		$raw = $order->get_meta( '_pre_order_items', true );
		$ids = is_array( $raw ) ? $raw : preg_split( '/\s*,\s*/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );

		return array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
	}
}

if ( ! function_exists( 'yoohw_pre_orders_wc_get_product_name' ) ) {
	function yoohw_pre_orders_wc_get_product_name( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		if ( $product->is_type( 'variation' ) && function_exists( 'wc_get_formatted_variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$variation = wc_get_formatted_variation( $product, true, false, true );
				return '' !== $variation ? $parent->get_name() . ' - ' . $variation : $parent->get_name();
			}
		}

		return $product->get_name();
	}
}

class YoOhw_Pre_Orders_WC_Compatibility {
	const DB_VERSION        = '1.3.0';
	const DB_VERSION_OPTION = 'yoohw_pre_orders_wc_db_version';

	public function __construct() {
		add_action( 'admin_init', [ __CLASS__, 'maybe_upgrade' ], 5 );
		add_filter( 'woocommerce_hidden_order_itemmeta', [ $this, 'hide_internal_order_item_meta' ] );
	}

	public static function activate() {
		self::maybe_upgrade();
	}

	public static function maybe_upgrade() {
		$current = (string) get_option( self::DB_VERSION_OPTION, '0.0.0' );
		if ( version_compare( $current, self::DB_VERSION, '>=' ) ) {
			return;
		}

		self::migrate_free_available_dates();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
		do_action( 'yoohw_pre_orders_wc_database_upgraded', $current, self::DB_VERSION );
	}

	private static function migrate_free_available_dates() {
		global $wpdb;

		$last_meta_id = 0;
		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT legacy.meta_id, legacy.post_id, legacy.meta_value FROM %i legacy
					INNER JOIN %i posts ON posts.ID = legacy.post_id
					LEFT JOIN %i current ON current.post_id = legacy.post_id AND current.meta_key = %s
					WHERE legacy.meta_key = %s AND legacy.meta_id > %d AND legacy.meta_value <> %s AND current.meta_id IS NULL
					AND posts.post_type IN (%s,%s) AND posts.post_status <> %s
					ORDER BY legacy.meta_id ASC LIMIT 500',
					$wpdb->postmeta,
					$wpdb->posts,
					$wpdb->postmeta,
					'_extended_status_available_date',
					'_pre_order_available_date',
					$last_meta_id,
					'',
					'product',
					'product_variation',
					'trash'
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $row ) {
				$last_meta_id = max( $last_meta_id, absint( $row['meta_id'] ?? 0 ) );
				$date         = yoohw_pre_orders_wc_normalize_available_date( $row['meta_value'] ?? '' );
				if ( '' !== $date ) {
					update_post_meta( absint( $row['post_id'] ?? 0 ), '_extended_status_available_date', $date );
				}
			}
		} while ( 500 === count( (array) $rows ) );
	}

	public function hide_internal_order_item_meta( $keys ) {
		$keys[] = '_wcess_stock_status';
		$keys[] = '_wcess_available_date';
		$keys[] = '_yoohw_preorder_delay_notice_date';

		return array_values( array_unique( $keys ) );
	}
}

new YoOhw_Pre_Orders_WC_Compatibility();
