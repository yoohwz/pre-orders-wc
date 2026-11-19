#!/usr/bin/env bash
# Require the actual GitHub Actions gate on the merged candidate.
set -euo pipefail

candidate_sha="${1:?Full candidate SHA is required}"
[[ "$candidate_sha" =~ ^[0-9a-f]{40}$ ]]
repo=yoohwz/pre-orders-wc
workflow=.github/workflows/pre-orders-compatibility.yml
run_id="$(gh api --method GET "repos/$repo/actions/workflows/pre-orders-compatibility.yml/runs" \
  -f branch=main -f head_sha="$candidate_sha" -f per_page=100 --jq "
    [.workflow_runs[] | select(
      .path == \"$workflow\" and .head_sha == \"$candidate_sha\" and .head_branch == \"main\"
      and .head_repository.full_name == \"$repo\" and (.event == \"push\" or .event == \"workflow_dispatch\")
    )] | first | select(.status == \"completed\" and .conclusion == \"success\") | .id")"
[[ "$run_id" =~ ^[0-9]+$ ]] || { echo "No successful current main CI run for $candidate_sha" >&2; exit 1; }

job_id="$(gh api --method GET "repos/$repo/actions/runs/$run_id/jobs" -f filter=latest -f per_page=100 --jq "
  [.jobs[] | select(.name == \"CI Gate\" and .head_sha == \"$candidate_sha\" and .conclusion == \"success\")]
  | select(length == 1) | first | .id")"
[[ "$job_id" =~ ^[0-9]+$ ]] || { echo "Main CI run $run_id has no successful CI Gate for $candidate_sha" >&2; exit 1; }

result="$(gh api "repos/$repo/check-runs/$job_id" --jq "
  .name == \"CI Gate\" and .app.id == 15368 and .head_sha == \"$candidate_sha\" and .conclusion == \"success\"")"
[[ "$result" == true ]] || { echo "CI Gate check $job_id did not authenticate as GitHub Actions" >&2; exit 1; }
printf 'Verified CI Gate for %s in GitHub Actions run %s\n' "$candidate_sha" "$run_id"
