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
| `SendNoticePort` | `sendNotice(...)`, `sendNoticeToChannel(...)` | Send NOTICE to user or channel |
| `ChannelLookupPort` | `findByChannelName(string)` | Get channel info → `ChannelView` |
| `ChannelServiceActionsPort` | Multiple | Set modes, join, topic |
| `ProtocolModuleInterface` | `getHandler()`, `getServiceActions()`, etc. | Active IRCd protocol module |
| `ProtocolServiceActionsInterface` | Multiple | Wire-level actions (`introduceService`, `setUserVhost`, etc.) |
| `LocalUserModeSyncInterface` | `syncLocalUserMode(...)` | Sync local user modes (+r/-r) |
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

## SOLID & Hexagonal Review Rules (MANDATORY before declaring work done)

Every new or modified design must pass this checklist. A failing item is a design violation: refactor before finishing, or justify explicitly in the reply why the shape is the correct tradeoff.

### Single Responsibility (SRP)
- One class = one reason to change. If a class name says X but it also does Y (e.g. a mode applier applying operclasses, a session coordinator owning a separate projection state machine), extract the second responsibility into its own collaborator.
- State machines for separate concerns (e.g. HEL/reconciliation vs OCLG view) live in separate classes; the orchestrator delegates.

### Open/Closed & Interface Segregation (OCP/ISP)
- Extend behavior by adding NEW optional ports (see `.agents/protocol/README.md`), never by growing shared interfaces. Consumers feature-detect with `instanceof`.
- No class may be forced to implement methods it cannot perform (no no-op implementations of protocol capabilities).

### Liskov Substitution (LSP)
- No protocol implementation may change the meaning of a shared contract; absent capability = the optional port is not implemented, not an empty stub.

### Dependency Inversion (DIP)
- Application depends on `Application/Port/` + Domain ONLY. Application code MUST NOT import `Infrastructure\*` namespaces.
- Infrastructure may implement ports and depend on external libraries (symfony/lock, Doctrine), but never leak concrete classes into Application or Domain.
- UI (CLI/Bots) may hold Infrastructure interface references only when an Application-layer port does not exist (established pattern: `ConsumerProcessManagerInterface`); prefer ports for anything new.

### Hexagonal (Ports & Adapters)
- Each protocol (`src/Infrastructure/IRC/Protocol/<Name>/`) is an adapter: wire types (`UdbFrame`, raw lines, protocol enums) MUST NOT cross the Port boundary into Application/Domain.
- Adapter-internal collaborators (e.g. `UdbOclgView`, `UdbSessionLock`) stay inside the adapter namespace; they are implementation details, not ports.
- Domain events are the inbound direction (adapter → services); ports are the outbound direction (services → network). Never route Application → adapter internals directly.

### Self-check (run before replying "done")
1. Did I add a method/property to a shared contract? → revert; new port instead.
2. Does any new class hold two unrelated state machines/data? → extract collaborator.
3. Does Application import Infrastructure? → move the port.
4. Do new classes have `#[CoversClass]` tests and full branch coverage?

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
