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

### Useful commands

```bash
# List all registered console commands
php bin/console list

# Inspect the Symfony DI container
php bin/console debug:container --tag=irc.protocol_handler

# Clear the cache
php bin/console cache:clear
```

### Testing

The test suite uses **PHPUnit 13** with attributes (`#[Test]`, `#[CoversClass]`). Tests live under `tests/` and mirror the structure of `src/` (Domain, Application, Infrastructure).

```bash
# Run all tests (no coverage)
./vendor/bin/phpunit --no-coverage

# Run with deprecation/notice display
./vendor/bin/phpunit --no-coverage --display-deprecations --display-phpunit-deprecations --display-phpunit-notices

# Run with code coverage (requires a coverage driver such as PCOV or Xdebug)
./vendor/bin/phpunit --coverage-text --coverage-filter=src
```

Configuration: `phpunit.dist.xml`. Bootstrap: `tests/bootstrap.php` (loads autoload and Dotenv for `.env.test`).

---

## License

Proprietary — All rights reserved.
