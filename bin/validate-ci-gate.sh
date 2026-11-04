#!/usr/bin/env bash
set -euo pipefail

require_result() {
  local name="$1" required="$2" result="$3"
  case "$required" in
    true) [[ "$result" == success ]] || { echo "$name is required but concluded: $result" >&2; exit 1; } ;;
    false) [[ "$result" == success || "$result" == skipped ]] || { echo "$name unexpectedly concluded: $result" >&2; exit 1; } ;;
    *) echo "Invalid requirement for $name: $required" >&2; exit 1 ;;
  esac
}

require_result 'Classify change' true "$CLASSIFY"
require_result 'Repository contracts' true "$CONTRACTS"
require_result 'PHP syntax and source contracts' "$QUALITY_REQUIRED" "$QUALITY"
require_result 'JavaScript syntax' "$JAVASCRIPT_REQUIRED" "$JAVASCRIPT"
require_result 'Pre-Orders integration' "$INTEGRATION_REQUIRED" "$INTEGRATION"
