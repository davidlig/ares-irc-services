# Adding a New Service/Bot

Checklist for implementing a new IRC service (e.g., OperServ, HostServ, BotServ).

## Architecture Overview

Each service follows Clean Architecture with three layers:

```
Domain/<ServiceName>/          # Entities, VOs, Repository Interfaces, Events
Application/<ServiceName>/     # Service, Context, Commands, Handlers
Infrastructure/<ServiceName>/  # Bot, Doctrine Repos, Subscribers
```

## 1. Domain Layer

Create `src/Domain/<ServiceName>/`:

### 1.1 Entity (if persistent state)

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
        private(set) ?\DateTimeImmutable $approvedAt = null,
    ) {}
    
    public function approve(): void { /* business logic */ }
    public function reject(): void { /* business logic */ }
}
```

### 1.2 Repository Interface

```php
// src/Domain/HostServ/Repository/HostRequestRepositoryInterface.php
interface HostRequestRepositoryInterface
{
    public function findPendingByNick(string $nick): ?HostRequest;
    public function save(HostRequest $request): void;
}
```

### 1.3 Value Objects

```php
// src/Domain/HostServ/ValueObject/HostRequestStatus.php
enum HostRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
```

### 1.4 Domain Events (if needed)

```php
// src/Domain/HostServ/Event/HostRequestApprovedEvent.php
final readonly class HostRequestApprovedEvent
{
    public function __construct(
        public string $requestId,
        public string $nick,
        public string $vhost,
    ) {}
}
```

## 2. Application Layer

Create `src/Application/<ServiceName>/`:

### 2.1 Main Service (Dispatcher)

```php
// src/Application/HostServ/HostServService.php
final readonly class HostServService
{
    public function __construct(
        private readonly HostServCommandRegistry $commandRegistry,
        private readonly HostRequestRepositoryInterface $requestRepository,
        private readonly HostServNotifierInterface $notifier,
        // ... other dependencies
    ) {}
    
    public function dispatch(string $rawText, SenderView $sender): void
    {
        $parts = preg_split('/\s+/', trim($rawText), -1, PREG_SPLIT_NO_EMPTY);
        $cmdName = strtoupper(array_shift($parts) ?? '');
        $args = $parts;
        
        $handler = $this->commandRegistry->find($cmdName);
        if (null === $handler) {
            $this->notifier->sendNotice($sender->uid, 'Unknown command.');
            return;
        }
        
        $context = new HostServContext($sender, $args, ...);
        $handler->handle($context);
    }
}
```

### 2.2 Context (Readonly)

```php
// src/Application/HostServ/Command/HostServContext.php
final readonly class HostServContext
{
    public function __construct(
        public SenderView $sender,
        public array $args,
        public ?HostRequest $pendingRequest,
        public string $language = 'en',
    ) {}
}
```

### 2.3 Command Registry

```php
// src/Application/HostServ/Command/HostServCommandRegistry.php
final readonly class HostServCommandRegistry
{
    private array $map;
    
    /**
     * @param iterable<HostServCommandInterface> $commands
     */
    public function __construct(iterable $commands)
    {
        $map = [];
        foreach ($commands as $command) {
            $map[strtoupper($command->getName())] = $command;
        }
        $this->map = $map;
    }
    
    public function find(string $name): ?HostServCommandInterface { ... }
    public function all(): array { ... }
}
```

### 2.4 Command Interface

```php
// src/Application/HostServ/Command/HostServCommandInterface.php
interface HostServCommandInterface
{
    public function getName(): string;
    /** @return string[] */
    public function getAliases(): array;
    public function handle(HostServContext $context): void;
}
```

### 2.5 Notifier Interface

```php
// src/Application/HostServ/Command/HostServNotifierInterface.php
interface HostServNotifierInterface
{
    public function sendNotice(string $targetUid, string $message): void;
    public function sendMessage(string $targetUid, string $message, string $type): void;
}
```

### 2.6 Command Handler Example

```php
// src/Application/HostServ/Command/Handler/RequestCommand.php
final readonly class RequestCommand implements HostServCommandInterface
{
    public function __construct(
        private readonly HostRequestRepositoryInterface $requestRepository,
        private readonly HostServNotifierInterface $notifier,
        private readonly TranslatorInterface $translator,
    ) {}
    
    public function getName(): string { return 'REQUEST'; }
    public function getAliases(): array { return ['REQ']; }
    
    public function handle(HostServContext $context): void
    {
        $vhost = $context->args[0] ?? null;
        if (null === $vhost) {
            $this->notifier->sendNotice($context->sender->uid, 'Syntax: REQUEST <vhost>');
            return;
        }
        
        // Business logic...
        $request = new HostRequest(/* ... */);
        $this->requestRepository->save($request);
        
        $this->notifier->sendNotice($context->sender->uid, 'Request submitted.');
    }
}
```

## 3. Infrastructure Layer

Create `src/Infrastructure/<ServiceName>/`:

### 3.1 Bot Class

```php
// src/Infrastructure/HostServ/Bot/HostServBot.php
final readonly class HostServBot implements
    HostServNotifierInterface,
    ServiceCommandListenerInterface,
    EventSubscriberInterface
{
    public function __construct(
        private readonly ActiveConnectionHolder $connectionHolder,
        private readonly NetworkUserLookupPort $userLookup,
        private readonly SendNoticePort $sendNoticePort,
        private readonly HostServService $service,
        private readonly string $servicesHostname,
        private readonly string $hostservUid,
        private readonly string $hostservNick = 'HostServ',
        private readonly string $hostservIdent = 'HostServ',
        private readonly string $hostservRealname = 'Virtual Host Service',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}
    
    // ServiceCommandListenerInterface
    public function getServiceName(): string { return $this->hostservNick; }
    public function getServiceUid(): ?string { return $this->hostservUid; }
    
    public function onCommand(string $senderUid, string $text): void
    {
        $sender = $this->userLookup->findByUid($senderUid);
        if (null === $sender) {
            return;
        }
        $this->service->dispatch($text, $sender);
    }
    
    // EventSubscriberInterface
    public static function getSubscribedEvents(): array
    {
        return [NetworkBurstCompleteEvent::class => ['onBurstComplete', 98]];
    }
    
    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) { return; }
        
        $line = $module->getIntroductionFormatter()->formatIntroduction(
            $event->serverSid,
            $this->hostservNick,
            $this->hostservIdent,
            $this->servicesHostname,
            $this->hostservUid,
            $this->hostservRealname,
        );
        $event->connection->writeLine($line);
    }
    
    // HostServNotifierInterface
    public function sendNotice(string $targetUid, string $message): void
    {
        $this->sendNoticePort->sendNotice($targetUid, $message);
    }
    
    public function sendMessage(string $targetUid, string $message, string $type): void
    {
        $this->sendNoticePort->sendMessage($targetUid, $message, $type);
    }
    
    public function getNick(): string { return $this->hostservNick; }
    public function getUid(): string { return $this->hostservUid; }
}
```

### 3.2 Doctrine Repository

```php
// src/Infrastructure/HostServ/Persistence/HostRequestDoctrineRepository.php
final readonly class HostRequestDoctrineRepository implements HostRequestRepositoryInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}
    
    public function findPendingByNick(string $nick): ?HostRequest { ... }
    public function save(HostRequest $request): void { ... }
}
```

### 3.3 Doctrine Mapping

```xml
<!-- config/doctrine/HostServ.orm.xml -->
<doctrine-mapping>
    <entity name="App\Domain\HostServ\HostRequest" table="host_requests">
        <id name="id" type="string"/>
        <field name="nick" type="string"/>
        <field name="vhost" type="string"/>
        <field name="status" type="string"/>
        <field name="requestedAt" type="datetimetz_immutable"/>
        <field name="approvedAt" type="datetimetz_immutable" nullable="true"/>
    </entity>
</doctrine-mapping>
```

## 4. Symfony DI Configuration

Add to `config/services.yaml`:

```yaml
# --- HostServ Service ---------------------------------------------

App\Domain\HostServ\Repository\HostRequestRepositoryInterface:
    alias: App\Infrastructure\HostServ\Persistence\HostRequestDoctrineRepository

App\Infrastructure\HostServ\Bot\HostServBot:
    arguments:
        $servicesHostname: '%services.hostname%'
        $hostservUid: '%hostserv.uid%'
        $hostservNick: '%hostserv.nick%'
        $hostservIdent: '%hostserv.ident%'
        $hostservRealname: '%hostserv.realname%'
        $logger: '@monolog.logger.irc'
    tags: ['kernel.event_subscriber', 'irc.service_bot']

App\Application\HostServ\Command\HostServNotifierInterface:
    alias: App\Infrastructure\HostServ\Bot\HostServBot

# Command handlers (tagged for registry)
_instanceof:
    App\Application\HostServ\Command\HostServCommandInterface:
        tags: ['hostserv.command']

App\Application\HostServ\Command\HostServCommandRegistry:
    arguments: [!tagged_iterator hostserv.command]

App\Application\HostServ\HostServService:
    arguments:
        $commandRegistry: '@App\Application\HostServ\Command\HostServCommandRegistry'
        # ... other dependencies
```

### 4.1 Parameters

```yaml
parameters:
    # Service configuration
    services.hostname: '%env(SERVICES_HOSTNAME)%'
    services.default_language: 'en'
    
    # HostServ pseudo-client
    hostserv.uid: '%env(HOSTSERV_UID)%'
    hostserv.nick: 'HostServ'
    hostserv.ident: 'HostServ'
    hostserv.realname: 'Virtual Host Service'
```

### 4.2 Service Command Gateway Registration

The Bot must implement `ServiceCommandListenerInterface`. The Gateway auto-discovers it via the `irc.service_bot` tag (or manually add to the listeners iterable).

## 5. Tests

### 5.1 Domain Tests

```php
// tests/Domain/HostServ/HostRequestTest.php
final class HostRequestTest extends TestCase
{
    public function testApproveChangesStatus(): void
    {
        $request = new HostRequest(/* ... */);
        $request->approve();
        self::assertSame(HostRequestStatus::Approved, $request->status);
    }
}
```

### 5.2 Application Tests

```php
// tests/Application/HostServ/Command/Handler/RequestCommandTest.php
final class RequestCommandTest extends TestCase
{
    public function testHandleValidRequest(): void
    {
        $notifier = $this->createMock(HostServNotifierInterface::class);
        $repo = $this->createMock(HostRequestRepositoryInterface::class);
        // ...
    }
}
```

### 5.3 Bot Tests

```php
// tests/Infrastructure/HostServ/Bot/HostServBotTest.php
final class HostServBotTest extends TestCase
{
    public function testOnCommandDispatchesToService(): void
    {
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(new SenderView(...));
        
        $service = $this->createMock(HostServService::class);
        $service->expects(self::once())->method('dispatch');
        
        $bot = new HostServBot(/* ... */, $service);
        $bot->onCommand('ABC123', 'REQUEST my.vhost');
    }
}
```

## 6. Forbidden Patterns

### NEVER in Application Layer

```php
// WRONG: Core entity imported
use App\Domain\IRC\NetworkUser;
use App\Domain\IRC\Event\MessageReceivedEvent;

// CORRECT: Use Ports and DTOs
use App\Application\Port\SenderView;
use App\Application\Port\NetworkUserLookupPort;
```

### NEVER subscribe to Core events directly (except burst)

```php
// WRONG: Direct subscription to network events in Service
final class HostServBot implements EventSubscriberInterface {
    public static function getSubscribedEvents(): array {
        return [
            MessageReceivedEvent::class => 'onMessage',  // WRONG
            UserJoinEvent::class => 'onUserJoin',        // WRONG
        ];
    }
}

// CORRECT: Only burst for introduction
final class HostServBot implements EventSubscriberInterface {
    public static function getSubscribedEvents(): array {
        return [NetworkBurstCompleteEvent::class => 'onBurstComplete'];
    }
}
```

### NEVER put business logic in Bot

```php
// WRONG: Logic in Bot
public function onCommand(string $uid, string $text): void {
    if (preg_match('/^REQUEST\s+(.+)/', $text, $m)) {
        $vhost = $m[1];
        // validation, persistence, etc. in Bot
    }
}

// CORRECT: Delegate to Service
public function onCommand(string $uid, string $text): void {
    $sender = $this->userLookup->findByUid($uid);
    if (null === $sender) { return; }
    $this->service->dispatch($text, $sender);
}
```

## 7. Checklist Summary

- [ ] Domain: Entity, Repository Interface, VOs, Events
- [ ] Application: Service, Context, Registry, Command Interface, Handlers, Notifier Interface
- [ ] Infrastructure: Bot, Doctrine Repository, Mapping
- [ ] DI: `services.yaml` configuration (tag `irc.service_bot` or `kernel.event_subscriber`)
- [ ] Parameters: UID, nick, ident, realname
- [ ] Tests: Domain, Application (mocks), Infrastructure (Bot with mocked ports)
- [ ] No `Domain\IRC` imports in Application
- [ ] No `MessageReceivedEvent` subscription (use Gateway)
- [ ] Bot implements `ServiceCommandListenerInterface`
- [ ] Bot delegates to Service (no business logic in Bot)
- [ ] HELP command follows `.agents/services/help-design.md`

## File Structure Summary

```
src/
├── Domain/<ServiceName>/
│   ├── <Entity>.php
│   ├── Repository/<Interface>.php
│   ├── ValueObject/<VO>.php
│   └── Event/<Event>.php
├── Application/<ServiceName>/
│   ├── <ServiceName>Service.php
│   └── Command/
│       ├── <ServiceName>Context.php
│       ├── <ServiceName>CommandRegistry.php
│       ├── <ServiceName>CommandInterface.php
│       ├── <ServiceName>NotifierInterface.php
│       └── Handler/
│           ├── HelpCommand.php
│           └── <Command>.php
├── Infrastructure/<ServiceName>/
│   ├── Bot/<ServiceName>Bot.php
│   └── Persistence/<Repository>.php
└── config/
    ├── services.yaml (DI config)
    └── doctrine/<ServiceName>.orm.xml (mapping)

tests/
├── Domain/<ServiceName>/
├── Application/<ServiceName>/
└── Infrastructure/<ServiceName>/
```