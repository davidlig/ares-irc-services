# Core IRCd vs Services & Bots Architecture

Use this skill when implementing new services, bots, or modifying Core/Services boundaries.

## Overview

The codebase has **5 bounded contexts**: IRC (Core), NickServ, ChanServ, MemoServ, OperServ.

```
┌─────────────────────────────────────────────────────────────────────┐
│                         SERVICES (4 contexts)                        │
│  NickServ (reg/identify/suspend)    ChanServ (channels/access/akick)│
│  MemoServ (memos/ignore)            OperServ (gline/kill/ircop)     │
│                          │                                           │
│                    ┌─────▼─────┐                                     │
│                    │   PORTS   │  Application/Port/ (28 interfaces)  │
│                    └─────┬─────┘                                     │
└──────────────────────────┼───────────────────────────────────────────┘
                           │
┌──────────────────────────┼───────────────────────────────────────────┐
│                      CORE — IRC (1 context)                           │
│  Connections, Users, Channels, Protocol parsing, Domain events       │
└─────────────────────────────────────────────────────────────────────┘
```

## Core (IRCd Scope)

**Location**: `Domain/IRC`, `Application/IRC`, `Infrastructure/IRC`

- Core MUST NOT import anything from Services
- Core exposes **Ports** (interfaces in `Application/Port/`) that Services consume
- Core dispatches domain events (`NetworkBurstCompleteEvent`, `UserQuitEvent`, etc.)

## Ports (Core ↔ Services Contract)

**Location**: `Application/Port/` — 28 interfaces + DTOs

Key ports:

| Port | Purpose |
|------|---------|
| `NetworkUserLookupPort` | Resolve connected user → `SenderView` |
| `SendNoticePort` | Send NOTICE to user |
| `ChannelLookupPort` | Get channel info → `ChannelView` |
| `ChannelServiceActionsPort` | Set modes, join, topic for ChanServ |
| `ProtocolModuleInterface` | Active IRCd protocol module |
| `ServiceCommandListenerInterface` | Bot receives commands from Gateway |

DTOs crossing the boundary must be `readonly`:
```php
// CORRECT: DTO
function dispatch(SenderView $sender): void {}
// WRONG: Core entity
function dispatch(NetworkUser $user): void {}
```

## Services

Each service follows the same structure:
```
Domain/<Service>/          — Entities, Repository Interfaces, VOs, Events
Application/<Service>/     — Service dispatcher, Commands, Handlers, Security
Infrastructure/<Service>/  — Bot, Doctrine Repositories, Subscribers
```

### Forbidden in Application Layer

```php
// WRONG
use App\Domain\IRC\NetworkUser;
use App\Domain\IRC\Event\MessageReceivedEvent;

// CORRECT
use App\Application\Port\SenderView;
use App\Application\Port\NetworkUserLookupPort;
```

## Bots

**Purpose**: Bridge between network and Service business logic.

### Bot responsibilities (minimal):
1. Implement `ServiceCommandListenerInterface` (receive PRIVMSG from Gateway)
2. On `NetworkBurstCompleteEvent`: introduce the pseudo-client
3. Implement `XxxNotifierInterface` (send NOTICE/PRIVMSG via ports)
4. Delegate ALL business logic to the Service

### Rules:
- **Never** put business logic in Bots
- **Never** subscribe to `MessageReceivedEvent` directly — use `ServiceCommandGateway`
- **Never** import Core entities in Bot

## File Checklist for New Service

1. **Environment variables** (`.env`): `<SERVICE>_UID`, `<SERVICE>_NICK`
2. **Parameters** (`config/services.yaml`): service nick, UID, ident, realname
3. **CtcpHandler** `$serviceUidMap`: add new service UID
4. **Bot class**: implement `ServiceCommandListenerInterface`
5. **Bot registration**: tag with `app.service_command_listener`

Full checklist: `.agents/services/bots.md`.

## Related Skills

- `.agents/architecture/README.md` — Full architecture map
- `.agents/services/commands.md` — Command handler structure
- `.agents/services/bots.md` — New bot implementation
