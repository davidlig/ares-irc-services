# Ares IRC Services

A modular, protocol-agnostic IRC services daemon (Core + NickServ + ChanServ + MemoServ + OperServ) built with **PHP 8.4** and **Symfony 7.4**.

> **Note:** The services are **not complete** and are **under active development**. Functionality and APIs may change.

Supports multiple IRC daemon backends out of the box:

| IRCD | Protocol driver |
|---|---|
| [UnrealIRCd](https://www.unrealircd.org/) 6.2.2 | `unreal` |
| [InspIRCd](https://www.inspircd.org/) 4.9.0 | `inspircd` |

**Try the bots** on our test server:

- **Plain:** [irc://irc.davidlig.net:6667](irc://irc.davidlig.net:6667)
- **SSL:** [ircs://irc.davidlig.net:6697](ircs://irc.davidlig.net:6697)

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
NICKSERV_UID=001AAAAAA                 # = <IRC_SERVER_SID> + suffix (must be unique on the network)
CHANSERV_UID=001BBBBBB
MEMOSERV_UID=001CCCCCC
OPERSERV_UID=001EEEEEE
```

> **CRITICAL:** The first 3 characters of every UID **MUST** match `IRC_SERVER_SID`.
> If you change the SID (e.g. from `001` for UnrealIRCd to `0A0` for InspIRCd),
> you **must** also update all four UIDs in `.env.local`. A mismatch causes the
> IRCd to reject the link with no clear error message.
>
> Example for InspIRCd: `IRC_SERVER_SID=0A0` → `NICKSERV_UID=0A0AAAAAA`, etc.

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

> **InspIRCd note:** Ares uses the SpanTree protocol v4 (1206). The handshake sends
> `CAPAB START 1206` followed by a minimal `CAPAB CAPABILITIES :CASEMAPPING=ascii`
> (InspIRCd skips module/mode comparison when the
> remote server omits those CAPAB fields). No `CHALLENGE` is sent, so the link
> uses **plaintext password** authentication. Set `IRC_SERVER_SID` to a 3-character
> alphanumeric ID (e.g. `0A0`) and ensure all UIDs in `.env.local` use the same
> prefix (e.g. `NICKSERV_UID=0A0AAAAAA`).

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
| `CHANSERV_HISTORY_VIEW_LIMIT` | Max history entries per page in VIEW | `40` |

After editing `.env.local`:

```bash
make restart
```

---

## Troubleshooting

### Link closes immediately after handshake

**Most common cause:** UID prefix does not match `IRC_SERVER_SID`.

InspIRCd validates that the first 3 characters of every UID match the SID of the server that introduces them. If `IRC_SERVER_SID=0A0` but `NICKSERV_UID=002AAAAAA`, InspIRCd rejects the UID lines and closes the link.

**Fix:** Ensure all UIDs in `.env.local` start with the same 3-character prefix as `IRC_SERVER_SID`:

```dotenv
IRC_SERVER_SID=0A0
NICKSERV_UID=0A0AAAAAA
CHANSERV_UID=0A0BBBBBB
MEMOSERV_UID=0A0CCCCCC
OPERSERV_UID=0A0EEEEEE
```

The `irc:connect` command validates this at startup and will refuse to connect if there is a mismatch.

### No error message shown on disconnect

When InspIRCd rejects a link, it sends an `ERROR :<reason>` message before closing the connection. Ares logs this reason at `CRITICAL` level. Check `var/log/irc-*.log` for the actual rejection reason (e.g. "Bogus UUID", "Invalid password", "CAPAB negotiation failed").

### CAPAB lines appear garbled in logs

If long `CAPAB` lines appear split or corrupted in `var/log/irc-*.log`, this was a buffering issue in the socket read layer (fixed: the connection now buffers incoming data until a complete line is received).

---

## License

This project is licensed under the GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later). See the [LICENSE](LICENSE) file for details.