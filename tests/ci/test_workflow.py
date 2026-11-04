"""Routing and negative gate regression tests for the supported Pre-Orders CI graph."""
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('classify_ci', ROOT / 'bin/classify-ci.py')
ci = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ci)
JOBS = json.loads((ROOT / 'tests/compatibility-matrix.json').read_text())['jobs']


class RoutingTests(unittest.TestCase):
    def route(self, paths, draft=False, event='pull_request'):
        return ci.classify(event, draft, paths, JOBS)

    def test_iteration_and_final_runtime_coverage(self):
        for path in ['inc/cores/compatibility.php', 'pre-orders-wc.php',
                     'tests/run-preorders-suite.sh', 'readme.txt', 'unknown/path']:
            for draft in [False, True]:
                with self.subTest(path=path, draft=draft):
                    result = self.route([path], draft)
                    self.assertTrue(result['quality'])
                    self.assertTrue(result['integration'])
                    self.assertEqual(result['full'], not draft)
                    self.assertEqual(result['matrix']['include'],
                                     [JOBS[2]] if draft else JOBS)

    def test_governance_does_not_start_runtime(self):
        for draft in [False, True]:
            result = self.route(['AGENTS.md', 'docs/workflow.md', '.github/pull_request_template.md'], draft)
            for check in ['quality', 'integration', 'javascript']:
                self.assertFalse(result[check])

    def test_control_plane_self_tests_final_graph(self):
        for path in ci.CONTROL_PLANE | {'.github/workflows/other.yml', 'bin/new-helper.py'}:
            with self.subTest(path=path):
                result = self.route([path])
                self.assertTrue(result['control_plane'])
                for check in ['quality', 'integration', 'javascript']:
                    self.assertTrue(result[check])
                self.assertEqual(result['matrix']['include'], JOBS)

    def test_javascript_and_mixed_changes(self):
        result = self.route(['js/orders.js'], True)
        self.assertTrue(result['javascript'])
        self.assertFalse(result['integration'])
        result = self.route(['js/orders.js', 'inc/backend/actions/auto-update-to-instock.php'], True)
        self.assertTrue(result['javascript'])
        self.assertTrue(result['integration'])

    def test_empty_input_and_manual_dispatch_fail_closed(self):
        for paths, event in [([], 'pull_request'), (['docs/workflow.md'], 'workflow_dispatch')]:
            result = self.route(paths, event=event)
            self.assertTrue(all(result[key] for key in ['quality', 'integration', 'javascript']))

    def test_main_push_is_final(self):
        result = self.route(['inc/cores/compatibility.php'], True, 'push')
        self.assertTrue(result['full'])
        self.assertEqual(result['matrix']['include'], JOBS)

    def test_cli_outputs_json_matrix(self):
        with tempfile.TemporaryDirectory() as directory:
            paths, output = Path(directory) / 'paths', Path(directory) / 'output'
            paths.write_text('inc/backend/actions/auto-update-to-instock.php\n')
            subprocess.run(['python3', str(ROOT / 'bin/classify-ci.py'), '--event-name',
                            'pull_request', '--pr-draft', 'true', '--changed-files', str(paths),
                            '--github-output', str(output)], check=True)
            values = dict(line.split('=', 1) for line in output.read_text().splitlines())
            self.assertEqual(values['full'], 'false')
            self.assertEqual(values['integration'], 'true')
            self.assertEqual(json.loads(values['matrix'])['include'], [JOBS[2]])


class GateTests(unittest.TestCase):
    def gate(self, **overrides):
        env = dict(os.environ, CLASSIFY='success', CONTRACTS='success',
                   QUALITY_REQUIRED='true', JAVASCRIPT_REQUIRED='true', INTEGRATION_REQUIRED='true',
                   QUALITY='success', JAVASCRIPT='success', INTEGRATION='success')
        env.update(overrides)
        return subprocess.run(['bash', str(ROOT / 'bin/validate-ci-gate.sh')],
                              env=env, capture_output=True).returncode

    def test_success(self):
        self.assertEqual(self.gate(), 0)

    def test_every_required_failure_cancellation_skip_or_missing_fails(self):
        for job in ['CLASSIFY', 'CONTRACTS', 'QUALITY', 'JAVASCRIPT', 'INTEGRATION']:
            for result in ['failure', 'cancelled', 'skipped', '', 'unknown']:
                with self.subTest(job=job, result=result):
                    self.assertNotEqual(self.gate(**{job: result}), 0)

    def test_only_irrelevant_jobs_may_skip(self):
        for job in ['QUALITY', 'JAVASCRIPT', 'INTEGRATION']:
            for result in ['success', 'skipped', 'failure', 'cancelled', '']:
                with self.subTest(job=job, result=result):
                    code = self.gate(**{job + '_REQUIRED': 'false', job: result})
                    self.assertEqual(code == 0, result in ['success', 'skipped'])

    def test_invalid_classifier_output_fails(self):
        for value in ['', 'unknown']:
            self.assertNotEqual(self.gate(QUALITY_REQUIRED=value), 0)


class RepositoryTests(unittest.TestCase):
    def test_integration_inventory_is_complete(self):
        runner = (ROOT / 'tests/run-preorders-suite.sh').read_text()
        self.assertIn('"$PLUGIN_DIR"/tests/integration/*.php', runner)
        self.assertIn('tests/smoke/source-contract.php', runner)
        self.assertIn('PO_TEST_WP_PATH:?', runner)
        self.assertNotIn('$PLUGIN_DIR/../../..', runner)

    def test_runner_executes_every_suite_with_explicit_wordpress_path(self):
        with tempfile.TemporaryDirectory() as directory:
            fake_php = Path(directory) / 'php'
            log = Path(directory) / 'calls'
            fake_php.write_text('#!/usr/bin/env bash\nprintf "%s\\n" "$*" >> "$PO_TEST_LOG"\n')
            fake_php.chmod(0o755)
            env = dict(os.environ, PO_TEST_PHP_BIN=str(fake_php),
                       PO_TEST_WP_CLI='/disposable/wp-cli.phar',
                       PO_TEST_WP_PATH='/disposable/site with spaces', PO_TEST_LOG=str(log))
            subprocess.run(['bash', str(ROOT / 'tests/run-preorders-suite.sh')],
                           env=env, check=True, capture_output=True)
            calls = log.read_text().splitlines()
            self.assertEqual(calls[0], str(ROOT / 'tests/smoke/source-contract.php'))
            self.assertCountEqual(calls[1:], [
                '/disposable/wp-cli.phar --path=/disposable/site with spaces eval-file ' + str(path)
                for path in (ROOT / 'tests/integration').glob('*.php')])

    def test_runner_requires_explicit_wordpress_path(self):
        env = dict(os.environ)
        env.pop('PO_TEST_WP_PATH', None)
        result = subprocess.run(['bash', str(ROOT / 'tests/run-preorders-suite.sh')],
                                env=env, capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('disposable WordPress installation', result.stderr)

    def test_supported_matrix_is_not_silently_reduced(self):
        self.assertEqual(JOBS, [
            {'wordpress': '6.5.5', 'woocommerce': '8.0.3', 'php': '7.4', 'order_storage': 'legacy'},
            {'wordpress': '6.6.2', 'woocommerce': '9.0.2', 'php': '8.0', 'order_storage': 'hpos'},
            {'wordpress': '6.8.3', 'woocommerce': '10.0.4', 'php': '8.2', 'order_storage': 'hpos'},
            {'wordpress': '7.0.2', 'woocommerce': '10.9.4', 'php': '8.4', 'order_storage': 'hpos'},
        ])

    def test_workflow_graph_and_candidate_binding(self):
        workflow = (ROOT / '.github/workflows/pre-orders-compatibility.yml').read_text()
        blocks = dict(re.findall(r'^  ([\w-]+):\n(.*?)(?=^  [\w-]+:|\Z)', workflow.split('jobs:\n', 1)[1], re.M | re.S))
        self.assertEqual(set(blocks), {'classify', 'repository-contracts', 'quality', 'javascript', 'integration', 'ci-gate'})
        for block in blocks.values():
            self.assertIn('ref: ${{ github.event.pull_request.head.sha || github.sha }}', block)
            self.assertIn('persist-credentials: false', block)
            self.assertIn('timeout-minutes:', block)
        self.assertIn("matrix: ${{ fromJSON(needs.classify.outputs.matrix) }}", blocks['integration'])
        self.assertIn('tests/run-preorders-suite.sh', blocks['integration'])
        self.assertIn('tests/smoke/source-contract.php', blocks['quality'])
        self.assertIn('OrderUtil::custom_orders_table_usage_is_enabled()', blocks['integration'])
        self.assertIn('git diff --no-renames --name-only', blocks['classify'])
        self.assertIn('if: ${{ always() }}', blocks['ci-gate'])
        self.assertIn('needs: [classify, repository-contracts, quality, javascript, integration]', blocks['ci-gate'])
        self.assertIn("'CI Gate' || 'Iteration Gate'", blocks['ci-gate'])
        for job in ['classify', 'repository-contracts', 'quality', 'javascript', 'integration']:
            self.assertIn('${{ needs.' + job + '.result }}', blocks['ci-gate'])
        for event in ['ready_for_review', 'converted_to_draft']:
            self.assertIn(event, workflow)
        self.assertIn('branches: [main]', workflow)
        for job in ['quality', 'javascript', 'integration']:
            self.assertIn("if: ${{ needs.classify.outputs." + job + " == 'true' }}", blocks[job])
            self.assertIn('needs: classify', blocks[job])

    def test_development_files_excluded_from_distribution(self):
        patterns = (ROOT / '.distignore').read_text().splitlines()
        for path in ['.github', 'AGENTS.md', 'docs', 'bin', 'tests']:
            self.assertIn(path, patterns)


if __name__ == '__main__':
    unittest.main()
