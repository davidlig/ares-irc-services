# Automatically deploy tested main commits

GitHub Actions deploys the exact tested SHA through a dedicated, repository-scoped
runner and a private Unix socket. Only the protected host executor controls Docker.
The user provides configuration at `~/deploy/secrets/.env.local`, outside the
repository and all releases. MariaDB is persistent and managed separately. Installing
this infrastructure or performing the first production cutover requires separate
operator authorization; committing these files does not install anything remotely.

## Quick path

1. Review and install the protected host scripts and configuration below.
2. Register a dedicated Ares runner, configure the `production` environment,
   and test request rejection without deploying.
3. Schedule the first cutover. After both CI jobs pass on a main push, the executor
   backs up, runs `make down && make clean && make up`, and checks fresh IRC readiness.

## Prerequisites and trust boundary

- Host: Linux, user systemd with linger enabled by an administrator, Python 3,
  GNU Make, Docker Engine and Compose v2. Deployment account has Docker access.
- Normally the running `ares-irc-services` container supplies its effective
  `DATABASE_URL`, captured privately through PHP/Symfony. For an initial deployment
  without any Ares container, explicitly configure the bootstrap pair below. The MariaDB client runs on the host network: the database
  hostname must resolve and be reachable there. Production Ares uses host networking
  through `docker/compose.production.yaml`; keep that override in the deployment.
- Source checkout and all protected deployment directories must be owned by the
  deployment account (or root where appropriate), without group/other write access.
  The user-provided `.env.local` is not replaced or printed; entrypoint can append keys.
  Its directory `~/deploy/secrets` must be mode 0700 and the file mode 0600, owned
  by the deployment account. Each release links `.env.local` to this external file,
  and Docker bind-mounts that exact configured file, writable for key synchronization.
- The runner has neither the Docker socket nor the user D-Bus socket. It can request
  deployments, so anyone able to execute code on that runner remains privileged
  relative to the approved production deployment path. Never run PR jobs on it.
  The bridge limits interfaces, not the privileges of trusted main code: the host
  executes its Makefile and Docker build. Checksums prove integrity, not origin
  authentication or a host sandbox. A compromised runner can endanger the host.
- Pin the official runner base image to a reviewed digest and choose a MariaDB client
  image compatible with the deployed database. Re-pull that client before downtime.

See [GitHub self-hosted runner security](https://docs.github.com/en/actions/reference/runners/self-hosted-runners)
and [MariaDB dump semantics](https://mariadb.com/docs/server/clients-and-utilities/backup-restore-and-import-clients/mariadb-dump).
`--single-transaction` assumes transactional tables; concurrent DDL can invalidate
its consistency guarantees. Routines, events and triggers are included explicitly.

## Install host commands and socket

Run only in an approved setup window, from a reviewed local checkout on the host:

```bash
set -euo pipefail
umask 077
ACTIONS_ROOT="$HOME/.local/share/ares-actions"
install -d -m 0700 "$ACTIONS_ROOT" "$ACTIONS_ROOT/runner-state" \
  "$ACTIONS_ROOT/runner-command" "$ACTIONS_ROOT/host-command" \
  "$HOME/.config/systemd/user" "$HOME/deploy/ares-irc-services"
install -m 0700 scripts/deploy/submit-deploy "$ACTIONS_ROOT/runner-command/submit-deploy"
install -m 0700 scripts/deploy/deploy.py scripts/deploy/backup.py "$ACTIONS_ROOT/host-command/"
install -m 0600 scripts/deploy/templates/config.example.json "$ACTIONS_ROOT/config.json"
install -m 0600 scripts/deploy/templates/runner-compose.yaml "$ACTIONS_ROOT/compose.yaml"
install -m 0600 scripts/deploy/templates/runner.Dockerfile "$ACTIONS_ROOT/runner.Dockerfile"
install -m 0600 scripts/deploy/templates/ares-deploy.socket \
  scripts/deploy/templates/ares-deploy@.service "$HOME/.config/systemd/user/"
```

Edit `config.json`: replace every `DEPLOY_USER`, confirm old checkout, absolute
legacy checkout and backup image. `env_file` must point to the user-provided
`~/deploy/secrets/.env.local`; supply it separately, not through a commit or release
archive. Once provided, set `chmod 700 "$HOME/deploy/secrets"` and
`chmod 600 "$HOME/deploy/secrets/.env.local"`. No setup command here reads or copies
its content. Do not put credentials into JSON.
The installed commands are intentionally outside runner state and checkouts;
upgrading them is an explicit operator action, not something main can rewrite.

Create `$ACTIONS_ROOT/.env` with mode 0600 and these private setup values:

```dotenv
DEPLOY_UID=1000
DEPLOY_GID=1000
RUNNER_URL=https://github.com/OWNER/ares-irc-services
RUNNER_BASE_IMAGE=ghcr.io/actions/actions-runner@sha256:REVIEWED_DIGEST
RUNNER_STATE=/home/DEPLOY_USER/.local/share/ares-actions/runner-state
RUNNER_COMMAND_DIR=/home/DEPLOY_USER/.local/share/ares-actions/runner-command
RUNNER_TOKEN=ONE_USE_REGISTRATION_TOKEN
```

Use the actual values from `id -u`, `id -g` and the repository runner registration
page. No registration tokens belong in the repository or shell history.
### Bootstrap when no Ares container exists

Keep the ordinary backup command for subsequent deployments. If the first host
has no Ares container, append **both** optional flags to `backup_command` in the
private JSON, before the directory argument added by the executor:

```text
--credentials-image sha256:REVIEWED_EXISTING_ARES_IMAGE_ID
--env-file /home/DEPLOY_USER/deploy/secrets/.env.local
```

The image must already exist locally and be pinned by full image ID or repository
digest, and include PHP and Ares's Symfony Dotenv dependencies. Never use a mutable
tag here. The helper verifies the container is truly absent using a successful
Docker query; Docker errors, stopped containers, or credential-reading failures
are not silently converted to bootstrap. With a running container it retains the
normal runtime credential source.

The ephemeral reader bypasses the image entrypoint and executes only PHP, with
network disabled, a read-only root filesystem and a read-only external env mount.
It starts no daemon, runs no migration, and emits credentials only into a private
captured pipe. Symfony `loadEnv` reads the external configuration, ignoring dumped
`.env.local.php` and any baked `DATABASE_URL`. Secret file permissions are 0600,
secret directory 0700, owned by the deployment account. The actual database dump
still uses the MariaDB client on the host network; backup is never skipped.
Remove the bootstrap flags once a running Ares container is established if desired.

Enable socket and runner:

```bash
systemctl --user daemon-reload
systemctl --user enable --now ares-deploy.socket
cd "$ACTIONS_ROOT"
docker compose up -d --build runner
```

After GitHub reports it Online, remove `RUNNER_TOKEN` from the private `.env`,
then `docker compose up -d --force-recreate runner`. Check the mounted socket is
accessible, and that `docker inspect` reports no Docker/D-Bus socket mounts.
Verify the safe rejection path with
`docker compose exec -T runner /runner-command/submit-deploy`: expected exit 64,
no application changes. This is not a successful deployment test.

## Configure GitHub and first cutover

Create a `production` Environment restricted to main, and Environment secret
`DEPLOY_COMMAND` with value `/runner-command/submit-deploy`. Set protection rules
that permit automatic deployment if no manual approvals are intended. Restrict
runner access to this repository and do not add its label to other jobs.
The deploy job waits for PHP quality/coverage **and** the migration matrix.

Before the first merge, review host config and make sure either the old container
is running or the explicit bootstrap image is available, the external secret file
is ready, backup permissions work,
and a maintenance window is available. The first activation stops the legacy
Compose project; subsequent releases use stable project `ares-production`.
Never launch a second Ares instance with the same IRC identity.

The `legacy_checkout` path is used solely to stop the old Compose project during
first cutover. The old checkout may be deleted only after the first successful approved cutover,
as a separate authorized operator action. Subsequent deployments allow it to be
absent. No migration or copying of legacy application data/logs is needed.
MariaDB remains in its separate service and is never deleted with the checkout.

Application data and logs are disposable: Compose uses each release's own
`var/data` and `var/log` directories. Each new release starts with its own data
directory; `make clean` clears release-local cache/log files and local Docker images.
No old data/logs are copied. Cleanup of previous data artifacts is a separate operation.
Startup readiness inspects only fresh logs from the new release after cleanup,
not old logs. The production database must remain external MariaDB, not release-local SQLite.
Protected backups and deployment diagnostics are retained outside releases and
are independent of disposable application logs.

A deployment saves configuration and a MariaDB dump before stopping services.
`make clean` acts only on release-local paths, not the external configuration or MariaDB.
Startup runs migrations and appends missing configuration keys. Readiness requires
new EOS and all four service introductions, no restart/link loss, then ten stable
seconds; existing PID health alone does not suffice. Historical logs cannot pass.
This proves initial network synchronization, not every service command end-to-end.

## Failure and manual recovery

Build, backup and startup failures fail Actions. Migration/start/readiness failures
stop the new container and preserve backups and release diagnostics; **there is no
automatic database restore or migration down**. Actions prints only sanitized status.
Inspect `~/deploy/ares-irc-services/attempts/<commit>-<attempt>/status.json`
for the failed stage and release/backup locations, and `command.log` for captured
build/migration output (both mode 0600, directory 0700). Read these protected
host diagnostics and app/error logs in the attempted release's `var/log` locally,
without copying credentials or raw private logs into issues.

Keep the previous source/release available. `make clean --rmi local` can remove its
implicit Compose image: rebuild a compatible previous release for manual recovery.
An operator may explicitly tag/copy an image before cleanup, but image retention is
not guaranteed by deployment. Before restarting the previous source, inspect
migration changes and prove compatibility with the current schema. If compatible,
select that release explicitly and start with the same production Compose project,
production override and the external secret bind mount. Never run both old and new.
If incompatible, keep Ares stopped, fix forward or perform an approved database
restore. A restore can discard writes and must account for IRC/UDB state.

For an approved MariaDB restore, create a private `client.cnf` (mode 0600), use a
compatible client image and the preserved `database.sql` as stdin:

```bash
docker run --rm -i --network host --user "$(id -u):$(id -g)" \
  --mount "type=bind,src=/ABSOLUTE/PRIVATE/client.cnf,dst=/client.cnf,readonly" \
  mariadb:11.4 mariadb --defaults-extra-file=/client.cnf < /ABSOLUTE/BACKUP/database.sql
```

Reconcile restored DB with IRC/UDB before restarting. Do not paste passwords into
argv or environment, and remove the private restore credential file afterwards.
Retain backups/releases until recovery has been tested; cleanup is a separate,
explicit operation, never part of a failed activation.

## Local validation

```bash
python3 -m unittest discover -s scripts/deploy/tests -v
python3 -m py_compile scripts/deploy/deploy.py scripts/deploy/backup.py scripts/deploy/submit-deploy
```

These checks use fakes and fixtures. They do not register a runner, access production,
restart services, or prove live database backup/recovery.
