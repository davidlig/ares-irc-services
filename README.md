# Ares IRC Services

[![PHP](https://img.shields.io/badge/PHP-8.5%2B-777BB4?style=flat&logo=php)](https://php.net)
[![Symfony](https://img.shields.io/badge/Symfony-7.4-black?style=flat&logo=symfony)](https://symfony.com)
[![License](https://img.shields.io/badge/license-AGPL--3.0--or--later-blue.svg)](LICENSE)
[![Coverage](https://codecov.io/gh/davidlig/ares-irc-services/branch/main/graph/badge.svg)](https://codecov.io/gh/davidlig/ares-irc-services)
[![Release](https://img.shields.io/github/v/release/davidlig/ares-irc-services?include_prereleases&label=release)](https://github.com/davidlig/ares-irc-services/releases)
[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/davidlig/ares-irc-services)

> **Under active development.** Functionality and APIs may change.

A modular, protocol-agnostic IRC services daemon built for **PHP >= 8.5** and **Symfony 7.4**, following **Clean Architecture / DDD** principles. It ships with NickServ, ChanServ, MemoServ, and OperServ — all protocol-independent via a strategy pattern backed by a tagged-service registry.

---

## Table of Contents

- [Supported IRCds](#supported-ircds)
- [Test Server](#test-server)
- [Quick Start](#quick-start)
- [Services Overview](#services-overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration Reference](#configuration-reference)
- [IRCD Configuration](#ircd-configuration)
- [Service commands](#service-commands)
- [Registration and recovery](docs/registration-and-recovery.md)
- [Running the Services](#running-the-services)
- [Internationalization (i18n)](#internationalization-i18n)
- [Docker Deployment](#docker-deployment)
- [Testing & Development](#testing--development)
- [Troubleshooting](#troubleshooting)
- [License](#license)

---

## Supported IRCds

| IRCd | Driver | S2S protocol | SID format | Auth | Plain/TLS ports (typical) |
|------|--------|-------------|------------|------|---------------------------|
| [UnrealIRCd](https://www.unrealircd.org/) 6.2.x | `unreal` | 6.2.x | 3-digit numeric (`002`) | Plaintext link password | 6900,7000 / 6901,7001 |
| [UnrealIRCd-UDB](https://github.com/davidlig/unrealircd-udb) 4.0.0 / UnrealIRCd 6.2.x | `unrealudb` | UDB 4 at pinned revision `0032fb5` | 3-digit numeric (`002`) | Plaintext link password | 6900,7000 / 6901,7001 |
| [InspIRCd](https://www.inspircd.org/) | `inspircd` | SpanTree v4 (protocol 1206) | 3-char alphanum (`0A0`) | Plaintext (no CHALLENGE) | 7000 / 7001 |

The port you configure in `.env.local` (`IRC_IRCD_PORT`) must match whatever you set in your IRCd's `link` / `<link>` block.

With the `unrealudb` driver, services are the **sole UDB authority**: all six blocks (`N/C/I/S/L/K`) live in the authoritative services store and are served to the IRCd. The adapter uses SHA-256 manifests, snapshot watermarks, epoch-scoped sequenced mutations, `MANIFEST` anti-entropy, and `EXP` compare-and-delete. `N/C/K` blocks are rebuilt from the services SQL projections while `I/S/L` remain adapter-owned data; administrative `RAW DB *` rejects `DRP`, and stale `EXP` requests are no-ops. The UDB `C::<channel>::access::<nick>` container is the module's join-authorization allowlist (key presence plus `+r`), not a rank store. Ares keeps channel access levels in SQL and enforces them through ChanServ policies. Existing legacy `access` records are not purged automatically; plan any cleanup explicitly.

UDB startup performs an authority preflight. Fresh bootstrap is supported, and
`IRC_UDB_BOOTSTRAP_FROM_PEER` can enable optional first-store import from the linked peer.
Session exclusivity uses the application-owned local lock at `var/lock/udb.lock`; this is a
single-host lock, not distributed coordination.

The driver supports a closed UnrealIRCd mode profile: the core and bundled
`modules.default.conf` handlers encoded by `UdbSchema`. Custom mode modules,
or blacklisting one of those handlers, are unsupported because the UDB module
validates modes against the live UnrealIRCd registry while Ares validates them
statically. Extending the profile requires an explicit discovery/configuration
design rather than adding mode letters ad hoc.

---

## Test Server

Try the bots on our public test network:

- **Plain:** [irc://irc.davidlig.net:6667](irc://irc.davidlig.net:6667)
- **SSL:** [ircs://irc.davidlig.net:6697](ircs://irc.davidlig.net:6697)

> Availability is best-effort; the server may be restarted for development.

---

## Quick Start

```bash
git clone https://github.com/davidlig/ares-irc-services.git
cd ares-irc-services
composer install
cp .env .env.local
# edit .env.local — set at minimum: IRC_LINK_PASSWORD, IRC_PROTOCOL, IRC_SERVER_SID
php bin/console irc:connect
```

With just 5 steps and a running IRCd you should see `NickServ`, `ChanServ`, `MemoServ`, and `OperServ` join the network.

---

## Services Overview

### NickServ

Manages nickname registration, identity, account recovery, and user preferences. REGMODE selects
the network-wide TOKEN or EMAIL registration flow. Registered nicks are protected;
unauthenticated users are renamed to the configurable guest prefix (default `Ares-`). See the
[registration and recovery guide](docs/registration-and-recovery.md) for token lifecycle and
storage details.

Additional features include brute-force lockout, custom vhosts, suspension and inactivity
expiry, action history, and IRCop search and restoration tools.

### ChanServ

Manages channel registration, hierarchical access, policy, channel state, and channel recovery.
IRCop permissions and each command's syntax are available through ChanServ HELP.

### MemoServ

Provides offline messaging for users and channels, delivery on the next identify or join,
ignore lists, and configurable storage limits. See MemoServ HELP for commands and syntax.

### OperServ

Provides IRC operator and role management, network operations, and configurable Root access.
Role permissions auto-register from code; Unreal protocols also support role operclass
configuration. See OperServ HELP for available commands and permissions.

---

## Requirements

| Dependency | Minimum version |
|------------|----------------|
| PHP | >= 8.5 |
| Symfony | 7.4 |
| Composer | 2.x |

**Required PHP extensions:**

Composer directly requires `ext-ctype`, `ext-iconv`, `ext-mbstring`, `ext-pdo_mysql`, and
`ext-pdo_sqlite` (with PDO). Install both declared PDO drivers even if you use only one database.
For PostgreSQL, also install `ext-pdo_pgsql`. Transitive dependencies and development tools may
require additional extensions; use the platform check below for the installed dependency set.
`ext-sockets` is not required by the Amp socket transport. Docker includes `pcntl` for graceful
daemon signal handling.

Verify your setup with:

```bash
php -m                          # list loaded extensions
composer check-platform-reqs    # validate against composer.json
```

---

## Installation

### 1. Clone

```bash
git clone https://github.com/davidlig/ares-irc-services.git
cd ares-irc-services
```

### 2. Install PHP dependencies

```bash
composer install --no-dev --optimize-autoloader   # production
composer install                                  # development
```

### 3. Configure the environment

```bash
cp .env .env.local
```

Edit `.env.local` and replace every `changeme` value. See [Configuration Reference](#configuration-reference) for every available variable.

Generate a secure `APP_SECRET`:

```bash
php -r "echo bin2hex(random_bytes(16)) . PHP_EOL;"
```

> **Never commit `.env.local`** — it is listed in `.gitignore`.

---

## Configuration Reference

All variables live in `.env.local`. Required variables are marked with ⚠.

### IRC Link (⚠ at least one must be set)

| Variable | Default | Description |
|----------|---------|-------------|
| `IRC_SERVER_NAME` | `ares-services.davidlig.net` | FQDN Ares presents to the IRCd |
| `IRC_IRCD_HOST` | `127.0.0.1` | IRCd hostname or IP |
| `IRC_IRCD_PORT` | `7000` | IRCd server-link listener port |
| `IRC_LINK_PASSWORD` | `pass` | ⚠ Shared link password |
| `IRC_PROTOCOL` | `unreal` | ⚠ `unreal`, `unrealudb`, or `inspircd` |
| `IRC_USE_TLS` | `false` | `true` to wrap the link in TLS |
| `IRC_SERVER_SID` | `002` | ⚠ 3-digit numeric (Unreal) or 3-char alphanum (InspIRCd) |

> **UIDs are auto-generated** from the SID prefix by `ServiceUidGenerator`. You do **not** need to set `NICKSERV_UID`, `CHANSERV_UID`, `MEMOSERV_UID`, or `OPERSERV_UID` anymore.

### Global

| Variable | Default | Description |
|----------|---------|-------------|
| `IRC_SERVICES_VHOST` | `services.davidlig.net` | Virtual host for service pseudo-clients |
| `SERVICES_DEFAULT_LANGUAGE` | `en` | Default language for service replies (`ca`, `de`, `el`, `en`, `es`, `eu`, `fr`, `gl`, `it`, `nl`, `pl`, `pt`, `ro`, `tr`) |
| `SERVICES_DEFAULT_TIMEZONE` | `UTC` | Default timezone for date display |

### Antiflood

| Variable | Default | Description |
|----------|---------|-------------|
| `SERVICES_ANTIFLOOD_MAX_MESSAGES` | `5` | Max commands per user per window (`0` = disabled) |
| `SERVICES_ANTIFLOOD_WINDOW_SECONDS` | `10` | Counting window in seconds |
| `SERVICES_ANTIFLOOD_COOLDOWN_SECONDS` | `60` | Lockout after exceeding the limit |

IRCops are exempt from antiflood.

### Database

| Variable | Default | Description |
|----------|---------|-------------|
| `DATABASE_URL` | `sqlite:///%kernel.project_dir%/var/data/ares.db` | Doctrine DBAL connection URL |

To switch database drivers (MySQL, PostgreSQL), use the format:

```
# MySQL / MariaDB
DATABASE_URL="mysql://user:pass@host:3306/db?serverVersion=8.0.32&charset=utf8mb4"

# PostgreSQL
DATABASE_URL="postgresql://user:pass@host:5432/db?serverVersion=16&charset=utf8"
```

### NickServ

| Variable | Default | Description |
|----------|---------|-------------|
| `NICKSERV_NICK` | `NickServ` | Bot nickname |
| `NICKSERV_IDENT` | `NickServ` | Bot ident |
| `NICKSERV_REALNAME` | `Nickname Registration Services` | Bot realname |
| `NICKSERV_GUEST_PREFIX` | `Ares-` | Guest nick prefix for unidentified users |
| `NICKSERV_VHOST_SUFFIX` | `virtual` | Suffix appended to custom vhosts |
| `NICKSERV_RESEND_MIN_INTERVAL` | `600` | Seconds between RESEND attempts |
| `NICKSERV_RECOVER_TOKEN_TTL` | `3600` | Provisional recovery-email token TTL; confirmed recoveryToken does not expire |
| `NICKSERV_RECOVER_MIN_INTERVAL` | `600` | Minimum seconds between recovery-email requests per nick |
| `NICKSERV_REGISTER_MIN_INTERVAL` | `600` | Seconds between REGISTER attempts per host/IP |
| `NICKSERV_IDENTIFY_MAX_FAILED_ATTEMPTS` | `5` | Failed attempts before lockout |
| `NICKSERV_IDENTIFY_FAILED_WINDOW_SECONDS` | `900` | Window to count failed attempts |
| `NICKSERV_IDENTIFY_LOCKOUT_SECONDS` | `600` | Lockout duration |
| `NICKSERV_INACTIVITY_EXPIRY_DAYS` | `90` | Drop after days of inactivity (`0` = disabled) |
| `NICKSERV_DROP_GRACE_DAYS` | `7` | Days a manually dropped nick remains restorable (`0` = deletion on maintenance) |
| `NICKSERV_LIST_PAGE_SIZE` | `50` | Matching registrations per IRCop LIST page |
| `NICKSERV_HISTORY_RETENTION_DAYS` | `30` | Days to keep action history (`0` = forever) |
| `NICKSERV_HISTORY_VIEW_LIMIT` | `40` | Max history entries per page |

### ChanServ

| Variable | Default | Description |
|----------|---------|-------------|
| `CHANSERV_NICK` | `ChanServ` | Bot nickname |
| `CHANSERV_IDENT` | `ChanServ` | Bot ident |
| `CHANSERV_REALNAME` | `Channel Registration Services` | Bot realname |
| `CHANSERV_MAX_CHANNELS_PER_NICK` | `3` | Max registered channels per nick |
| `CHANSERV_REGISTER_MIN_INTERVAL` | `21600` | Seconds between channel registrations |
| `CHANSERV_INACTIVITY_EXPIRY_DAYS` | `45` | Drop after days of inactivity (`0` = disabled) |
| `CHANSERV_DROP_GRACE_DAYS` | `7` | Days a manually dropped channel remains restorable (`0` = deletion on maintenance) |
| `CHANSERV_LIST_PAGE_SIZE` | `50` | Matching registrations per IRCop LIST page |
| `CHANSERV_HISTORY_RETENTION_DAYS` | `30` | Days to keep action history (`0` = forever) |
| `CHANSERV_HISTORY_VIEW_LIMIT` | `40` | Max history entries per page |

### MemoServ

| Variable | Default | Description |
|----------|---------|-------------|
| `MEMOSERV_NICK` | `MemoServ` | Bot nickname |
| `MEMOSERV_IDENT` | `MemoServ` | Bot ident |
| `MEMOSERV_REALNAME` | `Memo Services` | Bot realname |
| `MEMOSERV_SEND_MIN_INTERVAL` | `30` | Seconds between SEND commands |
| `MEMOSERV_MAX_MEMOS_PER_NICK` | `50` | Max memos per nickname |
| `MEMOSERV_MAX_MEMOS_PER_CHANNEL` | `50` | Max memos per channel |
| `MEMOSERV_IGNORE_LIST_LIMIT_NICK` | `50` | Max ignore entries per nick |
| `MEMOSERV_IGNORE_LIST_LIMIT_CHANNEL` | `100` | Max ignore entries per channel |

### OperServ

| Variable | Default | Description |
|----------|---------|-------------|
| `OPERSERV_NICK` | `OperServ` | Bot nickname |
| `OPERSERV_IDENT` | `OperServ` | Bot ident |
| `OPERSERV_REALNAME` | `Network Operations Services` | Bot realname |
| `OPERSERV_ROOT_USERS` | `ares` | ⚠ Comma-separated nicknames with full access |
| `OPERSERV_MAX_GLINES` | `1000` | Max active G-lines |
| `IRCOPS_DEBUG_CHANNEL` | _(commented out)_ | Channel for IRCop debug messages (e.g. `#opers`) |

When a service bot nickname changes, restart Ares after applying database migrations. Ares reserves the configured names again. The `unrealudb` driver releases obsolete Ares reservations only when its live UDB record still has the exact reason `Reserved for network services`. For `unreal` and `inspircd`, Ares retains historical names in its inventory but cannot verify whether a Q-line or SQLINE still belongs to it; it therefore never removes obsolete wire reservations automatically. Inspect the IRCd's current ban before removing it manually, particularly if an operator may have replaced it. Once a historical line is confirmed absent or operator-owned, its row in `service_nick_reservations` may be removed to stop the warning; do not remove the operator's ban. Reservations created before tracking was added are likewise left untouched.

### Maintenance

| Variable | Default | Description |
|----------|---------|-------------|
| `MAINTENANCE_INTERVAL` | `3600` | Interval between maintenance cycles (seconds) |

### Mailer

| Variable | Default | Description |
|----------|---------|-------------|
| `MAILER_DSN` | `null://null` | Symfony Mailer DSN (use `smtp://`, `sendmail://`, or `null://null` to discard) |
| `MAILER_FROM` | `NickServ <nickserv@localhost>` | Sender address for verification emails |
| `MAILER_SEND_DELAY_SECONDS` | `5` | Delay between emails (avoids provider rate limits) |

---

## IRCD Configuration

See the port notes in [Supported IRCds](#supported-ircds). The port in your link block must match `IRC_IRCD_PORT`.

### UnrealIRCd

Add to `unrealircd.conf`:

```
link services.example.com {
    incoming {
        mask *;
    };
    outgoing {
        bind-ip *;
        hostname 127.0.0.1;
        port 7029;
        options { };
    };
    password "your_link_password";
    class servers;
};

ulines { services.example.com; };
```

> **Handshake:** Ares sends `PROTOCTL EAUTH=<name> SID=<sid>` before the capability list — required for the 6.2.x protocol. Omitting `EAUTH`/`SID` triggers `LINK_OLD_PROTOCOL`.

### InspIRCd

Add to `inspircd.conf`:

```xml
<link name="services.example.com"
      ipaddr="127.0.0.1"
      port="7029"
      allowmask="*"
      sendpass="your_link_password"
      recvpass="your_link_password">

<uline server="services.example.com" silent="yes">
```

Ensure the `spanningtree` module is loaded.

> **Handshake:** Ares uses SpanTree v4 (1206). It sends `CAPAB START 1206` then
> `CAPAB CAPABILITIES :CASEMAPPING=ascii` (deliberately omitting module/mode CAPAB fields to
> skip comparison). At `CAPAB END`, the link requires protocol 1206 and the `services`,
> `account`, `chghost`, and `cloak` capabilities used by current operations; optional
> decoration modes are not required. No `CHALLENGE` is sent — authentication uses the
> plaintext link password. Set `IRC_SERVER_SID` to a 3-character alphanumeric value (for
> example, `0A0`).

---

## Service commands

Use `/msg <service> HELP` for the current command groups, `HELP <command>` for syntax, and
`HELP <command> <option>` for supported subcommands. All four services provide localized HELP
in the 14 supported languages. Restricted commands follow each service's visibility policy;
HELP visibility does not replace execution authorization, and Root access remains explicit.

Use `/msg NickServ IDENTIFY <nick> <password>` with `unreal` and `inspircd`. With
`unrealudb`, authentication is native: use `/NICK <nick>:<password>` or
`/NICK <nick>!<password>`; NickServ HELP explains this flow. NickServ VERIFY and RESEND apply
to the sender's current nickname.

For registration, confirmation, recovery, and credential-storage details, see
[Nick registration and recovery](docs/registration-and-recovery.md).

---

## Running the Services

All arguments are **optional** — each falls back to its `.env.local` variable:

```bash
php bin/console irc:connect

# Override individual values:
php bin/console irc:connect services.example.com irc.example.com 6697 secret \
    "Ares IRC Services" --tls
```

The protocol adapter is selected once at startup through `IRC_PROTOCOL`; it cannot be switched per
command invocation. This keeps protocol composition centralized and prevents mixing collaborators
from different IRCd adapters in one daemon session.

### Startup flow

1. **Handshake** — Ares connects to the IRCd and negotiates the S2S link.
2. **Burst** — The IRCd sends all existing users, channels, and modes.
3. **EOS/ENDBURST** — Network sync is complete; Ares introduces the service bots.
4. **Live** — Users interact with NickServ, ChanServ, MemoServ, and OperServ via `/msg`.

### Argument reference

| Argument / Option | Env fallback | Description |
|-------------------|-------------|-------------|
| `server-name` | `IRC_SERVER_NAME` | FQDN presented to IRCd |
| `host` | `IRC_IRCD_HOST` | IRCd hostname or IP |
| `port` | `IRC_IRCD_PORT` | IRCd server-link port |
| `password` | `IRC_LINK_PASSWORD` | Link password |
| `description` | `IRC_DESCRIPTION` | Text in `/MAP` and `/LINKS` |
| `--tls` | `IRC_USE_TLS` | Wrap connection in TLS |

---

## Internationalization (i18n)

Ares ships with **14 languages**, defaulting to `en` (English). Every translatable string exists in all languages.

| Code | Language |
|------|---------|
| `ca` | Catalan (Català) |
| `de` | German (Deutsch) |
| `el` | Greek (Ελληνικά) |
| `en` | English |
| `es` | Spanish (Español) |
| `eu` | Basque (Euskara) |
| `fr` | French (Français) |
| `gl` | Galician (Galego) |
| `it` | Italian (Italiano) |
| `nl` | Dutch (Nederlands) |
| `pl` | Polish (Polski) |
| `pt` | Portuguese (Português) |
| `ro` | Romanian (Română) |
| `tr` | Turkish (Türkçe) |

Translation files per service (in `translations/`) follow the pattern `<service>.<lang>.yaml`, with 6 domains × 14 languages = 84 files:

| Service | Files |
|---------|-------|
| Common | `common.<lang>.yaml` |
| NickServ | `nickserv.<lang>.yaml` |
| ChanServ | `chanserv.<lang>.yaml` |
| MemoServ | `memoserv.<lang>.yaml` |
| OperServ | `operserv.<lang>.yaml` |
| Email | `mail.<lang>.yaml` |

Users can change their language:

```
/msg NickServ SET LANGUAGE en
```

The default language for new users is controlled by `SERVICES_DEFAULT_LANGUAGE` in `.env.local`.

IRC color codes and formatting are handled via translation parameters — see the `translations/` files for the exact syntax.

---

## Docker Deployment

### Quick Start

```bash
make config         # create .env.local from .env
# edit .env.local   # set your IRCd connection details
make up             # build + start container
make logs           # follow logs
```

For large networks, Docker defaults PHP's `memory_limit` to 512 MiB for each CLI invocation and
caps the container at 2 GiB. Total process memory can exceed PHP's limit. Set
`PHP_MEMORY_LIMIT` and `ARES_CONTAINER_MEMORY_LIMIT` in the host shell or the
Compose environment before running `make up`, for example:

```bash
PHP_MEMORY_LIMIT=1G ARES_CONTAINER_MEMORY_LIMIT=3g make up
```

These are Docker/Compose settings, not `.env.local` settings. Measure memory use under a
representative load before choosing production limits. CLI OPcache is enabled, while JIT is
disabled because this daemon spends much of its time on IRC, events, and database I/O.
For large networks, use MySQL through `DATABASE_URL` instead of the default SQLite database;
the Doctrine Messenger transport also uses that database.

### All `make` targets

| Target | Description |
|--------|-------------|
| `make config` | Create `.env.local` from `.env` template |
| `make build` | Build Docker image |
| `make build-no-cache` | Build without Docker cache |
| `make build-multiarch` | Build for amd64 + arm64 |
| `make up` | Start services (rebuilds image) |
| `make down` | Stop services |
| `make restart` | Down then up |
| `make clean` | Remove containers, images, volumes, cache, and logs |
| `make logs` | Follow logs in real-time |
| `make logs-tail` | Show last 100 log lines |
| `make ps` | Show container status |
| `make shell` | Open shell inside container |
| `make health` | Inspect container state and health; fail if unavailable or unhealthy |
| `make db-migrate` | Run database migrations manually |
| `make test [TEST_ARGS='...']` | Run PHPUnit without coverage, optionally forwarding a path or filter |
| `make coverage` | Run the exact 100% coverage gate |

> **Destructive:** `make clean` removes Docker volumes and logs as well as containers, images,
> and cache. Back up any data you need before running it.

### Container lifecycle

Production dependencies are installed in the builder stage of `docker/Dockerfile`, then copied
into the runtime image. Startup does not run Composer; rebuild the image when dependencies change.

The repository script `docker/entrypoint.sh` (installed as `/entrypoint.sh`) runs at every container start:

1. Creates `.env.local` from `.env` if absent
2. Auto-generates `APP_SECRET` if missing or `changeme`
3. Syncs new keys from `.env` into `.env.local` via `docker/sync-env.sh` (installed as `/app/docker/sync-env.sh`)
4. Checks that `vendor/autoload.php` exists, failing with a rebuild instruction if absent
5. Runs `doctrine:migrations:migrate`
6. Starts `php bin/console irc:connect`

### Bind mounts

The container mounts three paths for persistence:

| Host path | Container path | Purpose |
|-----------|---------------|---------|
| `var/data/` | `/app/var/data/` | SQLite database |
| `var/log/` | `/app/var/log/` | Application logs |
| `.env.local` | `/app/.env.local` | Configuration |

### Networking

The container adds `host.docker.internal` → `host-gateway` in `extra_hosts`. Use this as `IRC_IRCD_HOST` in `.env.local` to reach the IRCd running on the Docker host.

---

## Testing & Development

### Run new or modified tests during development

```bash
make test
make test TEST_ARGS='tests/Path/Test.php --filter testName'
make coverage
```

`make test` runs PHPUnit without coverage and accepts an optional test path or PHPUnit filter
through `TEST_ARGS`. `make coverage` runs the exact 100% coverage gate. Reserve it for final
verification; do not run a standalone full suite immediately before or after it.

### Code style (PHP-CS-Fixer)

```bash
composer cs-fix     # auto-fix
composer cs-check   # check only
```

### Pre-commit verification chain

```bash
php -l path/to/file.php                                 # syntax check
php bin/console lint:container                           # DI validation
php bin/console lint:yaml . --exclude vendor/ --parse-tags  # YAML lint
composer phpstan                                        # PHPStan level max
./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php  # format
composer architecture                                   # Deptrac architecture boundaries
./scripts/check-coverage.sh 100 --issues                 # tests + coverage floor (single run)
```

Zero warnings, zero skipped, zero deprecated, zero incomplete required. Run `scripts/check-coverage.sh` only after the complete implementation; it runs the full PHPUnit suite WITH coverage exactly once. Do not run a standalone full suite immediately before or after it.

### Architecture

The target architecture uses five bounded contexts with hexagonal layers inside each context:

| Layer | Directory | Depends on | Imports |
|-------|-----------|------------|---------|
| Domain | `src/{Irc,NickServ,ChanServ,MemoServ,OperServ}/Domain/` | Same-context Domain, `Shared/Domain` | Pure PHP |
| Application | `src/<Context>/Application/` | Same-context Domain/Application, Shared kernel | No framework or concrete adapter |
| Adapter | `src/<Context>/Adapter/` | Own inner layers and public cross-context boundaries | External mechanisms |
| Protocol | `src/Irc/Adapter/Protocol/{InspIRCd,UnrealStandalone,UnrealUdb}/` | Irc boundaries | Protocol-specific wire/runtime code |
| Bootstrap | `src/Bootstrap/` | All composition boundaries | Framework/container wiring only |
| Kernel | `src/Kernel.php` | Bootstrap | Framework entry point only |

`composer architecture` enforces this graph with Deptrac. The gate accepts only the final bounded-context topology and its declared dependency boundaries.

Read [AGENTS.md](AGENTS.md) and [.agents/architecture.md](.agents/architecture.md) for the full architecture contract.

---

## Troubleshooting

### Link closes immediately after handshake

**Probable cause:** SID mismatch between Ares and IRCd, or UID prefix mismatch with SID.

Ares auto-generates UIDs from the SID prefix. Verify `IRC_SERVER_SID` in `.env.local` matches your IRCd's expected format (3-digit numeric for Unreal, 3-char alphanum for InspIRCd).

### No error message on disconnect

When an IRCd rejects the link, it sends `ERROR :<reason>` before closing. Ares logs this at `CRITICAL` level. Check `var/log/irc-*.log` for the actual rejection reason (e.g. "Invalid password", "CAPAB negotiation failed", "Bogus UUID").

### Services don't appear on the network

Verify **ULines** / **uline** is configured in your IRCd:
- UnrealIRCd: `ulines { services.example.com; };`
- InspIRCd: `<uline server="services.example.com" silent="yes">`

Without uline, service bots cannot set modes on other users.

### Channel modes not working

InspIRCd negotiates supported channel modes via `CAPAB CHANMODES` at link time. If modes (like `+P` for permanent channels) are not working, verify the InspIRCd `spanningtree` module is loaded and that the channel mode module is active on the IRCd. Ares parses the CAPAB response to dynamically discover available modes.

### Database migration failure

Run migrations manually inside the container:

```bash
make shell
php bin/console doctrine:migrations:migrate -n
```

Or from the host:

```bash
make db-migrate
```

### CAPAB lines appear garbled in logs

If `CAPAB` lines appear split or corrupted in logs, this was a buffering issue in the socket layer (fixed: the connection now buffers until a complete line is received).

---

## License

This project is licensed under the **GNU Affero General Public License v3.0 or later** (AGPL-3.0-or-later). See [LICENSE](LICENSE) for the full text.
