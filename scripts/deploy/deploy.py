#!/usr/bin/python3
"""Protected host executor. Never install this entry point from an incoming release.

The runner can supply an archive, not a shell command or deployment configuration.
All subprocess output stays private; only a bounded status crosses the socket.
"""
import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import signal
import stat
import subprocess
import sys
import tarfile
import tempfile
import time

MAX_ARCHIVE = 128 * 1024 * 1024
MAX_EXPANDED = 512 * 1024 * 1024
MAX_FILES = 30000
PATH = '/usr/local/bin:/usr/bin:/bin'
REQUIRED = {'runner_work_root', 'runner_container_work_root', 'deploy_root',
            'env_file', 'backup_command', 'legacy_checkout'}


class DeployError(Exception):
    """An intentionally non-secret deployment failure."""


def private_file(path):
    path = Path(path)
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid not in (0, os.geteuid()) or info.st_mode & 0o022:
        raise DeployError('unsafe protected file')
    # Every ancestor must also resist untrusted replacement of this file.
    for parent in path.parents:
        info = parent.stat()
        if info.st_uid not in (0, os.geteuid()) or info.st_mode & 0o022:
            # Root-owned sticky /tmp is allowed for local fixture/config staging;
            # its private descendant prevents replacement by other users.
            if not (info.st_uid == 0 and info.st_mode & stat.S_ISVTX):
                raise DeployError('unsafe protected directory')



def cutover_done(root):
    """The old checkout is required only until its protected cutover is recorded."""
    root = Path(root)
    marker = root / '.legacy-stopped'
    if marker.is_file() and not marker.is_symlink():
        return True
    current = root / 'current'
    if not current.is_symlink():
        return False
    release = current.resolve()
    return (release.is_dir() and release.parent == (root / 'releases').resolve()
            and re.fullmatch('[0-9a-f]{40}', release.name) is not None)

def load_config(path):
    path = Path(path)
    private_file(path)
    if path.stat().st_mode & 0o077:
        raise DeployError('configuration must be private')
    cfg = json.loads(path.read_text())
    if not isinstance(cfg, dict) or not REQUIRED <= cfg.keys() or cfg.keys() - REQUIRED - {'readiness_timeout'}:
        raise DeployError('invalid configuration fields')
    for key in REQUIRED - {'backup_command'}:
        value = cfg[key]
        if not isinstance(value, str) or not Path(value).is_absolute() or '..' in Path(value).parts:
            raise DeployError('configuration paths must be absolute')
    for key in ('runner_work_root', 'deploy_root'):
        if not Path(cfg[key]).is_dir() or Path(cfg[key]).is_symlink():
            raise DeployError('configuration directory missing or symlinked')
    root = Path(cfg['deploy_root'])
    if root.stat().st_uid != os.geteuid() or root.stat().st_mode & 0o077:
        raise DeployError('deploy root must be owned and private')
    if not cutover_done(root):
        legacy = Path(cfg['legacy_checkout'])
        if not legacy.is_dir() or legacy.is_symlink():
            raise DeployError('first cutover requires legacy checkout')
    if not Path(cfg['env_file']).is_file() or Path(cfg['env_file']).is_symlink():
        raise DeployError('environment file unavailable')
    command = cfg['backup_command']
    if not isinstance(command, list) or not command or any(not isinstance(arg, str) or '\0' in arg for arg in command):
        raise DeployError('invalid backup command')
    if not Path(command[0]).is_absolute():
        raise DeployError('backup executable must be absolute')
    for arg in command:
        if Path(arg).is_absolute():
            # Distribution Python can be a trusted symlink, resolve before checking.
            private_file(Path(arg).resolve())
    timeout = cfg.get('readiness_timeout', 120)
    if isinstance(timeout, bool) or not isinstance(timeout, (int, float)) or not 1 <= timeout <= 600:
        raise DeployError('invalid readiness timeout')
    cfg['readiness_timeout'] = timeout
    return cfg


def parse_request(line):
    if len(line.encode()) > 4096:
        raise DeployError('request too large')
    request = json.loads(line)
    if not isinstance(request, list) or len(request) != 3 or any(not isinstance(item, str) for item in request):
        raise DeployError('invalid request')
    if not re.fullmatch('[0-9a-f]{64}', request[1]) or not re.fullmatch('[0-9a-f]{40}', request[2]):
        raise DeployError('invalid identifiers')
    return request


def archive_path(cfg, incoming):
    source = PurePosixPath(incoming)
    if not source.is_absolute() or '..' in source.parts:
        raise DeployError('invalid archive path')
    try:
        relative = source.relative_to(cfg['runner_container_work_root'])
    except ValueError as error:
        raise DeployError('archive outside runner work mount') from error
    path = Path(cfg['runner_work_root']) / str(relative)
    if not relative.parts:
        raise DeployError('archive missing')
    for parent in (path, *path.parents):
        if parent == Path(cfg['runner_work_root']):
            break
        if parent.is_symlink():
            raise DeployError('archive symlink rejected')
    if not path.is_file() or path.stat().st_size > MAX_ARCHIVE:
        raise DeployError('archive missing or oversized')
    return path



def copy_archive(cfg, incoming, destination):
    """openat prevents runner-side parent swaps from redirecting the file read."""
    archive_path(cfg, incoming)
    relative = PurePosixPath(incoming).relative_to(cfg['runner_container_work_root'])
    descriptor = os.open(cfg['runner_work_root'], os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        for part in relative.parts[:-1]:
            next_descriptor = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=descriptor)
            os.close(descriptor)
            descriptor = next_descriptor
        file_descriptor = os.open(relative.parts[-1], os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK, dir_fd=descriptor)
        with os.fdopen(file_descriptor, 'rb') as source, Path(destination).open('xb') as target:
            info = os.fstat(source.fileno())
            if not stat.S_ISREG(info.st_mode) or info.st_size > MAX_ARCHIVE:
                raise DeployError('archive must be a bounded regular file')
            remaining = MAX_ARCHIVE
            while True:
                chunk = source.read(min(1024 * 1024, remaining + 1))
                if not chunk:
                    break
                remaining -= len(chunk)
                if remaining < 0:
                    raise DeployError('archive grew beyond limit')
                target.write(chunk)
    except OSError as error:
        raise DeployError('archive changed during copy') from error
    finally:
        os.close(descriptor)

def extract_archive(archive_path, target):
    """Validate the whole archive before writing; no link or special-file semantics."""
    with tarfile.open(archive_path, 'r:*') as archive:
        members = []
        names = set()
        size = 0
        for member in archive:
            parts = PurePosixPath(member.name)
            if (parts.is_absolute() or '..' in parts.parts or not parts.parts
                    or not (member.isfile() or member.isdir())
                    or parts.parts[0] in ('.git', '.env.local', 'var')
                    or str(parts) in names or member.size < 0):
                raise DeployError('unsafe archive member')
            names.add(str(parts))
            size += member.size
            members.append(member)
            if size > MAX_EXPANDED or len(members) > MAX_FILES:
                raise DeployError('archive expansion limit')
        target = Path(target)
        target.mkdir(mode=0o700, parents=True, exist_ok=True)
        for member in members:
            path = target / member.name
            if member.isdir():
                path.mkdir(mode=0o700, parents=True, exist_ok=True)
            else:
                path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                with archive.extractfile(member) as source, path.open('xb') as destination:
                    shutil.copyfileobj(source, destination)
                path.chmod(0o700 if member.mode & 0o111 else 0o600)


@contextlib.contextmanager
def deployment_lock(root):
    descriptor = os.open(Path(root) / '.deploy.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        try:
            fcntl.flock(descriptor, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as error:
            raise DeployError('deployment already running') from error
        yield
    finally:
        os.close(descriptor)


class FreshLogs:
    """Offsets keyed by inode exclude historical and renamed logs, including rotations."""
    def __init__(self, root):
        self.root = Path(root)
        self.offsets = {}
        self.prefixes = {}
        self._read(snapshot=True)

    def _read(self, snapshot=False):
        chunks = []
        for path in self.root.iterdir():
            if (not re.fullmatch(r'(irc|ares)(-\d{4}-\d{2}-\d{2})?\.log', path.name)
                    or path.is_symlink() or not path.is_file()):
                continue
            info = path.stat()
            key = (info.st_dev, info.st_ino)
            with path.open('rb') as stream:
                prefix = stream.read(64)
                offset = self.offsets.get(key, 0)
                old_prefix = self.prefixes.get(key, b'')
                if info.st_size < offset or not prefix.startswith(old_prefix):
                    offset = 0
                if snapshot:
                    offset = info.st_size
                stream.seek(offset)
                # Cap per-poll memory. Never expose the read bytes outside host executor.
                chunks.append(stream.read(4 * 1024 * 1024).decode('utf-8', 'replace'))
                self.offsets[key] = stream.tell()
                self.prefixes[key] = prefix
        return ''.join(chunks)

    def read(self):
        return self._read()


def command(argv, cwd=None, env=None, log_path=None):
    """Stream builds to a private host log; bound structured Docker responses."""
    capture = argv[:2] in (['docker', 'inspect'], ['docker', 'ps'])
    with contextlib.ExitStack() as stack:
        diagnostics = stack.enter_context(Path(log_path).open('ab')) if log_path else stack.enter_context(tempfile.TemporaryFile())
        if log_path:
            Path(log_path).chmod(0o600)
        output = stack.enter_context(tempfile.TemporaryFile()) if capture else diagnostics
        try:
            subprocess.run(argv, cwd=cwd, env=env, stdout=output, stderr=diagnostics, timeout=900, check=True)
        except (subprocess.SubprocessError, OSError) as error:
            raise DeployError('protected command failed') from error
        if not capture:
            return ''
        output.seek(0)
        captured = output.read(1024 * 1024 + 1)
        if len(captured) > 1024 * 1024:
            raise DeployError('oversized container response')
        return captured.decode('utf-8', 'replace')


def wait_ready(env, logs, run=command, now=time.monotonic, sleep=time.sleep, timeout=120):
    deadline = now() + timeout
    seen = set()
    identity = None
    stable_since = None
    markers = {'eos': 'Sent EOS — initial burst and sync complete.', **{
        name: name + ' introduced to network.' for name in ('NickServ', 'ChanServ', 'MemoServ', 'OperServ')}}
    carry = ''
    while now() <= deadline:
        fresh = carry + logs.read()
        records = fresh.split('\n')
        carry = records.pop()[-4096:]
        # Only anchored Monolog messages count, never peer-controlled DEBUG context.
        for record in records:
            message = re.match(r'^\[[^\]\r\n]+\] irc\.(INFO|WARNING): (.*)$', record)
            if message is None:
                continue
            level, text = message.groups()
            if level == 'WARNING' and text.startswith('Server link lost.'):
                raise DeployError('new server link lost')
            if level == 'INFO':
                seen.update(key for key, marker in markers.items() if text.startswith(marker))
        try:
            state = json.loads(run(['docker', 'inspect', 'ares-irc-services'], env=env))[0]
            running = state['State']['Running']
            restarts = state['RestartCount']
            current = state['Id']
        except (ValueError, IndexError, KeyError, TypeError, DeployError):
            if identity is not None:
                raise DeployError('new container disappeared')
            running, restarts, current = False, 0, None
        if restarts != 0 or (identity is not None and (not running or current != identity)):
            raise DeployError('new container restarted or stopped')
        if running:
            identity = current
        if running and len(seen) == len(markers):
            if stable_since is None:
                stable_since = now()
            if now() - stable_since >= 10:
                return
        sleep(1)
    raise DeployError('readiness deadline exceeded')


class Executor:
    def __init__(self, cfg, run=command, readiness=wait_ready):
        self.cfg, self.original_run, self.readiness = cfg, run, readiness
        self.run = self._run
        self.env = {'PATH': PATH, 'HOME': str(Path.home()), 'USER': str(os.geteuid()),
                    'APP_ENV': 'prod', 'COMPOSE_PROJECT_NAME': 'ares-production',
                    'DOCKER_HOST': 'unix:///var/run/docker.sock',
                    'ARES_ENV_FILE': cfg['env_file']}

    def _run(self, argv, cwd=None, env=None):
        if self.original_run is command:
            return command(argv, cwd=cwd, env=env, log_path=self.attempt / 'command.log')
        return self.original_run(argv, cwd=cwd, env=env)

    def stage(self, name, **values):
        self.metadata.update(values, stage=name)
        metadata = self.attempt / 'status.json'
        metadata.write_text(json.dumps(self.metadata))
        metadata.chmod(0o600)

    def execute(self, archive, digest, commit):
        parse_request(json.dumps([archive, digest, commit]))
        root = Path(self.cfg['deploy_root'])
        with deployment_lock(root):
            self.attempt = root / 'attempts' / (commit + '-' + str(time.time_ns()))
            self.attempt.mkdir(mode=0o700, parents=True)
            (self.attempt / 'command.log').touch(mode=0o600)
            self.metadata = {'commit': commit, 'status': 'running'}
            self.stage('archive')
            try:
                self._execute(archive, digest, commit)
                self.stage('complete', status='ready')
            except Exception:
                self.stage(self.metadata['stage'], status='failed')
                raise

    def _execute(self, archive, digest, commit):
        root = Path(self.cfg['deploy_root'])
        with tempfile.TemporaryDirectory(prefix='.incoming-', dir=root) as temporary:
            # Copy runner-controlled bytes into a private file first, hash and extract only that copy.
            copied = Path(temporary) / 'archive.tar'
            copy_archive(self.cfg, archive, copied)
            if copied.stat().st_size > MAX_ARCHIVE or hashlib.sha256(copied.read_bytes()).hexdigest() != digest:
                raise DeployError('archive checksum mismatch')
            staging = Path(temporary) / 'release'
            extract_archive(copied, staging)
            for required in ('Makefile', 'docker/compose.yaml', 'docker/compose.production.yaml'):
                if not (staging / required).is_file():
                    raise DeployError('release runtime files missing')
            releases = root / 'releases'
            releases.mkdir(mode=0o700, exist_ok=True)
            release = releases / commit
            if release.exists():
                raise DeployError('release already exists; operator recovery required')
            staging.rename(release)
            (release / '.env.local').symlink_to(self.cfg['env_file'])
            self.env['ARES_COMPOSE_OVERRIDE'] = str(release / 'docker/compose.production.yaml')
            self.stage('preflight', release=str(release))
            # Validate tooling and rendered mounts before any backup or service interruption.
            self.run(['make', '--version'], env=self.env)
            self.run(['docker', 'compose', 'version'], env=self.env)
            self.run(['docker', 'compose', '-f', 'docker/compose.yaml', '-f', 'docker/compose.production.yaml', 'config', '--quiet'], cwd=release, env=self.env)
            backup = root / 'backups' / (commit + '-' + str(time.time_ns()))
            backup.mkdir(mode=0o700, parents=True)
            shutil.copyfile(self.cfg['env_file'], backup / '.env.local')
            (backup / '.env.local').chmod(0o600)
            current = root / 'current'
            previous = str(current.resolve()) if current.is_symlink() else None
            (backup / 'deployment.json').write_text(json.dumps({'previous': previous, 'commit': commit}))
            self.stage('backup', backup=str(backup), previous=previous)
            self.run(self.cfg['backup_command'] + [str(backup)], env=self.env)
            # Once cut over, do not revisit the legacy project even if activation later fails.
            cutover = root / '.legacy-stopped'
            if not cutover_done(root):
                self.stage('legacy-stop')
                self.run(['make', 'down'], cwd=self.cfg['legacy_checkout'], env={
                    key: value for key, value in self.env.items() if key not in ('COMPOSE_PROJECT_NAME', 'ARES_COMPOSE_OVERRIDE')})
                cutover.write_text(commit + '\n')
            stopped = False
            try:
                self.stage('down')
                self.run(['make', 'down'], cwd=release, env=self.env)
                stopped = True
                if self.run(['docker', 'ps', '--filter', 'name=^/ares-irc-services$', '--format', '{{.ID}}'], env=self.env).strip():
                    raise DeployError('previous container still running')
                # Separate argv calls preserve exactly make down && make clean && make up,
                # while snapshotting release-local logs after their cleanup.
                self.stage('clean')
                self.run(['make', 'clean'], cwd=release, env=self.env)
                log_dir = release / 'var/log'
                log_dir.mkdir(mode=0o700, parents=True, exist_ok=True)
                logs = FreshLogs(log_dir)
                self.stage('up')
                self.run(['make', 'up'], cwd=release, env=self.env)
                self.stage('readiness')
                self.readiness(self.env, logs, self.run, timeout=self.cfg['readiness_timeout'])
                self.stage('activate')
                temporary_link = root / '.current-new'
                temporary_link.symlink_to(Path('releases') / commit)
                temporary_link.replace(current)
            except Exception:
                if stopped:
                    try:
                        self.run(['make', 'down'], cwd=release, env=self.env)
                    except DeployError:
                        pass
                raise


def main(argv=None):
    argv = sys.argv[1:] if argv is None else argv
    status = 1
    os.umask(0o077)
    try:
        if len(argv) not in (2, 5) or argv[0] not in ('serve', 'execute'):
            raise DeployError('invalid invocation')
        cfg = load_config(argv[1])
        if argv[0] == 'serve' and len(argv) == 2:
            def timeout_handler(*_):
                raise DeployError('request timeout')
            signal.signal(signal.SIGALRM, timeout_handler)
            signal.alarm(30)
            line = sys.stdin.buffer.readline(4097)
            signal.alarm(0)
            if not line.endswith(b'\n'):
                raise DeployError('unterminated request')
            request = parse_request(line.decode('utf-8'))
        elif argv[0] == 'execute' and len(argv) == 5:
            request = parse_request(json.dumps(argv[2:]))
        else:
            raise DeployError('invalid invocation')
        Executor(cfg).execute(*request)
        status = 0
    except Exception:
        # Exception messages, stdout and logs may contain secrets: never echo them.
        print('Deployment failed; inspect protected host state and recover manually.', flush=True)
    finally:
        signal.alarm(0)
    print('__ARES_DEPLOY_STATUS__:' + str(status), flush=True)
    return status


if __name__ == '__main__':
    sys.exit(main())
