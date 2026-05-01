# Testing Patterns by Layer & Type

Use this skill as a quick reference for common test patterns across different test types.

---

## Test Types & Patterns

### Entity Tests (Domain Layer)

```
tests/Domain/<Service>/Entity/<Entity>Test.php
```

Pattern: Test business behavior, not getters/setters.

```php
#[CoversClass(RegisteredNick::class)]
final class RegisteredNickTest extends TestCase
{
    #[Test]
    public function suspendChangesStatusToSuspended(): void
    {
        $nick = RegisteredNick::create(/* ... */);
        $nick->suspend('reason', new \DateTimeImmutable('+7 days'));

        self::assertTrue($nick->isSuspended());
        self::assertSame('reason', $nick->getSuspensionReason());
    }

    #[Test]
    public function changeEmailUpdatesEmailAndRecordsTimestamp(): void
    {
        $nick = RegisteredNick::create(/* ... */);
        $nick->changeEmail('new@email.com');

        self::assertSame('new@email.com', $nick->getEmail());
    }
}
```

### Value Object Tests (Domain Layer)

```
tests/Domain/<Service>/ValueObject/<VO>Test.php
```

Pattern: Test validation, equality, immutability.

```php
#[CoversClass(ChannelName::class)]
final class ChannelNameTest extends TestCase
{
    #[Test]
    public function throwsExceptionWhenNameDoesNotStartWithHash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ChannelName('noHash');
    }

    #[Test]
    public function equalsIgnoresCase(): void
    {
        $a = new ChannelName('#chan');
        $b = new ChannelName('#CHAN');
        self::assertTrue($a->equals($b));
    }
}
```

### Command Handler Tests (Application Layer)

```
tests/Application/<Service>/Command/Handler/<Command>Test.php
```

Pattern: Test interface contract + all execution branches. See `.agents/services/commands-testing.md` for full detail.

### Service Tests (Application Layer)

```
tests/Application/<Service>/<Service>Test.php
```

Pattern: Test dispatch orchestration, error handling, delegation.

```php
#[CoversClass(NickServService::class)]
final class NickServServiceTest extends TestCase
{
    #[Test]
    public function dispatchUnknownCommandReturnsError(): void
    {
        $registry = new NickServCommandRegistry([]);
        $service = new NickServService(/* ... */);
        $sender = $this->createSender();

        $service->dispatch('UNKNOWN arg', $sender);

        self::assertStringContainsString('Unknown command', $this->messages[0]);
    }
}
```

### Subscriber Tests (Infrastructure Layer)

```
tests/Infrastructure/<Service>/Subscriber/<Subscriber>Test.php
```

Pattern: Test that subscriber reacts to events correctly.

```php
#[CoversClass(MemoServNickDropCleanupSubscriber::class)]
final class MemoServNickDropCleanupSubscriberTest extends TestCase
{
    #[Test]
    public function onNickDropDeletesByNickId(): void
    {
        $repo = $this->createMock(MemoRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('deleteByNickId')
            ->with(42);

        $subscriber = new MemoServNickDropCleanupSubscriber($repo);
        $subscriber->onNickDrop(new NickDropEvent(
            nickId: 42,
            nickname: 'Test',
            nicknameLower: 'test',
            reason: 'manual',
        ));
    }

    #[Test]
    public function getSubscribedEventsReturnsNickDropEvent(): void
    {
        $subscriber = new MemoServNickDropCleanupSubscriber(
            $this->createStub(MemoRepositoryInterface::class),
        );

        $events = $subscriber::getSubscribedEvents();
        self::assertArrayHasKey(NickDropEvent::class, $events);
    }
}
```

### Voter Tests (Infrastructure Layer)

```
tests/Infrastructure/<Service>/Security/Voter/<Voter>Test.php
```

Pattern: Test supports() + voteOnAttribute() with different scenarios.

```php
#[CoversClass(NickServIdentifiedOwnerVoter::class)]
final class NickServIdentifiedOwnerVoterTest extends TestCase
{
    #[Test]
    public function supportsNickServIdentifiedOwnerAttribute(): void
    {
        $voter = new NickServIdentifiedOwnerVoter();
        $result = $this->callSupports($voter, NickServPermission::IDENTIFIED_OWNER, $context);
        self::assertTrue($result);
    }

    #[Test]
    public function voteOnAttributeWhenNotIdentifiedReturnsFalse(): void
    {
        // Setup sender with isIdentified = false
        self::assertFalse($voter->vote(/* ... */));
    }
}
```

### Bot Tests (Infrastructure Layer)

```
tests/Infrastructure/<Service>/Bot/<Bot>Test.php
```

Pattern: Test command delegation, burst introduction, notifier methods.

```php
#[CoversClass(NickServBot::class)]
final class NickServBotTest extends TestCase
{
    #[Test]
    public function onCommandDelegatesToService(): void
    {
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($this->createSender());

        $service = $this->createMock(NickServService::class);
        $service->expects(self::once())
            ->method('dispatch')
            ->with('REGISTER pass email', self::anything());

        $bot = new NickServBot(/* ... */, $service);
        $bot->onCommand('UID1', 'REGISTER pass email');
    }
}
```

### Registry/In-Memory Tests (Application Layer)

```
tests/Application/<Service>/<Registry>Test.php
```

Pattern: Test add/remove/check operations, pruning, TTL.

```php
#[CoversClass(RegisterThrottleRegistry::class)]
final class RegisterThrottleRegistryTest extends TestCase
{
    #[Test]
    public function isThrottledReturnsTrueWhenWithinInterval(): void
    {
        $registry = new RegisterThrottleRegistry();
        $registry->recordAttempt('ip:xyz');

        self::assertTrue($registry->isThrottled('ip:xyz', 3600));
    }

    #[Test]
    public function pruneRemovesExpiredEntries(): void
    {
        $registry = new RegisterThrottleRegistry();
        // ... add entries with past timestamps
        $registry->prune();
        // ... assert only recent entries remain
    }
}
```

### Pruner Tests (Application Layer)

```
tests/Application/<Service>/Maintenance/Pruner/<Pruner>Test.php
```

Pattern: Test that pruner calls prune on its registry.

```php
#[CoversClass(RegisterThrottlePruner::class)]
final class RegisterThrottlePrunerTest extends TestCase
{
    #[Test]
    public function pruneDelegatesToRegistry(): void
    {
        $registry = $this->createMock(RegisterThrottleRegistry::class);
        $registry->expects(self::once())->method('prune');

        $pruner = new RegisterThrottlePruner($registry);
        $pruner->prune();
    }
}
```

### Protocol Handler Tests (Infrastructure Layer)

```
tests/Infrastructure/IRC/Protocol/<Ircd>/<Handler>Test.php
```

Pattern: Test wire format → canonical IRCMessage parsing, and reverse.

```php
#[CoversClass(InspIRCdProtocolHandler::class)]
final class InspIRCdProtocolHandlerTest extends TestCase
{
    #[Test]
    public function parseNickLineReturnsIRCMessage(): void
    {
        $handler = new InspIRCdProtocolHandler(/* ... */);
        $message = $handler->parseRawLine(':0AAAAAB NICK newnick 1234567890');

        self::assertSame('NICK', $message->command);
        self::assertSame(['newnick'], $message->params);
    }
}
```

### Integration Tests (Repository)

```
tests/Integration/Infrastructure/<Service>/Doctrine/<Repo>Test.php
```

Pattern: Use real EntityManager with SQLite, test actual persistence.

```php
#[CoversClass(RegisteredNickDoctrineRepository::class)]
final class RegisteredNickDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function findByNickReturnsEntityWhenExists(): void
    {
        $repo = $this->getRepository(RegisteredNickDoctrineRepository::class);
        $nick = RegisteredNick::create(/* ... */);
        $repo->save($nick);

        $em = static::getContainer()->get('doctrine')->getManager();
        $em->flush();
        $em->clear();

        $found = $repo->findByNick('test');
        self::assertNotNull($found);
    }
}
```

---

## Quick Reference: Test by What You're Testing

| What | Test Location | Pattern |
|------|---------------|---------|
| Entity business logic | `tests/Domain/*/Entity/` | Create entity, call method, assert state |
| Value Object validation | `tests/Domain/*/ValueObject/` | Test creation, equality, exceptions |
| Command interface contract | `tests/Application/*/Command/Handler/` | Test getName(), getMinArgs(), etc. |
| Command execution | `tests/Application/*/Command/Handler/` | Create context with args, execute, assert messages |
| Command authorization | `tests/Application/*/Command/Handler/` | Test with identified=false, wrong owner, IRCop |
| Subscriber reaction | `tests/Infrastructure/*/Subscriber/` | Create event, call subscriber method, assert calls |
| Voter logic | `tests/Infrastructure/*/Security/Voter/` | Test supports() + voteOnAttribute() |
| Bot delegation | `tests/Infrastructure/*/Bot/` | Test onCommand() calls service |
| Protocol parsing | `tests/Infrastructure/IRC/Protocol/` | Test raw line → message |
| Repository persistence | `tests/Integration/Infrastructure/*/Doctrine/` | Use SQLite, test actual DB operations |
| In-memory registry | `tests/Application/*/` | Test add/check/prune behavior |
| Maintenance task | `tests/Application/*/Maintenance/` | Test task invokes correct operations |

---

## Related Skills

- `.agents/testing/README.md` — Core testing rules
- `.agents/testing/testing-coverage-priorities.md` — Test priorities and coverage map
- `.agents/services/commands-testing.md` — Full command testing guide
