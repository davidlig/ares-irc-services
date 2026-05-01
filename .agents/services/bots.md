# Adding a New Service/Bot

Checklist for implementing a new IRC service (e.g., OperServ, HostServ, BotServ).

## Architecture

Each service follows Clean Architecture with three layers:

```
Domain/<ServiceName>/          — Entities, VOs, Repository Interfaces, Events
Application/<ServiceName>/     — Service dispatcher, Context, Commands, Handlers
Infrastructure/<ServiceName>/  — Bot, Doctrine Repositories, Subscribers
```

## 1. Domain Layer

### Entity (if persistent state)

```php
// src/Domain/HostServ/HostRequest.php
final class HostRequest
{
    public function __construct(
        private(set) string $id,
        private(set) string $nick,
        private(set) string $vhost,
        private(set) HostRequestStatus $status,
        private(set) \DateTimeImmutable $requestedAt,
    ) {}

    public function approve(): void { /* business logic */ }
    public function reject(): void { /* business logic */ }
}
```

### Repository Interface

```php
interface HostRequestRepositoryInterface
{
    public function findPendingByNick(string $nick): ?HostRequest;
    public function save(HostRequest $request): void;
}
```

### Value Objects

```php
enum HostRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
```

## 2. Application Layer

### Main Service (Dispatcher)

```php
final readonly class HostServService
{
    public function dispatch(string $rawText, SenderView $sender): void
    {
        $parts = preg_split('/\s+/', trim($rawText), -1, PREG_SPLIT_NO_EMPTY);
        $cmdName = strtoupper(array_shift($parts) ?? '');
        $handler = $this->commandRegistry->find($cmdName);

        if (null === $handler) {
            $this->notifier->sendNotice($sender->uid, 'Unknown command.');
            return;
        }

        $context = new HostServContext($sender, $parts, ...);
        $handler->handle($context);
    }
}
```

### Command Interface & Registry

```php
interface HostServCommandInterface
{
    public function getName(): string;
    public function getAliases(): array;
    public function handle(HostServContext $context): void;
}

final readonly class HostServCommandRegistry
{
    private array $map;

    public function __construct(iterable $commands)
    {
        $map = [];
        foreach ($commands as $command) {
            $map[strtoupper($command->getName())] = $command;
        }
        $this->map = $map;
    }
}
```

## 3. Infrastructure Layer

### Bot Class

```php
final readonly class HostServBot implements
    HostServNotifierInterface,
    ServiceCommandListenerInterface,
    EventSubscriberInterface
{
    // ServiceCommandListenerInterface
    public function getServiceName(): string { return 'HostServ'; }
    public function getServiceUid(): ?string { return $this->hostservUid; }

    public function onCommand(string $senderUid, string $text): void
    {
        $sender = $this->userLookup->findByUid($senderUid);
        if (null === $sender) { return; }
        $this->service->dispatch($text, $sender);
    }

    // EventSubscriberInterface (only for burst introduction)
    public static function getSubscribedEvents(): array
    {
        return [NetworkBurstCompleteEvent::class => ['onBurstComplete', 98]];
    }
}
```

### Doctrine Mapping

```xml
<!-- config/doctrine/HostServ.orm.xml -->
<doctrine-mapping>
    <entity name="App\Domain\HostServ\HostRequest" table="host_requests">
        <id name="id" type="string"/>
        <field name="nick" type="string"/>
        <field name="vhost" type="string"/>
        <field name="status" type="string"/>
        <field name="requestedAt" type="datetimetz_immutable"/>
    </entity>
</doctrine-mapping>
```

## 4. DI Configuration (`config/services.yaml`)

```yaml
parameters:
    hostserv.uid: '%env(HOSTSERV_UID)%'
    hostserv.nick: 'HostServ'
    hostserv.ident: 'HostServ'
    hostserv.realname: 'Virtual Host Service'

App\Domain\HostServ\Repository\HostRequestRepositoryInterface:
    alias: App\Infrastructure\HostServ\Persistence\HostRequestDoctrineRepository

App\Infrastructure\HostServ\Bot\HostServBot:
    arguments:
        $hostservUid: '%hostserv.uid%'
        $hostservNick: '%hostserv.nick%'
    tags: ['kernel.event_subscriber', 'app.service_command_listener']

# Command registry auto-discovers tagged handlers
App\Application\HostServ\Command\HostServCommandRegistry:
    arguments: [!tagged_iterator hostserv.command]
```

### Environment Variables (`.env`)

```
HOSTSERV_UID=00ZZZZZZZ
```

### CtcpHandler `$serviceUidMap`

```yaml
App\Infrastructure\IRC\ServiceBridge\CtcpHandler:
    arguments:
        $serviceUidMap:
            nickserv: '%nickserv.uid%'
            chanserv: '%chanserv.uid%'
            memoserv: '%memoserv.uid%'
            operserv: '%operserv.uid%'
            hostserv: '%hostserv.uid%'  # add new service
```

## 5. Forbidden Patterns

```php
// WRONG: Core entity in Application
use App\Domain\IRC\NetworkUser;

// CORRECT: Use DTOs
use App\Application\Port\SenderView;
```

```php
// WRONG: Subscribe to MessageReceivedEvent
public static function getSubscribedEvents(): array {
    return [MessageReceivedEvent::class => 'onMessage'];  // NO
}

// CORRECT: Only burst for introduction
public static function getSubscribedEvents(): array {
    return [NetworkBurstCompleteEvent::class => 'onBurstComplete'];
}
```

```php
// WRONG: Business logic in Bot
public function onCommand(string $uid, string $text): void {
    // validation, persistence here
}

// CORRECT: Delegate to Service
public function onCommand(string $uid, string $text): void {
    $this->service->dispatch($text, $sender);
}
```

## Checklist Summary

- [ ] Domain: Entity, Repository Interface, VOs, Events
- [ ] Application: Service, Context, Registry, Command Interface, Handlers, Notifier Interface
- [ ] Infrastructure: Bot, Doctrine Repository, XML Mapping
- [ ] DI: `services.yaml` parameters, service definitions, tags
- [ ] Environment: `.env` variables, CtcpHandler service UID map
- [ ] Tests: Domain, Application, Infrastructure (100% coverage)
- [ ] No `Domain\IRC` imports in Application
- [ ] No `MessageReceivedEvent` subscription (use Gateway)
- [ ] Bot implements `ServiceCommandListenerInterface`
- [ ] Bot delegates ALL logic to Service
- [ ] HELP command follows `.agents/services/help-design.md`

## Related Skills

- `.agents/services/README.md` — Core vs Services overview
- `.agents/services/commands.md` — Command handler patterns
- `.agents/services/commands-permissions.md` — Authorization
- `.agents/services/commands-translations.md` — Translations
- `.agents/services/commands-testing.md` — Testing
