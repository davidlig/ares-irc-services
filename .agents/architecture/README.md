# Architecture — Project Structure & Bounded Contexts

Use this skill to understand the project's architecture: bounded contexts, layers, dependency rules, and the Port boundary.

---

## Bounded Contexts Map

The project has **5 bounded contexts**:

```
┌──────────────────────────────────────────────────────────────────┐
│                         IRC (Core)                                │
│  Simulates IRCd: connections, users, channels, protocol parsing  │
│  Domain/IRC  +  Application/IRC  +  Infrastructure/IRC            │
└───────────────────────────┬──────────────────────────────────────┘
                            │
                    ┌───────▼───────┐
                    │     PORTS     │  Application/Port/
                    │  Interfaces   │  28 interfaces + DTOs
                    │    + DTOs     │  The ONLY boundary
                    └───┬───┬───┬───┘
            ┌───────────┘   │   └───────────┐
            ▼               ▼               ▼
    ┌──────────────┐ ┌──────────────┐ ┌──────────────┐
    │   NickServ   │ │   ChanServ   │ │   MemoServ   │
    │  Registration│ │  Channel mgmt│ │  Memo system │
    │  Identify    │ │  Access/Op   │ │  Send/Read   │
    │  Suspend     │ │  AKick/Mlock │ │  Ignore      │
    └──────────────┘ └──────────────┘ └──────────────┘
            │
            ▼
    ┌──────────────┐
    │   OperServ   │
    │  Gline/Kill  │
    │  IRCop/Role  │
    │  MOTD/Global │
    └──────────────┘
```

Each service follows the same layered structure:
```
Domain/<Service>/
├── Entity/         — Business entities with behavior
├── Event/          — Domain events (service-specific)
├── Exception/      — Domain exceptions
├── Repository/     — Repository INTERFACES (no implementations)
├── Service/        — Domain services (e.g., PasswordHasherInterface)
└── ValueObject/    — Immutable value objects

Application/<Service>/
├── Command/
│   ├── Handler/    — Command handlers (implements XxxCommandInterface)
│   ├── XxxCommandInterface.php
│   ├── XxxCommandRegistry.php
│   ├── XxxContext.php
│   └── XxxNotifierInterface.php
├── Maintenance/    — Scheduled tasks
├── Security/       — Permission constants + IRCop permission providers
└── Service/        — Application services

Infrastructure/<Service>/
├── Bot/            — Pseudo-client bot (network entry point)
├── Doctrine/       — Repository implementations
├── Security/Voter/ — Custom voters
└── Subscriber/     — Event subscribers (event → action bridges)
```

---

## Four Architectural Layers

| Layer | Location | Depends On | Framework |
|-------|----------|------------|-----------|
| **Domain** | `src/Domain/` | Nothing (pure PHP) | None |
| **Application** | `src/Application/` | Domain only | Some Symfony interfaces |
| **Infrastructure** | `src/Infrastructure/` | Domain + Application | Symfony, Doctrine |
| **UI** | `src/UI/` | Application | Symfony console |

### Dependency Rule

```
UI → Application → Domain
        ↑              ↑
Infrastructure ────────┘
```

- Domain knows NOTHING about Application or Infrastructure
- Application knows ONLY about Domain
- Infrastructure implements Domain and Application contracts
- UI calls Application use cases

---

## The Port Boundary (CRITICAL)

**Location**: `src/Application/Port/` — 28 interfaces + DTOs

Ports are the ONLY way Services talk to Core (IRC). Services MUST NOT import `Domain\IRC` entities directly.

### Key Ports

| Port | Method | Purpose |
|------|--------|---------|
| `NetworkUserLookupPort` | `findByUid(string)` | Resolve connected user → `SenderView` |
| `SendNoticePort` | `sendNotice(string, string)` | Send NOTICE to user |
| `ChannelLookupPort` | `findByChannelName(string)` | Get channel info → `ChannelView` |
| `ChannelServiceActionsPort` | Multiple | Set modes, join, topic |
| `ProtocolModuleInterface` | `getHandler()` etc. | Active IRCd protocol module |
| `ServiceCommandListenerInterface` | `onCommand(string, string)` | Bot receives commands from Gateway |

### DTOs Crossing Boundaries

DTOs MUST be `readonly` and contain only data:
```php
// CORRECT: DTO in Application/Port/
readonly class SenderView {
    public string $uid;
    public string $nick;
    // ...
}

// WRONG: Domain entity crossing boundary
function dispatch(NetworkUser $user)  // NO
function dispatch(SenderView $sender)  // YES
```

---

## Forbidden Patterns

### NEVER in Application Layer

```php
// WRONG
use App\Domain\IRC\NetworkUser;
use App\Domain\IRC\Event\MessageReceivedEvent;

// CORRECT
use App\Application\Port\SenderView;
use App\Application\Port\NetworkUserLookupPort;
```

### NEVER put business logic in Bots

```php
// WRONG: Logic in Bot
public function onCommand(string $uid, string $text): void {
    // validation, persistence, etc.
}

// CORRECT: Delegate to Service
public function onCommand(string $uid, string $text): void {
    $sender = $this->userLookup->findByUid($uid);
    $this->service->dispatch($text, $sender);
}
```

### NEVER subscribe to MessageReceivedEvent

Bots register via `ServiceCommandListenerInterface` (tagged service). The `ServiceCommandGateway` routes PRIVMSG to the right bot.

---

## Related Skills

- `.agents/architecture/entities.md` — Entity design patterns
- `.agents/architecture/events.md` — Domain events & subscribers
- `.agents/architecture/drop-cleanup.md` — Ref cleanup on DropEvent
- `.agents/database/README.md` — Doctrine ORM, migrations
- `.agents/services/README.md` — Core vs Services in detail
- `.agents/services/commands.md` — Command handler structure
