# Ares IRC Services

A modular, protocol-agnostic IRC services daemon (Core + NickServ + ChanServ + MemoServ) built with **PHP 8.4** and **Symfony 7.4**, following Clean Architecture and Domain-Driven Design principles.

> **Note:** The services are **not complete** and are **under active development**. Functionality and APIs may change.

Supports multiple IRC daemon backends out of the box:

| IRCD | Protocol driver |
|---|---|
| [UnrealIRCd](https://www.unrealircd.org/) 6.2.2 | `unreal` |
| [InspIRCd](https://www.inspircd.org/) 4.9.0 | `inspircd` |

---

## Requirements

| Dependency | Minimum version |
|---|---|
| PHP | 8.4 |
| Composer | 2.x |
| Symfony CLI *(optional, recommended)* | 5.x |

**PHP extensions** (required to run the application):

- `ext-ctype`, `ext-iconv`, `ext-sockets`, `ext-pdo`, `ext-mbstring`, `ext-xml`, `ext-json`
- For the default database (SQLite): `ext-pdo_sqlite` — if you use another driver (MySQL, PostgreSQL), you need the corresponding `ext-pdo_*` instead.

You can check loaded extensions with `php -m`.

**Stack:** Symfony 7.4, Doctrine ORM 3.6, Doctrine Migrations 4.0. Exact dependency versions are in `composer.json` and `composer.lock`.

---

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/davidlig/ares-irc-services.git
cd ares-irc-services
```

### 2. Install PHP dependencies

```bash
composer install --no-dev --optimize-autoloader   # production
# or
composer install                                  # development
```

### 3. Configure the environment

Copy the provided template and fill in your values:

```bash
cp .env .env.local
```

Open `.env.local` and set every variable marked with `changeme`:

```dotenv
APP_SECRET=<random 32-char hex string>

IRC_SERVER_NAME=services.example.com   # must match your IRCD link block
IRC_IRCD_HOST=127.0.0.1
IRC_IRCD_PORT=7029
IRC_LINK_PASSWORD=<your link password>
IRC_DESCRIPTION="Ares IRC Services"
IRC_PROTOCOL=unreal                    # or: inspircd
IRC_USE_TLS=false
IRC_SERVER_SID=001                     # SID for this server (format depends on IRC_PROTOCOL: 001 / A0A)
```

> **Never commit `.env.local`** — it is already listed in `.gitignore`.

You can generate a secure `APP_SECRET` with:

```bash
php -r "echo bin2hex(random_bytes(16)) . PHP_EOL;"
```

---

## IRCD Configuration

### UnrealIRCd

Add a `link` block to your `unrealircd.conf`:

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

### InspIRCd

Add a `<link>` tag to your `inspircd.conf`:

```xml
<link name="services.example.com"
      ipaddr="127.0.0.1"
      port="7029"
      allowmask="*"
      sendpass="your_link_password"
      recvpass="your_link_password">

<uline server="services.example.com" silent="yes">
```

Also ensure the `spanningtree` module is loaded.

---

## Running the services

All arguments are **optional**. When omitted, each one falls back to the corresponding
environment variable defined in `.env.local`.

```bash
# Use every value from .env.local — no arguments needed
php bin/console irc:connect

# Override individual values on the fly
php bin/console irc:connect services.example.com irc.example.com 6697 secret \
    "Ares IRC Services" --protocol=unreal --tls
```

When the connection is established and the initial network burst completes, the Core IRC layer syncs users and channels and then introduces the service bots (NickServ, ChanServ, MemoServ, etc.) automatically. From that point, users on the network can interact with the services using normal IRC commands (e.g. `/msg NickServ ...`, `/msg ChanServ ...`, `/msg MemoServ ...`), without any extra manual setup.

### Argument / option reference

| Argument / Option | Env var fallback | Description |
|---|---|---|
| `server-name` | `IRC_SERVER_NAME` | FQDN Ares presents to the IRCD |
| `host` | `IRC_IRCD_HOST` | IRCD hostname or IP |
| `port` | `IRC_IRCD_PORT` | IRCD server-link port |
| `password` | `IRC_LINK_PASSWORD` | Link password |
| `description` | `IRC_DESCRIPTION` | Text shown in `/MAP` and `/LINKS` |
| `--protocol` / `-p` | `IRC_PROTOCOL` | `unreal` or `inspircd` |
| `--tls` | `IRC_USE_TLS` | Wrap the connection in TLS |

> **UnrealIRCd note:** Ares uses the 4.x / 5.x / 6.x protocol. The handshake sends
> `PROTOCTL EAUTH=<name> SID=<sid>` before the capability list, which is required for
> UnrealIRCd to accept the link. Omitting `EAUTH`/`SID` causes the
> `LINK_OLD_PROTOCOL` rejection.

---

## Docker Deployment

### Quick Start

```bash
# 1. Build the image
make build

# 2. Initialize environment (creates .env.local if missing)
make up
# The 'make up' command automatically runs scripts/init-env.sh and rebuilds the image

# 3. Edit .env.local and configure your IRCD connection
# See "Configuration" section below for required variables

# 4. Restart with your configuration
make restart

# 5. Check logs
make logs
```

**Note:** `make up` always rebuilds the Docker image to ensure you have the latest code. This means:
- After `git pull`, running `make up` will automatically include new code changes
- First start takes longer because it builds the image
- Subsequent starts are faster due to Docker layer caching

### Available Make Commands

Run `make help` or `make` to see all available commands:

| Command | Description |
|---------|-------------|
| `make build` | Build Docker image |
| `make build-no-cache` | Build without cache |
| `make up` | Start services in background |
| `make down` | Stop services |
| `make restart` | Restart services |
| `make logs` | Follow logs in real-time |
| `make logs-tail` | Show last 100 lines of logs |
| `make shell` | Open shell inside container |
| `make health` | Check container health |
| `make db-backup` | Backup database to `backups/` |
| `make db-restore FILE=path` | Restore database from backup |
| `make clean` | Remove containers and clean artifacts |

### Multi-Architecture Support

The Docker image supports both **amd64** (x86_64) and **arm64** (ARM) architectures:

```bash
# Build for current platform
make build

# Build for multiple platforms (requires docker buildx)
make build-multiarch
```

All Docker-related files are organized in the `docker/` directory:
- `docker/Dockerfile` - Multi-arch PHP 8.4 CLI Alpine image
- `docker/docker-compose.yml` - Service orchestration
- `docker/entrypoint.sh` - Initialization script
- `docker/.dockerignore` - Build optimization
- `docker/docker-buildx.sh` - Multi-arch builder

### Configuration

#### Environment Variables

Container initialization will automatically create `.env.local` from `.env` template on first run, generating a secure `APP_SECRET`.

**Automatic configuration sync:** On every container start, the entrypoint automatically syncs new configuration keys from `.env` to `.env.local` for the following blocks:
- `###> ares/irc-link ###` - IRC connection settings
- `###> ares/services ###` - Services configuration

**Automatic variable detection:** The sync process detects variables automatically:
- Regular variables: `VARIABLE_NAME=value`
- Optional/commented variables: `# VARIABLE_NAME=value` (synced as-is, remains commented)
- Only adds missing variables (never overwrites existing configurations)
- Future-proof: works with any new variables added to .env blocks

**First-time setup:**

1. Run `make up` (creates `.env.local` automatically)
2. Edit `.env.local` and configure IRCD connection
3. Run `make restart` to apply changes

**Required variables** (set in `.env.local`):

| Variable | Description | Example |
|----------|-------------|---------|
| `IRC_IRCD_HOST` | IRCD hostname or IP | See connection methods below |
| `IRC_IRCD_PORT` | IRCD server-link port | `7000` |
| `IRC_LINK_PASSWORD` | Link password from IRCD config | `your-secret-password` |
| `IRC_SERVER_NAME` | FQDN Ares presents to IRCD | `services.example.com` |
| `IRC_PROTOCOL` | Protocol driver | `unreal` or `inspircd` |

#### Connection Methods

**Docker Desktop (macOS/Windows):**

The `docker-compose.yml` includes `extra_hosts` to map `host.docker.internal`:

```env
IRC_IRCD_HOST=host.docker.internal
```

**Linux (Docker bridge):**

Use the Docker bridge gateway IP (usually `172.17.0.1`):

```env
IRC_IRCD_HOST=172.17.0.1
```

**Linux (host network mode):**

Edit `docker-compose.yml` and replace the `network_mode` and `extra_hosts` configuration:

```yaml
services:
  ares:
    network_mode: host
    # Remove extra_hosts when using host network mode
```

Then set in `.env.local`:

```env
IRC_IRCD_HOST=127.0.0.1
```

**Remote IRCD server:**

```env
IRC_IRCD_HOST=irc.yournetwork.net
IRC_IRCD_PORT=7000
```

### Data Persistence

The following directories are persisted via bind mounts:

| Host Path | Container Path | Purpose |
|-----------|---------------|---------|
| `./var/data/` | `/app/var/data/` | SQLite database (`ares.db`) |
| `./var/log/` | `/app/var/log/` | IRC service logs |
| `.env.local` | `/app/.env.local` | Configuration (writable for APP_SECRET generation) |

### Container Initialization

On startup, the container executes:

1. **Check `.env.local`** – If missing, copy from `.env` template
2. **Generate `APP_SECRET`** – If missing or contains `changeme`, generate random 32-char hex string
3. **Sync configuration** – Add new keys from `.env` to `.env.local` (ares/irc-link and ares/services blocks)
4. **Install dependencies** – `composer install -n --no-dev --optimize-autoloader --classmap-authoritative`
5. **Run migrations** – `doctrine:migrations:migrate -n`
6. **Start services** – `php bin/console irc:connect`

**Note:** The `.env.local` file must be writable by the container to generate `APP_SECRET` automatically. This is safe because:
- `.env.local` is listed in `.gitignore` (won't be committed)
- Contains only local configuration (no secrets in production)
- Automatically syncs new configuration keys from `.env` when new features are added

### Health Check

The container includes a health check that verifies the PHP process is running:

```bash
# Check container health
docker-compose ps
# STATUS: "healthy" or "unhealthy"

# Manual health check
make health
# Output: "✅ Healthy" or "❌ Unhealthy"
```

Health check configuration:
- **Interval**: every 30 seconds
- **Timeout**: 3 seconds to respond
- **Start period**: 10 seconds grace period at startup
- **Retries**: 3 attempts before marking unhealthy

### Logs and Debugging

```bash
# Follow logs in real-time
make logs

# Show last 100 lines
make logs-tail

# Open shell in container
make shell

# Check current configuration
make config-show

# Inspect container status
docker-compose ps
docker inspect ares-irc-services
```

### Database Operations

```bash
# Create backup (stored in backups/ directory)
make db-backup
# Output: ✅ Backup created: backups/ares-20260403-123456.db

# List available backups
ls -lh backups/

# Restore from backup
make db-restore FILE=backups/ares-20260403-123456.db

# Open SQLite CLI (for debugging)
make db-shell
```

Backups are stored in the `backups/` directory on the host with timestamp format: `ares-YYYYMMDD-HHMMSS.db`.

### Updating

```bash
# Pull latest code
git pull origin main

# Start services (automatically rebuilds image with new code)
make up
```

**What happens on `make up`:**

1. Runs `scripts/init-env.sh` (creates `.env.local` if missing)
2. Rebuilds Docker image with latest code
3. Starts container
4. Container entrypoint runs:
   - `composer install` (installs/updates dependencies)
   - `doctrine:migrations:migrate` (applies new migrations)
   - Starts IRC services

**Note:** `make up` always rebuilds the image, ensuring code changes from `git pull` are included. This is safer than manual `make build && make restart` because you won't forget to rebuild.

### Troubleshooting

#### .env.local: permission denied / mount error

The bind mount requires `.env.local` to exist before starting the container. The `make up` command handles this automatically, but if you see this error:

```
Error: .env.local: no such file or directory
```

Run the initialization script manually:

```bash
./scripts/init-env.sh
```

#### Container exits immediately

```bash
# Check logs for errors
docker-compose logs ares

# Verify .env.local exists and has correct values
cat .env.local | grep IRC_

# Check container status
docker-compose ps
```

#### Cannot connect to IRCD

```bash
# Verify network connectivity from container
docker-compose exec ares nc -v $IRC_IRCD_HOST $IRC_IRCD_PORT

# Check IRCD logs for connection attempts
# Ensure IRC_SERVER_NAME matches your IRCD link block
# Ensure IRC_LINK_PASSWORD matches your IRCD configuration
```

#### Database locked errors

```bash
# Ensure only one container instance is running
docker-compose ps
docker-compose down
docker-compose up -d
```

#### Permission denied errors

```bash
# Check file ownership
ls -la var/data var/log

# Fix permissions (run on host)
sudo chown -R 1000:1000 var/data var/log
```

### Docker Compose Override (Development)

For development with code hot-reload, create `docker-compose.override.yml`:

```yaml
services:
  ares:
    volumes:
      - ./:/app:cached
    environment:
      - APP_ENV=dev
      - PHP_OPCACHE_VALIDATE_TIMESTAMPS=1
```

Then run:

```bash
docker-compose -f docker-compose.yml -f docker-compose.override.yml up
```

This mount the entire source code for hot-reloading during development.

---

## Architecture

The project follows **Clean Architecture** with strict layer separation:

```
src/
├── Domain/IRC/                     Pure PHP — entities, value objects, domain events, no framework
│   ├── Connection/                 ConnectionInterface, ConnectionFactoryInterface
│   ├── Event/                      ConnectionEstablishedEvent, ConnectionLostEvent, MessageReceivedEvent, NetworkBurstCompleteEvent, NetworkSyncCompleteEvent, ...
│   ├── Message/                    IRCMessage, MessageDirection (canonical wire-agnostic form)
│   ├── Network/                    In-memory network state (servers, users, channels)
│   ├── Protocol/                   ProtocolHandlerInterface, ProtocolHandlerRegistryInterface
│   ├── Repository/                 ChannelRepositoryInterface, NetworkUserRepositoryInterface
│   ├── Server/                     ServerLink
│   ├── ValueObject/                Hostname, Port, ServerName, LinkPassword, etc.
│   └── (root)                      LocalUserModeSyncInterface, SkipIdentifiedModeStripRegistryInterface
│
├── Domain/NickServ/                Nick registration and authentication domain
│   ├── Entity/                     RegisteredNick
│   ├── Event/                      NickIdentifiedEvent, NickDropEvent
│   ├── Exception/                  NickAlreadyRegisteredException, NickNotRegisteredException, InvalidCredentialsException, ...
│   ├── Repository/                 RegisteredNickRepositoryInterface
│   ├── Service/                    PasswordHasherInterface
│   └── ValueObject/                NickStatus
│
├── Domain/ChanServ/                Channel registration and access control domain
│   ├── Entity/                     RegisteredChannel, ChannelAccess, ChannelLevel
│   ├── Event/                      ChannelDropEvent
│   ├── Exception/                  ChannelAlreadyRegisteredException, ChannelNotRegisteredException, InsufficientAccessException, ...
│   └── Repository/                 RegisteredChannelRepositoryInterface, ChannelAccessRepositoryInterface, ChannelLevelRepositoryInterface
│
├── Domain/MemoServ/                Memo (messaging) domain for nicknames and channels
│   ├── Entity/                     Memo, MemoIgnore, MemoSettings
│   ├── Exception/                  MemoNotFoundException, MemoDisabledException
│   └── Repository/                 MemoRepositoryInterface, MemoIgnoreRepositoryInterface, MemoSettingsRepositoryInterface
│
├── Application/IRC/                Use cases — depends only on Domain
│   ├── IRCClient                   Connect → read loop → disconnect orchestrator
│   ├── IRCClientFactory            Wires connection + protocol module into an IRCClient
│   └── Connect/                    ConnectToServerCommand, ConnectToServerHandler
│
├── Application/Port/               Ports and DTOs between Core IRC and services
│   ├── SenderView                  Readonly view of the user who sent a command
│   ├── ChannelView                 Readonly view of a channel (name, modes, topic, ...)
│   ├── NetworkUserLookupPort       Find a connected user by UID, return SenderView
│   ├── ChannelLookupPort           Find a channel by name, return ChannelView
│   ├── SendNoticePort              Send NOTICEs to users via the active connection
│   ├── ProtocolModuleInterface     Bundle of protocol-specific handler/actions/formatters
│   ├── ProtocolModuleRegistryInterface
│   ├── ProtocolServiceActionsInterface, ServiceIntroductionFormatterInterface, VhostCommandBuilderInterface
│   ├── ChannelModeSupportInterface, ActiveChannelModeSupportProviderInterface
│   ├── ChannelServiceActionsPort, ChannelSyncCompletedRegistryInterface, ApplyOutgoingChannelModesPort
│   ├── BurstCompletePort
│   └── ServiceCommandListenerInterface
│
├── Application/Shared/             Cross-cutting application helpers
│   └── Help/                       UnifiedHelpFormatter, HelpFormatterContextInterface
│
├── Application/NickServ/           NickServ application layer (commands, flows, registries)
│   ├── Command/                    Command parsing, routing and handlers for /msg NickServ (incl. SET subcommands in Handler/)
│   ├── Maintenance/                Periodic maintenance tasks, pruners (Maintenance/Pruner)
│   └── Security/                   Permission model and authorization contracts
│
├── Application/ChanServ/           ChanServ application layer (commands, mlock, access lists)
│   ├── Command/                    Command parsing, routing and handlers for /msg ChanServ (incl. SET subcommands in Handler/)
│   ├── Event/                      Application events (ChannelSecureEnabledEvent, mlock updated, ...)
│   ├── Maintenance/                PurgeInactiveChannelsTask, etc.
│   └── Service/                    ChanServAccessHelper, founder/successor logic
│
├── Application/MemoServ/          MemoServ application layer (SEND, READ, LIST, DEL, IGNORE, ENABLE, DISABLE, HELP)
│   ├── Command/                    Command parsing, routing and handlers for /msg MemoServ
│   └── Event/                      Application events for MemoServ
│
├── Application/Mail/              Mail use cases (e.g. SendEmailHandler)
├── Application/Maintenance/        MaintenanceScheduler, MaintenanceTaskInterface, shared maintenance contracts
│
├── Infrastructure/IRC/             Adapters — implements Domain and Port interfaces
│   ├── Connection/                 SocketConnection (TCP/TLS), SocketConnectionFactory, ActiveConnectionHolder
│   ├── Network/                    Network state adapters (Adapter/), subscribers and sync helpers
│   ├── Protocol/                   Protocol modules and helpers
│   │   ├── ProtocolModuleRegistry, ProtocolHandlerRegistry  Tag-based discovery of protocol modules
│   │   ├── Unreal/                 UnrealIRCdModule + UnrealIRCdProtocolHandler + service actions, introduction formatter, vhost builder, channel mode support
│   │   └── InspIRCd/               InspIRCdModule + InspIRCdProtocolHandler + service actions, introduction formatter, vhost builder, channel mode support
│   ├── Logging/                    IRCEventSubscriber and log wiring
│   ├── Security/                   IRC-layer security adapters
│   └── ServiceBridge/              Bridge between Core and service command gateway
│
├── Infrastructure/Common/          Shared infrastructure (Doctrine types, etc.)
│   └── Doctrine/Type/
│
├── Infrastructure/Shared/          Cross-cutting infrastructure (e.g. DoctrineIdentityMapClearSubscriber)
│   └── Subscriber/
│
├── Infrastructure/NickServ/       Infrastructure for NickServ (bot, persistence, security)
│   ├── Bot/                        NickServBot (bridge between Service Command Gateway and NickServ)
│   ├── Doctrine/                   RegisteredNickDoctrineRepository
│   ├── Security/                   Symfony security adapters, voters, password hasher
│   └── Subscriber/                 Event subscribers (protection, vhost sync, etc.)
│
├── Infrastructure/ChanServ/        Infrastructure for ChanServ (bot, persistence, subscribers)
│   ├── Bot/                        ChanServBot
│   ├── Doctrine/                   RegisteredChannel, ChannelAccess, ChannelLevel repositories
│   └── Subscriber/                 ChanServChannelRankSubscriber, ChanServTopicApplySubscriber, ChanServEntryMsgSubscriber, etc.
│
├── Infrastructure/MemoServ/        Infrastructure for MemoServ (bot, persistence, cleanup on nick/channel drop)
│   ├── Bot/                        MemoServBot
│   ├── Doctrine/                   MemoDoctrineRepository, MemoIgnoreDoctrineRepository, MemoSettingsDoctrineRepository
│   └── Subscriber/                 MemoServCommandListener, MemoServNickIdentifiedNoticeSubscriber, MemoServChannelDropCleanupSubscriber, MemoServNickDropCleanupSubscriber, etc.
│
├── Infrastructure/Mail/            Mail delivery adapters
├── Infrastructure/Messenger/       Symfony Messenger middleware (e.g. for async/identity map)
│
└── UI/CLI/                         Symfony console commands
    └── ConnectCommand              bin/console irc:connect
```

### Adding a new protocol

1. Create a module in `src/Infrastructure/IRC/Protocol/<Name>/<Name>Module.php` that implements `ProtocolModuleInterface` and wires together:
   - the protocol handler (parsing/formatting, handshake, burst),
   - the service actions (KILL, SVSJOIN, SVSMODE, etc.),
   - the service introduction formatter (pseudo-client introduction lines),
   - the vhost command builder and channel mode support (which prefix modes v/h/o/a/q exist).
2. Register the module as a Symfony service with the `irc.protocol_module` tag so that `ProtocolModuleRegistry` can discover it by `getProtocolName()`.
3. Wire a network state adapter for the new protocol (if needed) in the IRC network configuration so incoming messages are mapped to common domain events.

No other core code changes are required — adding a module and its adapter is enough for the new IRCd to be supported.

---

## Development

### Testing

The project uses PHPUnit 13. Run the full suite (no coverage):

```bash
./vendor/bin/phpunit --no-coverage
```

To generate a code coverage report (requires PCOV or Xdebug):

```bash
./vendor/bin/phpunit --coverage-text --coverage-filter=src
```

HTML and Clover reports are written to `var/coverage/`. To enforce a minimum line coverage (e.g. in CI), run `./scripts/check-coverage.sh [MIN_PERCENT]` (e.g. `./scripts/check-coverage.sh 100` when targeting 100%). See [.agents/testing/README.md](.agents/testing/README.md) and [.agents/testing/testing-coverage-priorities.md](.agents/testing/testing-coverage-priorities.md) for conventions, coverage priorities, and **division by Agents** (per-service test commands and coverage filters for parallel work).

### Useful commands

```bash
# List all registered console commands
php bin/console list

# Inspect the Symfony DI container
php bin/console debug:container --tag=irc.protocol_handler

# Clear the cache
php bin/console cache:clear
```

---

## License

Proprietary — All rights reserved.
