# Ares IRC Services

A modular, protocol-agnostic IRC services daemon (Core + NickServ + ChanServ + MemoServ + OperServ) built with **PHP 8.4** and **Symfony 7.4**.

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

When the connection is established and the initial network burst completes, the Core IRC layer syncs users and channels and then introduces the service bots (NickServ, ChanServ, MemoServ, OperServ) automatically. From that point, users on the network can interact with the services using normal IRC commands (e.g. `/msg NickServ ...`, `/msg ChanServ ...`, `/msg MemoServ ...`, `/msg OperServ ...`), without any extra manual setup.

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
# Build the image
make build

# Start services (creates .env.local if missing)
make up

# Follow logs
make logs
```

### Configuration

Edit `.env.local` and configure your IRCD connection:

| Variable | Description | Example |
|----------|-------------|---------|
| `IRC_IRCD_HOST` | IRCD hostname or IP | `127.0.0.1` |
| `IRC_IRCD_PORT` | IRCD server-link port | `7029` |
| `IRC_LINK_PASSWORD` | Link password from IRCD config | `your-secret-password` |
| `IRC_SERVER_NAME` | FQDN Ares presents to IRCD | `services.example.com` |
| `IRC_PROTOCOL` | Protocol driver | `unreal` or `inspircd` |
| `CHANSERV_HISTORY_RETENTION_DAYS` | Days to retain channel history (0 = forever) | `30` |

After editing `.env.local`:

```bash
make restart
```

---

## ChanServ HISTORY

ChanServ records an audit trail of sensitive channel operations. IRCop users with the `chanserv.history` permission can view, add, delete, and clear history entries.

### Automatically recorded actions

| Action | Trigger |
|--------|---------|
| `SET_FOUNDER` | Founder change via SET FOUNDER |
| `SET_SUCCESSOR` / `CLEAR_SUCCESSOR` | Successor change via SET SUCCESSOR |
| `ACCESS_ADD` / `ACCESS_DEL` | Access list modifications via ACCESS ADD/DEL, DELACCESS |
| `AKICK_ADD` / `AKICK_DEL` | AKICK list modifications via AKICK ADD/DEL |
| `SUSPEND` / `UNSUSPEND` | Channel suspend/unsuspend |
| `ADD` / `DEL` / `CLEAR` | Manual history entries by IRCop users |

### Syntax

```
HISTORY <channel> VIEW [page]   — View history entries (paginated)
HISTORY <channel> ADD <text>     — Add a manual note to the channel history
HISTORY <channel> DEL <id>       — Delete a specific history entry
HISTORY <channel> CLEAR          — Remove all history entries for a channel
```

Retention is controlled by the `CHANSERV_HISTORY_RETENTION_DAYS` environment variable (default: 30 days; set to `0` to keep entries forever).

---

## License

Proprietary — All rights reserved.