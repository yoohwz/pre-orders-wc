#!/usr/bin/env bash
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PO_TEST_PHP_BIN:-php}"
WP_CLI="${PO_TEST_WP_CLI:-wp}"
: "${PO_TEST_WP_PATH:?Set PO_TEST_WP_PATH to a disposable WordPress installation}"

if [[ "$WP_CLI" != */* ]]; then
  WP_CLI_PATH="$(command -v "$WP_CLI" || true)"
  if [[ -z "$WP_CLI_PATH" ]]; then
    echo "Unable to locate the WP-CLI executable: $WP_CLI" >&2
    exit 1
  fi
  WP_CLI="$WP_CLI_PATH"
fi

"$PHP_BIN" "$PLUGIN_DIR/tests/smoke/source-contract.php"
for test_file in "$PLUGIN_DIR"/tests/integration/*.php; do
  "$PHP_BIN" "$WP_CLI" "--path=$PO_TEST_WP_PATH" eval-file "$test_file"
done
