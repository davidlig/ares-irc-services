from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[3]

class WorkflowTests(unittest.TestCase):
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
        self.assertEqual('/usr/bin/python3', config['backup_command'][0])
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

    def test_guide_legacy_checkout_needed_only_for_first_cutover(self):
        guide = (ROOT / 'scripts/deploy/README.md').read_text()
        self.assertNotIn('Do not delete the legacy', guide)
        self.assertIn('only after the first successful approved cutover', guide)
        self.assertIn('Application data and logs are disposable', guide)

    def test_infrastructure_tests_follow_dependency_install(self):
        workflow = (ROOT / '.github/workflows/ci.yml').read_text()
        self.assertLess(workflow.index('      - name: Install dependencies'), workflow.index('      - name: Test deployment infrastructure'))
