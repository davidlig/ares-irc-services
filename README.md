# Ares IRC Services

[![PHP](https://img.shields.io/badge/PHP-8.5-777BB4?style=flat&logo=php)](https://php.net)
[![Symfony](https://img.shields.io/badge/Symfony-7.4-black?style=flat&logo=symfony)](https://symfony.com)
[![License](https://img.shields.io/badge/license-AGPL--3.0--or--later-blue.svg)](LICENSE)
[![Release](https://img.shields.io/github/v/release/davidlig/ares-irc-services?include_prereleases&label=release)](https://github.com/davidlig/ares-irc-services/releases)

> **Under active development.** Functionality and APIs may change.

A modular, protocol-agnostic IRC services daemon built with **PHP 8.5**, **Symfony 7.4**, **Doctrine ORM 3.6**, and **Clean Architecture / DDD** principles. Ships with NickServ, ChanServ, MemoServ, and OperServ — all protocol-independent via a strategy pattern backed by a tagged-service registry.

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
- [Command Reference](#command-reference)
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
| [InspIRCd](https://www.inspircd.org/) 4.10.x | `inspircd` | SpanTree v4 (1206) | 3-char alphanum (`0A0`) | Plaintext (no CHALLENGE) | 7000 / 7001 |

The port you configure in `.env.local` (`IRC_IRCD_PORT`) must match whatever you set in your IRCd's `link` / `<link>` block.

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

Nickname registration with **email verification**. Users register a nick, confirm via email token, and then identify with a password. Registered nicks are protected — unauthenticated users are renamed to a configurable **guest prefix** (default `Ares-`).

Key features: password identification, **brute-force lockout** after repeated failures, token-based **RECOVER**, custom **VHOST** with configurable suffix, forbidden nick/vhost lists, suspension with auto-expiry, inactivity-based expiry, and a full **action history** log.

### ChanServ

Channel registration with hierarchical **access lists** (founder → admin → op → halfop → voice). Supports **AKICK** (auto-kick with expiry), **MLOCK** (mode lock), **TOPICLOCK**, **SECURE** (+M enforcement), **ENTRYMSG** (greeting on join), forbidden channels (block registration + kick joiners), founder transfer via token, suspension/unsuspension, **CLEARUSERS** (channel purge), inactivity expiry with auto-drop, and per-channel **action history**.

### MemoServ

Offline messaging for users and channels. SEND notes that are delivered on next IDENTIFY (for users) or next JOIN (for channels). Supports **ignore lists** (per-nick and per-channel). Configurable limits per nick and per channel. ENABLE/DISABLE per-nick memo acceptance. Sender receives confirmation with the target's last-seen timestamp.

### OperServ

IRC operator management with a role-based permission system. **IRCOP** ADD/DEL/LIST for registering IRC operators assigned to roles. **ROLE** system (ADD/DEL/LIST) with **PERMS** subcommand for fine-grained permissions (LIST/ADD/DEL/CLEAR with ALL shortcut), **MODES** for IRCOP user modes, and **VHOST** for forced vhost patterns. Protected default roles (ADMIN, OPER, PREOPER). Permissions auto-register from code into the database on first use. **GLINE** network-wide bans with duration and expiry. **MOTD** system (add/del/list/clean messages per bot with expiry). **GLOBAL** notices, **KILL** (force-disconnect), and **RAW** (arbitrary IRC command injection). Configurable **root users** have unrestricted access.

---

## Requirements

| Dependency | Minimum version |
|------------|----------------|
| PHP | 8.5 |
| Composer | 2.x |

**Required PHP extensions:**

`ext-ctype`, `ext-iconv`, `ext-sockets`, `ext-pdo`, `ext-pdo_mysql`, `ext-mbstring`, `ext-xml`, `ext-json`

For SQLite (default): `ext-pdo_sqlite`. For PostgreSQL: `ext-pdo_pgsql`.

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
| `IRC_PROTOCOL` | `unreal` | ⚠ `unreal` or `inspircd` |
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
| `NICKSERV_REGISTER_MIN_INTERVAL` | `600` | Seconds between REGISTER attempts per host/IP |
| `NICKSERV_IDENTIFY_MAX_FAILED_ATTEMPTS` | `5` | Failed attempts before lockout |
| `NICKSERV_IDENTIFY_FAILED_WINDOW_SECONDS` | `900` | Window to count failed attempts |
| `NICKSERV_IDENTIFY_LOCKOUT_SECONDS` | `600` | Lockout duration |
| `NICKSERV_RECOVER_TOKEN_TTL` | `3600` | RECOVER token TTL (seconds) |
| `NICKSERV_RECOVER_MIN_INTERVAL` | `600` | Seconds between RECOVER attempts |
| `NICKSERV_INACTIVITY_EXPIRY_DAYS` | `90` | Drop after days of inactivity (`0` = disabled) |
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
| `CHANSERV_FOUNDER_TOKEN_TTL` | `3600` | SET FOUNDER token TTL |
| `CHANSERV_FOUNDER_MIN_INTERVAL` | `600` | Seconds between SET FOUNDER attempts |
| `CHANSERV_INACTIVITY_EXPIRY_DAYS` | `45` | Drop after days of inactivity (`0` = disabled) |
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

> **Handshake:** Ares uses SpanTree v4 (1206). It sends `CAPAB START 1206` then `CAPAB CAPABILITIES :CASEMAPPING=ascii` (deliberately omitting module/mode CAPAB fields to skip comparison). No `CHALLENGE` is sent — plaintext password authentication. Set `IRC_SERVER_SID` to a 3-char alphanumeric value (e.g. `0A0`).

---

## Command Reference

### NickServ

| Command | Parameters | Description |
|---------|------------|-------------|
| `REGISTER` | `<password> <email>` | Register a nickname |
| `VERIFY` | `<nick> <token>` | Confirm email verification |
| `RESEND` | `<nick>` | Re-send verification email |
| `RECOVER` | `<nick> [token]` | Recover a registered nick |
| `IDENTIFY` | `<password>` | Identify to your registered nick |
| `STATUS` | `<nick>` | Show nick registration status |
| `INFO` | `<nick>` | Show nick registration details |
| `SET PASSWORD` | `<new-password>` | Change your password |
| `SET EMAIL` | `<email>` | Change your email address |
| `SET LANGUAGE` | `<ca\|de\|el\|en\|es\|eu\|fr\|gl\|it\|nl\|pl\|pt\|ro\|tr>` | Set your preferred language |
| `SET TIMEZONE` | `<tz>` | Set your timezone |
| `SET PRIVATE` | `<on\|off>` | Hide registration in STATUS |
| `SET MSG` | `<on\|off>` | Set NOTICE vs PRIVMSG delivery |
| `SET VHOST` | `<vhost>` | Set a custom virtual host |
| `SASET` | `<nick> <option> <value>` | Admin SET on any nick |
| `DROP` | `<nick>` | Drop a nickname registration |
| `FORBID` | `<nick>` | Forbid a nickname from registration |
| `FORBIDVHOST` | `<vhost>` | Forbid a vhost pattern |
| `UNFORBID` | `<nick>` | Remove nick forbiddance |
| `SUSPEND` | `<nick> [duration]` | Suspend a nickname registration |
| `UNSUSPEND` | `<nick>` | Unsuspend a nickname |
| `RENAME` | `<nick> <new-nick>` | Rename a registered nick |
| `NOEXPIRE` | `<nick>` | Toggle no-expire flag |
| `USERIP` | `<nick>` | Show IP address of a nick (IRCops only) |
| `HISTORY` | `<nick> [page]` | View nickname action history |
| `HELP` | `[command]` | Show command help |

### ChanServ

| Command | Parameters | Description |
|---------|------------|-------------|
| `REGISTER` | `<#channel> <description>` | Register a channel |
| `INFO` | `<#channel>` | Show channel registration details |
| `SET FOUNDER` | `<#channel> <nick>` | Transfer channel founder |
| `SET SUCCESSOR` | `<#channel> <nick>` | Set channel successor |
| `SET DESC` | `<#channel> <desc>` | Set channel description |
| `SET URL` | `<#channel> <url>` | Set channel URL |
| `SET EMAIL` | `<#channel> <email>` | Set channel contact email |
| `SET ENTRYMSG` | `<#channel> <msg>` | Set join greeting message |
| `SET TOPICLOCK` | `<#channel> <on\|off>` | Lock the channel topic |
| `SET MLOCK` | `<#channel> <modes>` | Set enforced channel modes |
| `SET SECURE` | `<#channel> <on\|off>` | Only access-listed users can join |
| `ACCESS` | `<#channel> <nick> <level>` | Grant access to a nick |
| `DELACCESS` | `<#channel> <nick>` | Remove access from a nick |
| `LEVELS` | `<#channel> <type> <level>` | Configure privilege levels |
| `OP` | `<#channel> [nick]` | Give +o in a channel |
| `DEOP` | `<#channel> [nick]` | Remove +o in a channel |
| `VOICE` | `<#channel> [nick]` | Give +v in a channel |
| `DEVOICE` | `<#channel> [nick]` | Remove +v in a channel |
| `INVITE` | `<#channel>` | Invite yourself to a channel |
| `ADMIN` | `<#channel> [nick]` | Give +a in a channel |
| `DEADMIN` | `<#channel> [nick]` | Remove +a in a channel |
| `HALFOP` | `<#channel> [nick]` | Give +h in a channel |
| `DEHALFOP` | `<#channel> [nick]` | Remove +h in a channel |
| `AKICK` | `<#channel> <nick\|mask> [duration] [reason]` | Manage auto-kick list |
| `DROP` | `<#channel>` | Drop channel registration |
| `SUSPEND` | `<#channel> [duration]` | Suspend a channel |
| `UNSUSPEND` | `<#channel>` | Unsuspend a channel |
| `FORBID` | `<#channel>` | Forbid a channel name |
| `UNFORBID` | `<#channel>` | Remove channel forbiddance |
| `NOEXPIRE` | `<#channel>` | Toggle no-expire flag |
| `CLEARACCESS` | `<#channel>` | Remove all access entries |
| `CLEARUSERS` | `<#channel>` | Remove all non-privileged users |
| `HISTORY` | `<#channel> [page]` | View channel action history |
| `HELP` | `[command]` | Show command help |

### MemoServ

| Command | Parameters | Description |
|---------|------------|-------------|
| `SEND` | `<nick\|#channel> <message>` | Send a memo |
| `READ` | `[number\|LAST\|NEW]` | Read memos |
| `LIST` | | List your memos |
| `DEL` | `<number\|ALL>` | Delete memos |
| `IGNORE` | `<nick\|#channel> [ADD\|DEL\|LIST]` | Manage ignore list |
| `ENABLE` | | Enable memo reception |
| `DISABLE` | | Disable memo reception |
| `HELP` | `[command]` | Show command help |

### OperServ

| Command | Parameters | Description |
|---------|------------|-------------|
| `IRCOP ADD` | `<nick> <role>` | Add an IRC operator |
| `IRCOP DEL` | `<nick>` | Remove an IRC operator |
| `IRCOP LIST` | | List registered IRC operators |
| `ROLE ADD` | `<name> [description]` | Create a new role |
| `ROLE DEL` | `<name>` | Delete a role |
| `ROLE LIST` | | List all roles |
| `ROLE PERMS` | `<role> {LIST\|ADD\|DEL\|CLEAR} [permission\|ALL]` | Manage role permissions |
| `ROLE MODES` | `<role> {VIEW\|SET} [modes]` | Manage IRCOP user modes for a role |
| `ROLE VHOST` | `<role> {VIEW\|SET} [pattern]` | Manage forced vhost pattern for a role |
| `GLINE` | `ADD\|DEL\|LIST <mask> [duration] [reason]` | Manage G-lines |
| `MOTD ADD` | `<bot> <type> <message> [expiry]` | Add a MOTD message |
| `MOTD DEL` | `<bot> <id>` | Delete a MOTD message |
| `MOTD LIST` | `[bot]` | List MOTD messages |
| `MOTD CLEAN` | `[bot]` | Remove expired MOTD messages |
| `GLOBAL` | `<message>` | Send a global notice |
| `KILL` | `<nick> <reason>` | Force-disconnect a user |
| `RAW` | `<command>` | Send raw IRC command to IRCd |
| `HELP` | `[command]` | Show command help |

---

## Running the Services

All arguments are **optional** — each falls back to its `.env.local` variable:

```bash
php bin/console irc:connect

# Override individual values:
php bin/console irc:connect services.example.com irc.example.com 6697 secret \
    "Ares IRC Services" --protocol=unreal --tls
```

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
| `--protocol` / `-p` | `IRC_PROTOCOL` | `unreal` or `inspircd` |
| `--tls` | `IRC_USE_TLS` | Wrap connection in TLS |

---

## Internationalization (i18n)

Ares ships with **14 languages**, defaulting to `es` (Spanish). Every translatable string exists in all languages.

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
| `make clean` | Remove containers, volumes, images + clear cache |
| `make logs` | Follow logs in real-time |
| `make logs-tail` | Show last 100 log lines |
| `make ps` | Show container status |
| `make shell` | Open shell inside container |
| `make health` | Check container health |
| `make db-migrate` | Run database migrations manually |
| `make db-backup` | Backup SQLite DB to `backups/` |
| `make db-restore` | Restore from backup (`FILE=backups/ares-*.db`) |
| `make config-show` | Show resolved configuration |

### Container lifecycle

The `entrypoint.sh` script runs at every container start:

1. Creates `.env.local` from `.env` if absent
2. Auto-generates `APP_SECRET` if missing or `changeme`
3. Syncs new keys from `.env` into `.env.local` via `sync-env.sh`
4. Runs `composer install` (production, no-dev)
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

### Run tests

```bash
./vendor/bin/phpunit --no-coverage --display-all-issues
```

### Check code coverage (100% required)

```bash
./scripts/check-coverage.sh 100
```

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
./vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php  # format
./vendor/bin/phpunit --no-coverage --display-all-issues   # tests
./scripts/check-coverage.sh 100                          # coverage floor
```

Zero warnings, zero skipped, zero deprecated, zero incomplete required.

### Architecture

The project follows **Clean Architecture** with **Domain-Driven Design**:

| Layer | Directory | Depends on | Imports |
|-------|-----------|------------|---------|
| Domain | `src/Domain/` | Nothing | Pure PHP |
| Application | `src/Application/` | Domain | Domain |
| Infrastructure | `src/Infrastructure/` | Domain + Application | Symfony, Doctrine |
| UI | `src/UI/` | Application | Symfony Console |

Read `.agents/architecture/README.md` for the full architecture guide.

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
