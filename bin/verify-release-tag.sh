#!/usr/bin/env bash
# Authenticate the annotated tag during publication recovery and verification.
set -euo pipefail

tag_object="${1:?Tag object is required}"
version="${2:?Version is required}"
candidate_sha="${3:?Candidate SHA is required}"
preparation_run_id="${4:?Preparation run ID is required}"
[[ "$tag_object" =~ ^[0-9a-f]{40}$ && "$candidate_sha" =~ ^[0-9a-f]{40}$ ]]
result="$(gh api "repos/yoohwz/pre-orders-wc/git/tags/$tag_object" --jq '
  [.tag, .object.type, .object.sha, .message] | @json')"
python3 - "$result" "$version" "$candidate_sha" "$preparation_run_id" <<'PY'
import json
import re
import sys

tag, object_type, head, message = json.loads(sys.argv[1])
version, candidate_sha, preparation_run_id = sys.argv[2:]
prefix = f"Pre-Orders {version}\n\nGit SHA: {candidate_sha}\nPreparation run: {preparation_run_id}\nPublication run: "
if (tag != version or object_type != "commit" or head != candidate_sha
        or not re.fullmatch(re.escape(prefix) + r"[0-9]+\n?", message)):
    raise SystemExit("Existing annotated Git tag does not match the prepared release")
PY
