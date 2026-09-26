import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest.mock import patch
from contextlib import redirect_stdout

spec = importlib.util.spec_from_file_location('deploy', Path(__file__).parents[1] / 'deploy.py')
deploy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(deploy)


class DeployTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        for name in ('work', 'deploy', 'data', 'logs', 'legacy'):
            (self.root / name).mkdir(mode=0o700)
        self.env = self.root / 'env'
        self.env.write_text('DATABASE_URL=secret\n')
        self.env.chmod(0o600)
        self.helper = self.root / 'backup'
        self.helper.write_text('#!/bin/sh\nexit 0\n')
        self.helper.chmod(0o700)
        self.config = dict(runner_work_root=str(self.root / 'work'),
                           runner_container_work_root='/runner-state/_work',
                           deploy_root=str(self.root / 'deploy'), env_file=str(self.env),
                           legacy_checkout=str(self.root / 'legacy'),
                           backup_command=[str(self.helper)], readiness_timeout=2)
        self.config_path = self.root / 'config.json'
        self.config_path.write_text(json.dumps(self.config))
        self.config_path.chmod(0o600)
        self.sha = 'a' * 40

    def archive(self, entries=None):
        path = self.root / 'work' / 'release.tar'
        entries = entries or {'Makefile': b'up:\n', 'docker/compose.yaml': b'services:\n', 'docker/compose.production.yaml': b'services:\n'}
        with tarfile.open(path, 'w') as archive:
            for name, content in entries.items():
                info = tarfile.TarInfo(name)
                info.size = len(content)
                archive.addfile(info, io.BytesIO(content))
        return ['/runner-state/_work/release.tar', hashlib.sha256(path.read_bytes()).hexdigest(), self.sha]

    def test_request_shape_and_identifiers(self):
        valid = self.archive()
        self.assertEqual(valid, deploy.parse_request(json.dumps(valid)))
        for invalid in (valid + ['extra'], ['a', 2, self.sha], [valid[0], '0', self.sha],
                        [valid[0], valid[1], '../oops'], {'archive': valid[0]}):
            with self.subTest(invalid=invalid), self.assertRaises(deploy.DeployError):
                deploy.parse_request(json.dumps(invalid))

    def test_config_permissions_and_unknown_keys(self):
        deploy.load_config(self.config_path)
        self.config_path.chmod(0o666)
        with self.assertRaises(deploy.DeployError):
            deploy.load_config(self.config_path)
        self.config_path.chmod(0o600)
        self.config['shell'] = 'injection'
        self.config_path.write_text(json.dumps(self.config))
        with self.assertRaises(deploy.DeployError):
            deploy.load_config(self.config_path)

    def test_path_escape_and_symlinks(self):
        cfg = deploy.load_config(self.config_path)
        for path in ('/runner-state/_work/../env', '/other/release.tar'):
            with self.subTest(path=path), self.assertRaises(deploy.DeployError):
                deploy.archive_path(cfg, path)
        (self.root / 'work' / 'link').symlink_to(self.env)
        with self.assertRaises(deploy.DeployError):
            deploy.archive_path(cfg, '/runner-state/_work/link')

    def test_tar_escape_and_links(self):
        for name in ('../escape', '/escape', '.env.local', 'var/log/secret'):
            with self.subTest(name=name), self.assertRaises(deploy.DeployError):
                request = self.archive({name: b'x'})
                deploy.extract_archive(self.root / 'work/release.tar', self.root / 'extract')
        path = self.root / 'work/release.tar'
        with tarfile.open(path, 'w') as archive:
            entry = tarfile.TarInfo('link')
            entry.type = tarfile.SYMTYPE
            entry.linkname = '/etc/passwd'
            archive.addfile(entry)
        with self.assertRaises(deploy.DeployError):
            deploy.extract_archive(path, self.root / 'extract')

    def test_lock_excludes_second_executor(self):
        with deploy.deployment_lock(self.root / 'deploy'):
            with self.assertRaises(deploy.DeployError):
                with deploy.deployment_lock(self.root / 'deploy'):
                    pass

    def test_fresh_logs_rotation_and_truncation(self):
        path = self.root / 'logs' / 'irc.log'
        path.write_text('Sent EOS — initial burst and sync complete.\n')
        logs = deploy.FreshLogs(self.root / 'logs')
        self.assertEqual('', logs.read())
        with path.open('a') as stream:
            stream.write('NickServ introduced to network.\n')
        self.assertIn('NickServ', logs.read())
        path.rename(path.with_suffix('.old'))
        path.write_text('ChanServ introduced to network.\n')
        self.assertIn('ChanServ', logs.read())
        path.write_text('EOS\n')
        self.assertEqual('EOS\n', logs.read())

    def run_deploy(self, failure=None, ready=True):
        calls = []
        def run(argv, cwd=None, env=None):
            calls.append((argv, cwd))
            if failure and argv == ['make', failure]:
                raise deploy.DeployError('command failed')
            if failure == 'backup' and argv[0] == str(self.helper):
                raise deploy.DeployError('backup failed')
            return ''
        request = self.archive()
        def readiness(*args, **kwargs):
            if not ready:
                raise deploy.DeployError('not ready')
        executor = deploy.Executor(deploy.load_config(self.config_path), run=run, readiness=readiness)
        return executor, request, calls

    def test_sequence_and_current_only_on_success(self):
        executor, request, calls = self.run_deploy()
        executor.execute(*request)
        make = [(argv, Path(cwd).name) for argv, cwd in calls if argv[0] == 'make' and cwd]
        self.assertEqual([(['make', 'down'], 'legacy'), (['make', 'down'], self.sha),
                          (['make', 'clean'], self.sha), (['make', 'up'], self.sha)], make)
        self.assertEqual(self.sha, (self.root / 'deploy/current').resolve().name)
        self.assertTrue((self.root / 'deploy/releases' / self.sha / '.env.local').is_symlink())
        self.assertFalse((self.root / 'deploy/releases' / self.sha / 'var/log').is_symlink())

    def test_backup_failure_never_stops_services(self):
        executor, request, calls = self.run_deploy('backup')
        with self.assertRaises(deploy.DeployError):
            executor.execute(*request)
        self.assertFalse(any(argv in (['make', 'down'], ['make', 'clean'], ['make', 'up']) for argv, _ in calls))

    def test_hash_failure_never_runs_commands(self):
        executor, request, calls = self.run_deploy()
        request[1] = '0' * 64
        with self.assertRaises(deploy.DeployError):
            executor.execute(*request)
        self.assertEqual([], calls)

    def test_start_failure_and_readiness_failure_stop_new_without_current(self):
        for failure, ready in (('up', True), (None, False)):
            with self.subTest(failure=failure):
                # Each execution has a distinct SHA because failures retain their release.
                self.sha = ('b' if failure else 'c') * 40
                executor, request, calls = self.run_deploy(failure, ready)
                with self.assertRaises(deploy.DeployError):
                    executor.execute(*request)
                self.assertEqual(['make', 'down'], calls[-1][0])
                self.assertFalse((self.root / 'deploy/current').exists())

    def test_readiness_requires_fresh_markers_stability_and_no_restart(self):
        lines = ['Sent EOS — initial burst and sync complete.'] + [
            name + ' introduced to network.' for name in ('NickServ', 'ChanServ', 'MemoServ', 'OperServ')]
        class Clock:
            now = 0
            def time(self): return self.now
            def sleep(self, seconds): self.now += seconds
        class Logs:
            def read(self): return ''.join('[2026-09-26T00:00:00+00:00] irc.' + ('WARNING' if line == 'Server link lost.' else 'INFO') + ': ' + line + '\n' for line in lines)
        clock = Clock()
        state = json.dumps([{'Id': 'id', 'State': {'Running': True}, 'RestartCount': 0}])
        deploy.wait_ready({}, Logs(), lambda *a, **kw: state, clock.time, clock.sleep, timeout=30)
        self.assertGreaterEqual(clock.now, 10)
        for bad in ('Server link lost.',):
            lines.append(bad)
            with self.assertRaises(deploy.DeployError):
                deploy.wait_ready({}, Logs(), lambda *a, **kw: state, clock.time, clock.sleep, timeout=30)
            lines.pop()
        state = json.dumps([{'Id': 'id', 'State': {'Running': True}, 'RestartCount': 1}])
        with self.assertRaises(deploy.DeployError):
            deploy.wait_ready({}, Logs(), lambda *a, **kw: state, clock.time, clock.sleep, timeout=30)

    def test_fresh_logs_ignore_other_files_and_new_rotation_is_fresh(self):
        (self.root / 'logs' / 'error.log').write_text('historical')
        logs = deploy.FreshLogs(self.root / 'logs')
        (self.root / 'logs' / 'error.log').write_text('false readiness markers')
        (self.root / 'logs' / 'irc-2026-09-27.log').write_text('new daily rotation')
        self.assertEqual('new daily rotation', logs.read())

    def test_second_activation_does_not_stop_legacy_again(self):
        first, request, calls = self.run_deploy()
        first.execute(*request)
        self.sha = 'd' * 40
        second, request, calls = self.run_deploy()
        second.execute(*request)
        self.assertFalse(any(cwd == self.config['legacy_checkout'] for _, cwd in calls))
        metadata = list((self.root / 'deploy/backups').glob('d*/deployment.json'))[0]
        self.assertTrue(json.loads(metadata.read_text())['previous'].endswith('a' * 40))

    def test_existing_current_survives_failed_next_activation(self):
        first, request, calls = self.run_deploy()
        first.execute(*request)
        self.sha = 'e' * 40
        second, request, calls = self.run_deploy('up')
        with self.assertRaises(deploy.DeployError):
            second.execute(*request)
        self.assertEqual('a' * 40, (self.root / 'deploy/current').resolve().name)

    def test_remaining_old_container_aborts_before_up(self):
        executor, request, calls = self.run_deploy()
        original = executor.run
        def run(argv, **kwargs):
            result = original(argv, **kwargs)
            return 'old-id' if argv[:2] == ['docker', 'ps'] else result
        executor.run = run
        with self.assertRaises(deploy.DeployError):
            executor.execute(*request)
        self.assertFalse(any(argv == ['make', 'up'] for argv, _ in calls))

    def test_readiness_debug_strings_do_not_count_and_timeout(self):
        class Clock:
            now = 0
            def time(self): return self.now
            def sleep(self, seconds): self.now += seconds
        clock = Clock()
        class Logs:
            def read(self):
                return '[2026-09-26T00:00:00+00:00] irc.DEBUG: < PRIVMSG {"trailing":"Sent EOS — initial burst and sync complete. NickServ introduced to network. ChanServ introduced to network. MemoServ introduced to network. OperServ introduced to network."}\n'
        state = json.dumps([{'Id': 'id', 'State': {'Running': True}, 'RestartCount': 0}])
        with self.assertRaises(deploy.DeployError):
            deploy.wait_ready({}, Logs(), lambda *a, **kw: state, clock.time, clock.sleep, timeout=2)

    def test_readiness_replacement_container_fails(self):
        class Clock:
            now = 0
            def time(self): return self.now
            def sleep(self, seconds): self.now += seconds
        clock = Clock()
        class Logs:
            def read(self): return ''
        states = iter([json.dumps([{'Id': identifier, 'State': {'Running': True}, 'RestartCount': 0}])
                       for identifier in ('first', 'second')])
        with self.assertRaises(deploy.DeployError):
            deploy.wait_ready({}, Logs(), lambda *a, **kw: next(states), clock.time, clock.sleep, timeout=2)

    def test_archive_copy_is_bounded(self):
        request = self.archive()
        with patch.object(deploy, 'MAX_ARCHIVE', 1), self.assertRaises(deploy.DeployError):
            deploy.copy_archive(deploy.load_config(self.config_path), request[0], self.root / 'copy')

    def test_serve_failure_returns_only_generic_status_not_exception(self):
        out = io.StringIO()
        with redirect_stdout(out), patch.object(deploy, 'load_config', side_effect=RuntimeError('PASSWORD=secret')):
            self.assertEqual(1, deploy.main(['serve', str(self.config_path)]))
        self.assertNotIn('PASSWORD', out.getvalue())
        self.assertIn('__ARES_DEPLOY_STATUS__:1', out.getvalue())

    def test_attempt_failure_metadata_and_private_diagnostic_log(self):
        executor, request, calls = self.run_deploy('backup')
        with self.assertRaises(deploy.DeployError):
            executor.execute(*request)
        attempt = list((self.root / 'deploy/attempts').iterdir())[0]
        metadata = json.loads((attempt / 'status.json').read_text())
        self.assertEqual('failed', metadata['status'])
        self.assertEqual('backup', metadata['stage'])
        self.assertTrue(Path(metadata['backup']).is_dir())
        self.assertEqual(0o600, (attempt / 'status.json').stat().st_mode & 0o777)
        log = attempt / 'command.log'
        deploy.command(['/usr/bin/python3', '-c', 'print("private-build-diagnostic")'], log_path=log)
        self.assertEqual(0o600, log.stat().st_mode & 0o777)
        self.assertIn('private-build-diagnostic', log.read_text())
        with self.assertRaises(deploy.DeployError):
            deploy.command(['/usr/bin/python3', '-c', 'import sys; print("private-failure", file=sys.stderr); sys.exit(1)'], log_path=log)
        self.assertIn('private-failure', log.read_text())

    def test_oversized_request_rejected(self):
        with self.assertRaises(deploy.DeployError):
            deploy.parse_request(' ' * 4097)

    def test_first_cutover_requires_existing_legacy_checkout(self):
        (self.root / 'legacy').rmdir()
        with self.assertRaises(deploy.DeployError):
            deploy.load_config(self.config_path)

    def test_removed_legacy_allowed_after_successful_cutover(self):
        first, request, calls = self.run_deploy()
        first.execute(*request)
        (self.root / 'legacy').rmdir()
        self.sha = 'f' * 40
        second, request, calls = self.run_deploy()
        second.execute(*request)
        self.assertFalse(any(cwd == self.config['legacy_checkout'] for _, cwd in calls))

    def test_removed_legacy_allowed_after_failed_activation_with_cutover_marker(self):
        first, request, calls = self.run_deploy('up')
        with self.assertRaises(deploy.DeployError):
            first.execute(*request)
        (self.root / 'legacy').rmdir()
        self.sha = 'f' * 40
        second, request, calls = self.run_deploy()
        second.execute(*request)
        self.assertFalse(any(cwd == self.config['legacy_checkout'] for _, cwd in calls))

    def test_removed_legacy_allowed_with_valid_current_without_marker(self):
        first, request, calls = self.run_deploy()
        first.execute(*request)
        (self.root / 'deploy/.legacy-stopped').unlink()
        (self.root / 'legacy').rmdir()
        self.sha = 'f' * 40
        second, request, calls = self.run_deploy()
        second.execute(*request)
        self.assertFalse(any(cwd == self.config['legacy_checkout'] for _, cwd in calls))

    def test_dangling_current_does_not_replace_first_cutover_requirement(self):
        (self.root / 'deploy/current').symlink_to('releases/' + self.sha)
        (self.root / 'legacy').rmdir()
        with self.assertRaises(deploy.DeployError):
            deploy.load_config(self.config_path)

    def test_release_local_logs_snapshotted_after_clean(self):
        executor, request, calls = self.run_deploy()
        original = executor.run
        release = self.root / 'deploy/releases' / self.sha
        def run(argv, **kwargs):
            result = original(argv, **kwargs)
            if argv == ['make', 'clean']:
                self.assertFalse((release / 'var/log').exists())
            if argv == ['make', 'up']:
                self.assertTrue((release / 'var/log').is_dir())
                self.assertEqual(0o700, (release / 'var/log').stat().st_mode & 0o777)
                (release / 'var/log/irc.log').write_text('fresh release startup\n')
            return result
        def readiness(env, logs, *args, **kwargs):
            self.assertEqual(release / 'var/log', logs.root)
            self.assertEqual('fresh release startup\n', logs.read())
            self.assertNotIn('ARES_DATA_DIR', env)
            self.assertNotIn('ARES_LOG_DIR', env)
            self.assertEqual(str(self.env), env['ARES_ENV_FILE'])
        (self.root / 'logs/irc.log').write_text('old external EOS and introduction messages\n')
        executor.run, executor.readiness = run, readiness
        executor.execute(*request)

    def test_obsolete_external_data_log_config_fields_rejected(self):
        self.config['log_dir'] = str(self.root / 'logs')
        self.config['data_dir'] = str(self.root / 'data')
        self.config_path.write_text(json.dumps(self.config))
        with self.assertRaises(deploy.DeployError):
            deploy.load_config(self.config_path)

    def test_executor_pins_local_docker_daemon_despite_ambient_context(self):
        cfg = deploy.load_config(self.config_path)
        with patch.dict(os.environ, {'DOCKER_HOST': 'ssh://unauthorized.example',
                                     'DOCKER_CONTEXT': 'unauthorized-remote'}, clear=False):
            executor = deploy.Executor(cfg)
        self.assertEqual('unix:///var/run/docker.sock', executor.env.get('DOCKER_HOST'))
        self.assertNotIn('DOCKER_CONTEXT', executor.env)


if __name__ == '__main__':
    unittest.main()
