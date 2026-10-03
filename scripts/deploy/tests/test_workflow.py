from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]

class WorkflowTests(unittest.TestCase):
    def test_composer_audit_is_a_required_deployment_gate(self):
        workflow = (ROOT / '.github/workflows/ci.yml').read_text()
        test_job = workflow.split('\n  test:\n', 1)[1].split('\n  migrations:\n', 1)[0]
        test_settings = test_job.split('    steps:\n', 1)[0]
        self.assertNotRegex(test_settings, r'(?m)^    (?:if|continue-on-error):')
        self.assertNotRegex(test_settings, r'(?m)^    defaults:')

        audit_marker = '      - name: Audit Composer dependencies\n'
        self.assertIn(audit_marker, test_job)
        audit_step = test_job.split(audit_marker, 1)[1].split('\n      - ', 1)[0]
        # One exact command audits the complete lock, including dev dependencies.
        # Additional step options or shell commands could skip/swallow failures.
        self.assertEqual(['run: composer audit --locked'],
                         [line.strip() for line in audit_step.splitlines() if line.strip()])
        self.assertLess(test_job.index(audit_marker),
                        test_job.index('      - name: Install dependencies'))

        deploy_settings = workflow.split('\n  deploy:\n', 1)[1].split('    steps:\n', 1)[0]
        self.assertIn('    needs: [test, migrations]\n', deploy_settings)
        self.assertNotRegex(deploy_settings, r'(?m)^    continue-on-error:')
        conditions = [line.strip() for line in deploy_settings.splitlines()
                      if line.startswith('    if:')]
        # Keep GitHub's implicit success() guard; never allow always()/failure().
        self.assertEqual(["if: github.event_name == 'push' && github.ref == 'refs/heads/main'"],
                         conditions)

    def test_deploy_gates_and_isolation(self):
        workflow = (ROOT / '.github/workflows/ci.yml').read_text()
        self.assertIn('needs: [test, migrations]', workflow)
        self.assertIn("github.event_name == 'push' && github.ref == 'refs/heads/main'", workflow)
        self.assertIn('runs-on: [self-hosted, linux, x64, ares-deploy]', workflow)
        self.assertIn('cancel-in-progress: false', workflow)
        self.assertIn('persist-credentials: false', workflow)
        self.assertIn('ref: ${{ github.sha }}', workflow)
        self.assertIn('secrets.DEPLOY_COMMAND', workflow)
        self.assertIn('python3 -m unittest discover -s scripts/deploy/tests -v', workflow)
        self.assertIn('environment: production', workflow)
        self.assertIn('sha256sum', workflow)

    def test_runner_has_only_scoped_volumes(self):
        template = (ROOT / 'scripts/deploy/templates/runner-compose.yaml').read_text()
        self.assertNotIn('docker.sock', template)
        self.assertNotIn('/run/user', template)
        self.assertIn('/runner-command:ro', template)
        self.assertIn('--labels ares-deploy', template)
        self.assertIn('RUNNER_TOKEN:-', template)
        service = (ROOT / 'scripts/deploy/templates/ares-deploy@.service').read_text()
        self.assertIn('deploy.py serve', service)
        self.assertIn('StandardError=null', service)

    def test_config_example_contains_no_credentials(self):
        import json
        config = json.loads((ROOT / 'scripts/deploy/templates/config.example.json').read_text())
        self.assertEqual('/runner-state/_work', config['runner_container_work_root'])
        self.assertNotIn('backup_command', config)
        self.assertNotIn('password', str(config).lower())

    def test_packaging_shell_syntax(self):
        import subprocess
        workflow = (ROOT / '.github/workflows/ci.yml').read_text()
        block = workflow.split('      - name: Package and submit verified sources', 1)[1]
        script = block.split('        run: |\n', 1)[1]
        script = '\n'.join(line[10:] if line.startswith('          ') else line for line in script.splitlines())
        subprocess.run(['bash', '-n'], input=script, text=True, check=True, capture_output=True)

    def test_environment_is_external_user_provided_secret(self):
        import json
        config = json.loads((ROOT / 'scripts/deploy/templates/config.example.json').read_text())
        self.assertEqual('/home/DEPLOY_USER/deploy/secrets/.env.local', config['env_file'])
        self.assertNotIn('data_dir', config)
        self.assertNotIn('log_dir', config)

    def test_infrastructure_tests_follow_dependency_install(self):
        workflow = (ROOT / '.github/workflows/ci.yml').read_text()
        self.assertLess(workflow.index('      - name: Install dependencies'), workflow.index('      - name: Test deployment infrastructure'))

    def test_automatic_backup_helpers_are_absent(self):
        self.assertFalse((ROOT / 'scripts/deploy/backup.py').exists())
        self.assertFalse((ROOT / 'scripts/deploy/tests/test_backup.py').exists())
