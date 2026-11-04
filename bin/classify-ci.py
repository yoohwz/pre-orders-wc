#!/usr/bin/env python3
"""Select Pre-Orders' existing checks; unknown paths fail closed to runtime validation."""
import argparse
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROL_PLANE = {
    '.github/workflows/pre-orders-compatibility.yml',
    'bin/classify-ci.py', 'bin/validate-ci-gate.sh',
    'tests/compatibility-matrix.json', 'tests/ci/test_workflow.py',
}


def classify(event, draft, paths, jobs):
    paths = set(paths)
    full = event != 'pull_request' or not draft
    control = bool(paths & CONTROL_PLANE) or any(
        p.startswith(('.github/workflows/', 'bin/', 'tests/ci/')) for p in paths
    )
    runtime = not paths
    javascript = False
    for path in paths:
        if path in CONTROL_PLANE or path.startswith(('bin/', 'tests/ci/', '.github/')):
            continue
        if path == 'AGENTS.md' or path.startswith('docs/') or path in {'.gitignore'}:
            continue
        if path.startswith(('js/', 'tests/js/')):
            javascript = True
        elif path.startswith(('css/', 'img/', 'languages/')) or path in {'license.txt', 'changelog.txt'}:
            continue
        else:
            # Includes PHP, fixtures/runner, metadata, distribution and unknown paths.
            runtime = True
    if event == 'workflow_dispatch' or (full and control) or not paths:
        runtime = javascript = True
    representative = next(job for job in jobs if job['php'] == '8.2' and job['order_storage'] == 'hpos')
    return {
        'intensity': 'final' if full else 'iteration', 'full': full,
        'control_plane': control, 'quality': runtime, 'integration': runtime,
        'javascript': javascript,
        'matrix': {'include': jobs if full else [representative]},
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--event-name', choices=['push', 'pull_request', 'workflow_dispatch'], required=True)
    parser.add_argument('--pr-draft', choices=['true', 'false'], default='false')
    parser.add_argument('--changed-files', type=Path, required=True)
    parser.add_argument('--github-output', type=Path, required=True)
    args = parser.parse_args()
    jobs = json.loads((ROOT / 'tests/compatibility-matrix.json').read_text())['jobs']
    result = classify(args.event_name, args.pr_draft == 'true', args.changed_files.read_text().splitlines(), jobs)
    with args.github_output.open('a') as output:
        for key, value in result.items():
            if isinstance(value, bool):
                value = str(value).lower()
            elif isinstance(value, dict):
                value = json.dumps(value, separators=(',', ':'))
            output.write(f'{key}={value}\n')


if __name__ == '__main__':
    main()
