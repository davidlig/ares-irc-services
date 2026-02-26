# Ares IRC Services

A modular, protocol-agnostic IRC services daemon built with **PHP 8.4** and **Symfony 7.4**, following Clean Architecture and Domain-Driven Design principles.

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

## Architecture

The project follows **Clean Architecture** with strict layer separation:

```
src/
├── Domain/IRC/           Pure PHP — interfaces, value objects, domain events
│   ├── Connection/       ConnectionInterface, ConnectionFactoryInterface
│   ├── Protocol/         ProtocolHandlerInterface, ProtocolHandlerRegistryInterface
│   ├── Message/          IRCMessage (RFC 1459 parser/formatter)
│   ├── Server/           ServerLink value object
│   ├── ValueObject/      Hostname, Port, ServerName, LinkPassword
│   └── Event/            ConnectionEstablishedEvent, ConnectionLostEvent, MessageReceivedEvent
│
├── Application/IRC/      Use cases — depends only on Domain
│   ├── IRCClient         Connect → read loop → disconnect orchestrator
│   ├── IRCClientFactory  Wires connection + protocol into an IRCClient
│   └── Connect/          ConnectToServerCommand + ConnectToServerHandler
│
├── Infrastructure/IRC/   Adapters — implements Domain interfaces
│   ├── Connection/       SocketConnection (TCP/TLS), SocketConnectionFactory
│   └── Protocol/
│       ├── AbstractProtocolHandler   RFC 1459 parse/format baseline
│       ├── ProtocolHandlerRegistry   Tagged-iterator registry
│       ├── Unreal/       UnrealIRCdProtocolHandler
│       └── InspIRCd/     InspIRCdProtocolHandler
│
└── UI/CLI/               Symfony console commands
    └── ConnectCommand    bin/console irc:connect
```

### Adding a new protocol

1. Create `src/Infrastructure/IRC/Protocol/<Name>/<Name>ProtocolHandler.php` extending `AbstractProtocolHandler`.
2. Implement `getProtocolName()` and `performHandshake()`.
3. Register it in `config/services.yaml` with the `irc.protocol_handler` tag.

No other code changes are required — the registry picks it up automatically.

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

### Running tests

```bash
composer test          # once a test suite is configured
php vendor/bin/phpunit
```

---

## License

Proprietary — All rights reserved.
