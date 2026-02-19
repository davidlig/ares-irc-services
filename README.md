# Ares IRC Services

A modular, protocol-agnostic IRC services daemon built with **PHP 8.4** and **Symfony 7.4**, following Clean Architecture and Domain-Driven Design principles.

Supports multiple IRC daemon backends out of the box:

| IRCD | Protocol driver |
|---|---|
| [UnrealIRCd](https://www.unrealircd.org/) ≥ 4.x | `unreal` |
| [InspIRCd](https://www.inspircd.org/) ≥ 1.2 | `inspircd` |

---

## Requirements

| Dependency | Minimum version |
|---|---|
| PHP | 8.4 |
| Composer | 2.x |
| Symfony CLI *(optional, recommended)* | 5.x |

PHP extensions required: `ext-ctype`, `ext-iconv`, `ext-sockets`

---

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/your-org/ares-irc-services.git
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
IRC_UNREAL_SID=001                     # UnrealIRCd only — unique 3-digit numeric SID (e.g. 001)
IRC_INSPIRCD_SID=A0A                   # InspIRCd only   — unique 3-char alphanumeric SID (e.g. A0A)
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

Connect to an IRCD using the configured environment values:

```bash
php bin/console irc:connect \
    "$IRC_SERVER_NAME" \
    "$IRC_IRCD_HOST" \
    "$IRC_IRCD_PORT" \
    "$IRC_LINK_PASSWORD" \
    "$IRC_DESCRIPTION" \
    --protocol="$IRC_PROTOCOL"
```

With TLS:

```bash
php bin/console irc:connect services.example.com irc.example.com 6697 secret \
    "Ares IRC Services" --protocol=unreal --tls
```

All arguments at a glance:

| Argument / Option | Description |
|---|---|
| `server-name` | FQDN Ares presents to the IRCD |
| `host` | IRCD hostname or IP |
| `port` | IRCD server-link port |
| `password` | Link password |
| `description` | Text shown in `/MAP` and `/LINKS` |
| `--protocol` / `-p` | `unreal` or `inspircd` (default: `unreal`) |
| `--tls` | Wrap the connection in TLS |

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
