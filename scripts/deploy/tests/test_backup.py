import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

PATH = Path(__file__).resolve().parents[1] / 'backup.py'

def load():
    spec = importlib.util.spec_from_file_location('backup', PATH)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

class BackupTests(unittest.TestCase):
    def test_client_config_escapes_password(self):
        m = load()
        result = m.client_config({'host':'127.0.0.1','port':3306,'user':'ares','password':'a"\\b'})
        self.assertIn('password="a\\"\\\\b"', result)
        with self.assertRaises(ValueError):
            m.client_config({'host':'bad\nhost','port':3306,'user':'ares','password':'secret'})

    def test_dump_protected_and_password_not_in_arguments(self):
        m = load()
        with tempfile.TemporaryDirectory() as tmp:
            calls = []
            def fake_run(argv, **kwargs):
                calls.append(argv)
                kwargs['stdout'].write(b'dump')
            credentials = {'host':'127.0.0.1','port':3306,'user':'ares','password':'secret','database':'ares'}
            with patch.object(m, 'credentials', return_value=credentials), patch.object(m.subprocess, 'run', side_effect=fake_run):
                m.backup('ares-irc-services', 'mariadb:11.4', Path(tmp))
            target = Path(tmp) / 'database.sql'
            self.assertEqual(0o600, target.stat().st_mode & 0o777)
            self.assertNotIn('secret', repr(calls))
            self.assertIn('--network', calls[0])

    def test_failure_removes_partial_dump(self):
        m = load()
        with tempfile.TemporaryDirectory() as tmp:
            with patch.object(m, 'credentials', side_effect=RuntimeError('secret')):
                with self.assertRaises(RuntimeError):
                    m.backup('ares', 'mariadb:11.4', Path(tmp))
            self.assertFalse((Path(tmp)/'database.sql').exists())

    def test_existing_dump_is_never_deleted(self):
        m = load()
        with tempfile.TemporaryDirectory() as tmp:
            target = Path(tmp) / 'database.sql'
            target.write_text('old backup')
            values = {'host':'localhost','port':3306,'user':'ares','password':'secret','database':'ares'}
            with patch.object(m, 'credentials', return_value=values):
                with self.assertRaises(FileExistsError):
                    m.backup('ares', 'mariadb:11.4', Path(tmp))
            self.assertEqual('old backup', target.read_text())

    def test_failed_dump_removes_partial_and_credentials(self):
        m = load()
        with tempfile.TemporaryDirectory() as tmp:
            values = {'host':'localhost','port':3306,'user':'ares','password':'secret','database':'ares'}
            with patch.object(m, 'credentials', return_value=values), patch.object(m.subprocess, 'run', side_effect=RuntimeError('failure')):
                with self.assertRaises(RuntimeError):
                    m.backup('ares', 'mariadb:11.4', Path(tmp))
            self.assertEqual([], list(Path(tmp).iterdir()))

    def test_credentials_command_does_not_contain_password(self):
        m = load()
        result = type('Result', (), {'stdout': b'{"database":"ares"}'})()
        with patch.object(m.subprocess, 'run', return_value=result) as run:
            self.assertEqual({'database':'ares'}, m.credentials('ares-irc-services'))
        self.assertEqual(m.subprocess.DEVNULL, run.call_args.kwargs['stderr'])
        self.assertEqual(['docker', 'exec', 'ares-irc-services'], run.call_args.args[0][:3])

    def test_connection_parameters_are_typed_and_tcp(self):
        m = load()
        values = {'host':'localhost','port':3306,'user':'ares','password':'secret'}
        self.assertIn('protocol=tcp', m.client_config(values))
        for field, value in [('host', ''), ('host', None), ('port', 0), ('port', 65536), ('port', True), ('port', '3306'), ('user', ''), ('password', b'secret')]:
            with self.subTest(field=field, value=value), self.assertRaises(ValueError):
                m.client_config(dict(values, **{field:value}))

    def test_cli_error_does_not_disclose_exception(self):
        import contextlib
        import io
        m = load()
        output = io.StringIO()
        with patch.object(m, 'backup', side_effect=RuntimeError('private-secret')), patch.object(m.sys, 'argv', ['backup', '/tmp/backup']), contextlib.redirect_stderr(output):
            self.assertEqual(1, m.main())
        self.assertNotIn('private-secret', output.getvalue())

    def test_bootstrap_reads_pinned_image_without_daemon_or_network(self):
        m = load()
        with tempfile.TemporaryDirectory() as tmp:
            env = Path(tmp) / '.env.local'
            env.write_text('DATABASE_URL=private')
            env.chmod(0o600)
            image = 'sha256:' + 'a'*64
            responses = [type('Result', (), {'stdout': b''})(), type('Result', (), {'stdout': b'{"database":"ares"}'})()]
            with patch.object(m.subprocess, 'run', side_effect=responses) as run:
                self.assertEqual({'database':'ares'}, m.credentials('ares', image, env))
            command = run.call_args_list[1].args[0]
            self.assertIn('--read-only', command)
            self.assertIn('--pull', command)
            self.assertIn('never', command)
            self.assertEqual('none', command[command.index('--network')+1])
            self.assertEqual('php', command[command.index('--entrypoint')+1])
            self.assertIn('loadEnv', command[-1])
            self.assertNotIn('bootEnv', command[-1])
            self.assertIn("unset($_SERVER", command[-1])
            self.assertNotIn('private', repr(command))

    def test_bootstrap_does_not_mask_existing_container_failure(self):
        m = load()
        with tempfile.TemporaryDirectory() as tmp:
            env = Path(tmp) / '.env.local'
            env.write_text('DATABASE_URL=private')
            env.chmod(0o600)
            responses = [type('Result', (), {'stdout': b'ares running\n'})(), RuntimeError('secret')]
            with patch.object(m.subprocess, 'run', side_effect=responses) as run:
                with self.assertRaises(RuntimeError):
                    m.credentials('ares', 'sha256:'+'a'*64, env)
            self.assertEqual('exec', run.call_args_list[-1].args[0][1])
            self.assertEqual(2, run.call_count)

    def test_bootstrap_rejects_unpinned_or_insecure_config(self):
        m = load()
        with tempfile.TemporaryDirectory() as tmp:
            env = Path(tmp) / '.env.local'
            env.write_text('DATABASE_URL=private')
            env.chmod(0o600)
            for image, file in [('ares:latest', env), ('sha256:'+'a'*64, None), (None, env)]:
                with self.subTest(image=image), self.assertRaises(ValueError):
                    m.credentials('ares', image, file)
            env.chmod(0o644)
            with self.assertRaises(ValueError):
                m.credentials('ares', 'sha256:'+'a'*64, env)

    def test_bootstrap_daemon_error_and_stopped_container_abort(self):
        m = load()
        with tempfile.TemporaryDirectory() as tmp:
            env = Path(tmp) / '.env.local'
            env.write_text('DATABASE_URL=private')
            env.chmod(0o600)
            for response in [RuntimeError('docker unavailable'), type('Result', (), {'stdout': b'ares exited\n'})()]:
                with patch.object(m.subprocess, 'run', side_effect=[response]) as run:
                    with self.assertRaises((RuntimeError, ValueError)):
                        m.credentials('ares', 'sha256:'+'a'*64, env)
                    self.assertEqual(1, run.call_count)

    def test_bootstrap_dotenv_ignores_dump_and_image_database_url(self):
        import json
        import subprocess
        m = load()
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / '.env').write_text('APP_ENV=dev\nDATABASE_URL=mysql://defaults:default@localhost/defaults\n')
            (root / '.env.local').write_text('APP_ENV=dev\nDATABASE_URL=mysql://ares:private@localhost/ares\n')
            (root / '.env.local.php').write_text("<?php return ['DATABASE_URL'=>'mysql://old:old@localhost/old'];")
            autoload = Path(__file__).resolve().parents[3] / 'vendor/autoload.php'
            php = m.PHP_CREDENTIALS.replace('/app/vendor/autoload.php', str(autoload)).replace('/app/.env', str(root / '.env'))
            php = php.replace('->bootEnv(', '->loadEnv(')
            php = 'unset($_SERVER["DATABASE_URL"], $_ENV["DATABASE_URL"]); putenv("DATABASE_URL");' + php
            result = subprocess.run(['php', '-r', php], check=True, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
                                    env=dict(m.os.environ, APP_ENV='prod', DATABASE_URL='mysql://stale:stale@localhost/stale'))
            values = json.loads(result.stdout)
            self.assertEqual('ares', values['database'])
            self.assertEqual('private', values['password'])
