#!/usr/bin/env bash
set -euo pipefail

: "${RUNNER_TEMP:?GitHub Actions runner temporary directory is required}"
: "${GITHUB_WORKSPACE:?GitHub Actions workspace is required}"
: "${WP_VERSION:?WordPress version is required}"
: "${WC_VERSION:?WooCommerce version is required}"
case "${ORDER_STORAGE:-}" in
  hpos|legacy) ;;
  *) echo 'Expected ORDER_STORAGE=hpos or ORDER_STORAGE=legacy' >&2; exit 1 ;;
esac

wp_path="$RUNNER_TEMP/wordpress"
mkdir "$wp_path"
wp core download --path="$wp_path" --version="$WP_VERSION"
wp config create --path="$wp_path" --dbname=wordpress --dbuser=root --dbpass=root --dbhost=127.0.0.1:3306 --skip-check
wp core install --path="$wp_path" --url=https://pre-orders.test --title="Pre-Orders CI" --admin_user=admin --admin_password=password --admin_email=admin@example.test --skip-email

wc_version_args=()
if [[ "$WC_VERSION" != latest ]]; then
  wc_version_args=(--version="$WC_VERSION")
fi
wp plugin install woocommerce --path="$wp_path" "${wc_version_args[@]}" --activate
ln -s "$GITHUB_WORKSPACE" "$wp_path/wp-content/plugins/pre-orders-wc"

if [[ "$ORDER_STORAGE" == hpos ]]; then
  wp option update woocommerce_custom_orders_table_enabled yes --path="$wp_path"
  wp option update woocommerce_custom_orders_table_data_sync_enabled no --path="$wp_path"
else
  wp option update woocommerce_custom_orders_table_enabled no --path="$wp_path"
fi
wp plugin activate pre-orders-wc --path="$wp_path"
wp --path="$wp_path" core version
wp --path="$wp_path" plugin get woocommerce --field=version
