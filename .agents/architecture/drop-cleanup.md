# Data Integrity — Ref Cleanup on DropEvent

Use this skill when storing references to registered entities (nicks, channels) to ensure cleanup on drop.

---

## The Rule

Any feature that stores a `nickId` or `channelId` reference MUST define cleanup behavior when the referenced entity is dropped. Without this, orphaned records accumulate.

---

## Cleanup Strategies

| Strategy | Behavior | When to Use |
|----------|----------|-------------|
| **CASCADE DELETE** | Remove ALL referencing entries | Most common. No reason to keep data without the entity. |
| **SET NULL** | Keep entry, null the FK | Audit trails, AKICK creator, history records |
| **TRANSFER** | Reassign to another entity | Channel founder → successor transfer |

---

## Events That Trigger Cleanup

| Entity Dropped | Event | Event Class |
|---------------|-------|-------------|
| Nickname | `NickDropEvent` | `App\Domain\NickServ\Event\NickDropEvent` |
| Channel | `ChannelDropEvent` | `App\Domain\ChanServ\Event\ChannelDropEvent` |

### NickDropEvent

```php
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

### ChannelDropEvent

```php
final readonly class ChannelDropEvent
{
    public function __construct(
        public int $channelId,
        public string $channelName,
        public string $reason,
    ) {}
}
```

---

## Implementation Checklist

For every new feature that stores a `nickId` or `channelId`:

1. ✅ Decide the cleanup strategy (CASCADE DELETE / SET NULL / TRANSFER)
2. ✅ Add cleanup method to the repository interface
3. ✅ Implement in the Doctrine repository
4. ✅ Create a subscriber implementing `EventSubscriberInterface`
5. ✅ Register in `config/services.yaml` with `kernel.event_subscriber` tag
6. ✅ Write unit tests for the subscriber
7. ✅ Write integration tests for the repository cleanup method

---

## Existing Cleanup Subscribers (Reference)

### NickDropEvent Cleanup

| Subscriber | Strategy | Repository Method |
|-----------|----------|-------------------|
| `MemoServNickDropCleanupSubscriber` | CASCADE DELETE | `deleteByNickId(int)` |
| `ChanServNickDropCleanupSubscriber` | Mixed: DELETE access + SET NULL akick creator + TRANSFER founder | Multiple methods |
| `OperServNickDropCleanupSubscriber` | CASCADE DELETE IRCOP entry | `deleteByNickId(int)` |
| `NickHistoryNickDropSubscriber` | CASCADE DELETE history | `deleteByNickId(int)` |
| `ForbiddenVhostCleanupSubscriber` | CASCADE DELETE forbidden vhost | `deleteByNickId(int)` |

### ChannelDropEvent Cleanup

| Subscriber | Strategy | Repository Method |
|-----------|----------|-------------------|
| `MemoServChannelDropCleanupSubscriber` | CASCADE DELETE | `deleteByChannelId(int)` |
| `ChanServAccessChannelDropSubscriber` | CASCADE DELETE access entries | `deleteByChannelId(string)` |
| `ChanServHistoryChannelDropSubscriber` | CASCADE DELETE history | `deleteByChannelId(int)` |

---

## Subscriber Template

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\MyService\Subscriber;

use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\MyService\Repository\MyEntityRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class MyServiceNickDropCleanupSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MyEntityRepositoryInterface $repository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NickDropEvent::class => 'onNickDrop',
        ];
    }

    public function onNickDrop(NickDropEvent $event): void
    {
        $this->repository->deleteByNickId($event->nickId);
    }
}
```

### Repository Interface Addition

```php
interface MyEntityRepositoryInterface
{
    public function deleteByNickId(int $nickId): void;
}
```

### Doctrine Implementation

```php
public function deleteByNickId(int $nickId): void
{
    $this->em->createQueryBuilder()
        ->delete(MyEntity::class, 'e')
        ->where('e.nickId = :nickId')
        ->setParameter('nickId', $nickId)
        ->getQuery()
        ->execute();
}
```

---

## When Implementing DROP Commands

Commands that manually drop entities MUST emit the corresponding DropEvent:

```php
// NickServ DROP command
$this->nickRepository->delete($registered);
$this->eventDispatcher->dispatch(new NickDropEvent(
    nickId: $registered->getId(),
    nickname: $registered->getNickname(),
    nicknameLower: strtolower($registered->getNickname()),
    reason: 'manual',
));

// ChanServ DROP command
$this->channelRepository->delete($channel);
$this->eventDispatcher->dispatch(new ChannelDropEvent(
    channelId: $channel->getId(),
    channelName: $channel->getChannelName(),
    reason: 'manual',
));
```

Services MUST NOT delete entities directly without emitting the corresponding DropEvent.

---

## Related Skills

- `.agents/architecture/events.md` — Event subscriber patterns
- `.agents/database/README.md` — Doctrine repository implementations
- `.agents/services/commands.md` — How commands dispatch events
