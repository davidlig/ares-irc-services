#!/usr/bin/env python3
"""Back up the running application's MariaDB without credentials in process argv."""
import argparse
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile

# Captured privately, never forwarded to logs or Actions. APP_ENV follows production Compose.
PHP_CREDENTIALS = r'''require "/app/vendor/autoload.php";
(new Symfony\Component\Dotenv\Dotenv())->bootEnv("/app/.env");
$u = parse_url($_SERVER["DATABASE_URL"] ?? $_ENV["DATABASE_URL"] ?? getenv("DATABASE_URL"));
if (!is_array($u) || !in_array($u["scheme"] ?? "", ["mysql", "mariadb"], true)) { exit(1); }
echo json_encode(["host"=>$u["host"] ?? "", "port"=>$u["port"] ?? 3306,
"user"=>rawurldecode($u["user"] ?? ""), "password"=>rawurldecode($u["pass"] ?? ""),
"database"=>rawurldecode(ltrim($u["path"] ?? "", "/"))], JSON_THROW_ON_ERROR);'''


def bootstrap_parameters(image, env_file):
    if not isinstance(image, str) or not re.fullmatch(r'(?:sha256:|[^\s]+@sha256:)[0-9a-f]{64}', image):
        raise ValueError('bootstrap image must be pinned')
    if env_file is None:
        raise ValueError('bootstrap requires external environment file')
    path = Path(env_file)
    if not path.is_absolute() or path.is_symlink():
        raise ValueError('bootstrap environment must be an absolute regular file')
    info = path.stat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077:
        raise ValueError('bootstrap environment must be private and owned')
    parent = path.parent
    if parent.is_symlink() or parent.stat().st_uid != os.getuid() or parent.stat().st_mode & 0o077:
        raise ValueError('bootstrap environment directory must be private and owned')
    for ancestor in path.parents:
        info = ancestor.lstat()
        if stat.S_ISLNK(info.st_mode) or info.st_uid not in (0, os.getuid()):
            raise ValueError('unsafe environment ancestor')
        if info.st_mode & 0o022 and not (info.st_uid == 0 and info.st_mode & stat.S_ISVTX):
            raise ValueError('unsafe environment ancestor')
    return path


def credentials(container, credentials_image=None, env_file=None):
    command = ['docker', 'exec', container, 'php', '-r', PHP_CREDENTIALS]
    if credentials_image is not None or env_file is not None:
        path = bootstrap_parameters(credentials_image, env_file)
        # A failed Docker query or existing-but-stopped container is not absence.
        result = subprocess.run(['docker', 'container', 'ls', '--all', '--filter',
                                 f'name=^/{container}$', '--format', '{{.Names}} {{.State}}'],
                                stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=True, timeout=30)
        state = result.stdout.decode().strip()
        if state and state != f'{container} running':
            raise ValueError('existing application container is not running')
        if not state:
            php = PHP_CREDENTIALS.replace(
                '(new Symfony\\Component\\Dotenv\\Dotenv())->bootEnv("/app/.env");',
                'unset($_SERVER["DATABASE_URL"], $_ENV["DATABASE_URL"]); putenv("DATABASE_URL");'
                '(new Symfony\\Component\\Dotenv\\Dotenv())->loadEnv("/app/.env");')
            command = ['docker', 'run', '--rm', '--pull', 'never', '--read-only', '--network', 'none',
                       '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
                       '--user', f'{os.getuid()}:{os.getgid()}', '--env', 'APP_ENV=prod',
                       '--mount', f'type=bind,src={path},dst=/app/.env.local,readonly',
                       '--entrypoint', 'php', credentials_image, '-d', 'opcache.enable_cli=0', '-r', php]
    result = subprocess.run(command, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=True, timeout=30)
    if len(result.stdout) > 65536:
        raise ValueError('invalid database configuration')
    return json.loads(result.stdout)


def client_config(values):
    if not isinstance(values, dict):
        raise ValueError('invalid database configuration')
    for name in ('host', 'user', 'password'):
        if not isinstance(values.get(name), str):
            raise ValueError('invalid database configuration')
    if not values['host'] or not values['user']:
        raise ValueError('missing database configuration')
    port = values.get('port')
    if isinstance(port, bool) or not isinstance(port, int) or not 1 <= port <= 65535:
        raise ValueError('invalid database port')
    lines = ['[client]', 'protocol=tcp']
    for name in ('host', 'port', 'user', 'password'):
        value = str(values[name])
        if any(char in value for char in ('\n', '\r', '\x00')):
            raise ValueError('invalid database configuration')
        value = value.replace('\\', '\\\\').replace('"', '\\"')
        lines.append(f'{name}="{value}"')
    return '\n'.join(lines) + '\n'


def backup(container, image, directory, credentials_image=None, env_file=None):
    directory = directory.resolve(strict=True)
    if not directory.is_dir() or directory.stat().st_uid != os.getuid() or directory.stat().st_mode & 0o077:
        raise ValueError('backup directory must be private and owned by current user')
    values = credentials(container, credentials_image, env_file)
    config_content = client_config(values)
    database = values.get('database')
    if not isinstance(database, str) or not re.fullmatch(r'[A-Za-z0-9_]+', database):
        raise ValueError('unsupported database name')
    target = directory / 'database.sql'
    created = False
    try:
        with tempfile.TemporaryDirectory(prefix='.credentials-', dir=directory) as temporary:
            config = Path(temporary) / 'client.cnf'
            fd = os.open(config, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            with os.fdopen(fd, 'w') as handle:
                handle.write(config_content)
            fd = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
            created = True
            with os.fdopen(fd, 'wb') as dump:
                subprocess.run(['docker', 'run', '--rm', '--network', 'host', '--user', f'{os.getuid()}:{os.getgid()}',
                                '--mount', f'type=bind,src={config},dst=/backup.cnf,readonly', image,
                                'mariadb-dump', '--defaults-extra-file=/backup.cnf', '--single-transaction',
                                '--quick', '--routines', '--events', '--triggers', '--databases', database],
                               stdout=dump, stderr=subprocess.DEVNULL, check=True, timeout=600)
            if target.stat().st_size == 0:
                raise ValueError('empty database backup')
    except Exception:
        if created:
            target.unlink(missing_ok=True)
        raise


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--container', default='ares-irc-services')
    parser.add_argument('--image', default='mariadb:11.4')
    parser.add_argument('--credentials-image', help='Pinned existing Ares image for absent-container bootstrap')
    parser.add_argument('--env-file', type=Path, help='Private external .env.local for bootstrap')
    parser.add_argument('directory', type=Path)
    args = parser.parse_args()
    try:
        backup(args.container, args.image, args.directory, args.credentials_image, args.env_file)
        return 0
    except Exception:
        print('Database backup failed; service was not changed.', file=sys.stderr)
        return 1


if __name__ == '__main__':
    sys.exit(main())
