# Core IRCd vs Services & Bots Architecture

Use this skill when implementing new services, bots, or modifying Core/Services boundaries.

## Overview

The codebase is split into **Core** (IRCd simulation) and **Services** (NickServ, ChanServ, MemoServ, etc.). They MUST remain fully decoupled.

```
┌─────────────────────────────────────────────────────────────────────┐
│                         SERVICES LAYER                              │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐                 │
│  │   NickServ   │ │   ChanServ   │ │   MemoServ   │ ...             │
│  │(Domain/Application)            │(Domain/Application)              │
│  └──────┬───────┘ └──────┬───────┘ └──────┬───────┘                 │
│         │                │                │                          │
│         └────────────────┼────────────────┘                          │
│                          │                                           │
│                    ┌─────▼─────┐                                     │
│                    │   PORTS   │ (Interfaces + DTOs)                 │
│                    └─────┬─────┘                                     │
└──────────────────────────┼───────────────────────────────────────────┘
                           │
┌──────────────────────────┼───────────────────────────────────────────┐
│                      CORE LAYER                                       │
│                    ┌─────▼─────┐                                      │
│                    │ Adapters  │ (Infrastructure/IRC)                 │
│                    └─────┬─────┘                                      │
│         ┌──────────────┬─┴──────────────┐                             │
│         │              │                │                              │
│    ┌────▼────┐    ┌────▼────┐    ┌────▼────┐                        │
│    │Network │    │ Channel │    │ Protocol│                        │
│    │ User   │    │ Repo    │    │ Handler │ ...                    │
│    └────────┘    └─────────┘    └─────────┘                         │
└─────────────────────────────────────────────────────────────────────┘
```

## Core (IRCd Scope)

**Location**: `Domain/IRC`, `Application/IRC`, `Infrastructure/IRC`

**Responsibilities**:
- Servers: link/unlink, SID management
- Users: connect, quit, nick change, modes, account
- Channels: join, part, modes, topics
- Protocol: parse wire format, dispatch domain events

**Rules**:
- Core MUST NOT import anything from Services (`Domain\NickServ`, `Application\ChanServ`, etc.)
- Core exposes **Ports** (interfaces) that Services use
- Core dispatches domain events (`NetworkBurstCompleteEvent`, `UserQuitEvent`, etc.)

## Ports (Core ↔ Services Contract)

**Location**: `Application/Port/`

Ports are interfaces implemented by Core, consumed by Services:

| Port | Method | Purpose |
|------|--------|---------|
| `NetworkUserLookupPort` | `findByUid(string $uid): ?SenderView` | Resolve connected user |
| `SendNoticePort` | `sendNotice(string $targetUid, string $message): void` | Send NOTICE |
| `ChannelLookupPort` | `findByChannelName(string $name): ?ChannelView` | Channel info |
| `ChannelServiceActionsPort` | `setChannelModes(...)`, `joinChannelAsService(...)` | ChanServ actions |

### DTOs Crossing Boundaries

DTOs MUST be `readonly` and contain only data needed by Services:

```php
// GOOD: DTO in Application/Port/
readonly class SenderView {
    public function __construct(
        public string $uid,
        public string $nick,
        public string $ident,
        public string $hostname,
        public string $cloakedHost,
        public string $ipBase64,
        public string $displayHost,
    ) {}
}

// BAD: Passing Domain entity to Services
function dispatch(NetworkUser $user)  // WRONG - Core entity in Service
function dispatch(SenderView $sender)  // CORRECT - DTO crossing boundary
```

## Services (NickServ, ChanServ, MemoServ)

**Location**: `Domain/<ServiceName>/`, `Application/<ServiceName>/`, `Infrastructure/<ServiceName>/`

**Domain Layer**:
- Entities: `RegisteredNick`, `RegisteredChannel`, `Memo`, etc.
- Repository Interfaces
- Value Objects
- Domain Events (service-specific, not Core events)
- Domain Exceptions

**Application Layer**:
- `XxxService.php`: Main dispatcher (e.g., `NickServService::dispatch()`)
- `Command/XxxContext.php`: Readonly context for handlers
- `Command/XxxCommandRegistry.php`: Tagged service collector
- `Command/XxxCommandInterface.php`: Command contract
- `Command/XxxNotifierInterface.php`: Interface for Bot to implement
- Command Handlers: One per command

**Infrastructure Layer**:
- `Bot/XxxBot.php`: Network entry point
- Doctrine Repositories
- Subscribers (listening to Core domain events if needed)

### Forbidden Imports in Application Layer

```php
// WRONG - Core entities in Service
use App\Domain\IRC\NetworkUser;
use App\Domain\IRC\Event\MessageReceivedEvent;

// CORRECT - Use Ports and DTOs
use App\Application\Port\SenderView;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SendNoticePort;
```

## Bots (modular)

**Purpose**: Bridge between network and Service business logic.

**Location**: `Infrastructure/<ServiceName>/Bot/XxxBot.php`

### Bot Responsibilities (minimal)

1. Implement `ServiceCommandListenerInterface` (receive commands from Gateway)
2. On `NetworkBurstCompleteEvent`: introduce the pseudo-client
3. Implement `XxxNotifierInterface` (send NOTICE/PRIVMSG via port)
4. Delegate ALL business logic to the Service

### Bot Template

```php
final readonly class XxxBot implements
    XxxNotifierInterface,
    ServiceCommandListenerInterface,
    EventSubscriberInterface
{
    public function __construct(
        private readonly ActiveConnectionHolder $connectionHolder,
        private readonly NetworkUserLookupPort $userLookup,
        private readonly SendNoticePort $sendNotice,
        // ... other ports
    ) {}

    // ServiceCommandListenerInterface
    public function getServiceName(): string { return 'XxxServ'; }
    public function getServiceUid(): ?string { return $this->xxxServUid; }
    public function onCommand(string $senderUid, string $text): void {
        $sender = $this->userLookup->findByUid($senderUid);
        if (null === $sender) { return; }
        $this->xxxService->dispatch($text, $sender);  // Delegate to Service
    }

    // EventSubscriberInterface (burst introduction)
    public static function getSubscribedEvents(): array {
        return [NetworkBurstCompleteEvent::class => ['onBurstComplete', 100]];
    }
    public function onBurstComplete(NetworkBurstCompleteEvent $event): void {
        $module = $this->connectionHolder->getProtocolModule();
        $line = $module->getIntroductionFormatter()->formatIntroduction(...);
        $event->connection->writeLine($line);
    }

    // XxxNotifierInterface
    public function sendNotice(string $targetUid, string $message): void {
        $this->sendNotice->sendNotice($targetUid, $message);
    }
}
```

### Service Command Gateway

The **single entry point** for PRIVMSG to services:

```php
// Infrastructure/IRC/ServiceBridge/ServiceCommandGateway.php
final class ServiceCommandGateway implements EventSubscriberInterface {
    public function onMessage(MessageReceivedEvent $event): void {
        if ('PRIVMSG' !== $event->message->command) { return; }
        $target = strtolower($event->message->params[0]);
        $listener = $this->listeners[$target] ?? null;
        $listener?->onCommand($sourceId, $event->message->trailing);
    }
}
```

Bots register via `ServiceCommandListenerInterface` (tagged service). **Never** subscribe to `MessageReceivedEvent` directly in a Service.

## Quick Reference

| Layer | Imports | Exports |
|-------|---------|---------|
| Core (`Domain/IRC`) | Nothing from Services | Domain events, Ports |
| Services (`Application/NickServ`) | Ports, DTOs | Service classes |
| Bots (`Infrastructure/NickServ/Bot`) | Core events (burst), Ports | Implements Notifier |

## Files Affected

- `src/Application/Port/*.php` (interfaces, DTOs)
- `src/Domain/<ServiceName>/`
- `src/Application/<ServiceName>/`
- `src/Infrastructure/<ServiceName>/`
- `src/Infrastructure/IRC/ServiceBridge/ServiceCommandGateway.php`
- `config/services.yaml` (DI configuration)

## Adding a New Service: Checklist

When adding a new service (e.g., `BotServ`), update these locations:

1. **Environment variables**: Add to `.env`:
   - `<SERVICE>_UID=<value>` (e.g., `BOTSERV_UID=002GGGGGG`)

2. **Services config** (`config/services.yaml`):
   - Add `<service>.uid: '%env(<SERVICE>_UID)%'` under parameters
   - Add service UID to `CtcpHandler` `$serviceUidMap`:
     ```yaml
     $serviceUidMap:
         nickserv: '%nickserv.uid%'
         chanserv: '%chanserv.uid%'
         memoserv: '%memoserv.uid%'
         operserv: '%operserv.uid%'
         botserv: '%botserv.uid%'  # <-- add new service here
     ```

3. **Bot class**: Implement `ServiceCommandListenerInterface` with:
   - `getServiceName(): string` → returns `'BotServ'`
   - `getServiceUid(): ?string` → returns `$this->botServUid`

4. **Service registration**: Tag the bot with `app.service_command_listener`