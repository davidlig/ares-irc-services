# Domain Events & Subscribers

Use this skill when creating domain events, event subscribers, or understanding the event-driven architecture.

---

## Domain Events Overview

Domain events represent something that HAS HAPPENED in the domain. They are read-only and immutable.

### Structure

```php
// src/Domain/NickServ/Event/NickDropEvent.php
final readonly class NickDropEvent
{
    public function __construct(
        public int $nickId,
        public string $nickname,
        public string $nicknameLower,
        public string $reason,  // 'manual', 'expired', 'purge'
    ) {}
}
```

### Event Types

| Type | Location | Example |
|------|----------|---------|
| **Core IRC events** | `Domain/IRC/Event/` | `MessageReceivedEvent`, `UserJoinedNetworkEvent`, `NetworkBurstCompleteEvent` |
| **Service events** | `Domain/<Service>/Event/` | `NickDropEvent`, `ChannelRegisteredEvent`, `NickIdentifiedEvent` |

---

## Subscriber Pattern

Subscribers react to domain events. They live in `Infrastructure/` and implement `EventSubscriberInterface`.

### Subscriber Template

```php
// src/Infrastructure/NickServ/Subscriber/NickHistorySubscriber.php
final readonly class NickHistorySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private NickHistoryService $historyService,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NickDropEvent::class => 'onNickDrop',
        ];
    }

    public function onNickDrop(NickDropEvent $event): void
    {
        $this->historyService->recordDrop(
            nickId: $event->nickId,
            nickname: $event->nickname,
            reason: $event->reason,
        );
    }
}
```

### Registration in services.yaml

```yaml
App\Infrastructure\NickServ\Subscriber\NickHistorySubscriber:
    tags: ['kernel.event_subscriber']
```

---

## When to Use Events vs Direct Calls

| Use Events When | Use Direct Calls When |
|----------------|----------------------|
| Multiple consumers need the notification | Only one consumer |
| Cross-context communication (Core → Service) | Same context/bounded context |
| Loose coupling required | Tight coupling is acceptable |
| Side effects (logging, audit, cleanup) | Core business flow |

**Example**: `DROP` command dispatches `NickDropEvent` instead of calling every cleanup service directly. This decouples the command from cleanup logic.

---

## Existing Subscribers (by Service)

### NickServ (7 subscribers)
- `NickServCommandListener` — Routes PRIVMSG to `NickServService`
- `NickProtectionSubscriber` — Enforces nick protection on connect
- `ForbiddenNickEnforceSubscriber` — Blocks forbidden nicknames
- `ForbiddenVhostCleanupSubscriber` — Cleans up on nick drop
- `VhostClearOnDeidentifySubscriber` — Clears vhost on deidentify
- `NickHistorySubscriber` — Records nick history events
- `NickHistoryNickDropSubscriber` — Cascade cleanup on nick drop

### ChanServ (19 subscribers)
- `ChanServCommandListener` — Routes PRIVMSG to `ChanServService`
- `ChanServAccessChannelDropSubscriber` — Cascade access on channel drop
- `ChanServAkickEnforceSubscriber` — Enforces AKICK on join
- `ChanServChannelForbiddenSubscriber` — Handles forbidden channel state
- `ChanServChannelRankSubscriber` — Syncs channel member ranks
- `ChanServChannelUnforbiddenSubscriber` — Restores after unforbid
- `ChanServEntryMsgSubscriber` — Sends entry message on join
- `ChanServForbiddenChannelBurstSubscriber` — Registers forbidden on burst
- `ChanServForbiddenChannelJoinSubscriber` — Blocks joins to forbidden channels
- `ChanServHistoryChannelDropSubscriber` — Cascade history on channel drop
- `ChanServHistorySubscriber` — Records channel history
- `ChanServMlockEnforceSubscriber` — Enforces mode locks
- `ChanServNickDropCleanupSubscriber` — Mixed cleanup on nick drop
- `ChanServNojoinEnforceSubscriber` — Enforces NOJOIN setting
- `ChanServPermanentChannelSubscriber` — Marks channels as permanent
- `ChanServRejoinSubscriber` — Rejoins bot after kick
- `ChanServTopicApplySubscriber` — Applies stored topic
- `ChanServTopicSyncSubscriber` — Syncs topic changes
- `ChanServUnsuspendSubscriber` — Handles unsuspension

### MemoServ (5 subscribers)
- `MemoServCommandListener`
- `MemoServNickDropCleanupSubscriber`
- `MemoServChannelDropCleanupSubscriber`
- `MemoServNickIdentifiedNoticeSubscriber`
- `MemoServPendingChannelNoticeSubscriber`

### OperServ (7 subscribers)
- `OperServCommandListener`
- `MotdOnConnectSubscriber`
- `OperServNickDropCleanupSubscriber`
- `OperServGlineEnforceSubscriber`
- `OperRoleModesSubscriber`
- `OperRoleModesDeidentifiedSubscriber`
- `OperRoleForcedVhostSubscriber`

---

## Priority Convention

In `getSubscribedEvents()`, typical priorities:

| Priority | When |
|----------|------|
| 100 | High — must run first (e.g., bot introduction on burst) |
| 0 | Default |
| -100 | Low — runs last (e.g., cleanup, logging) |

---

## Related Skills

- `.agents/architecture/drop-cleanup.md` — Ref cleanup on DropEvent specifically
- `.agents/services/commands.md` — How commands dispatch events
- `.agents/testing/testing-patterns.md` — How to test subscribers
