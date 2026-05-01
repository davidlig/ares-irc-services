# Entity Design Patterns

Use this skill when creating or modifying Domain entities.

---

## Entity Rules

### Readonly Properties

Use `readonly` for properties that never change after construction:

```php
final class RegisteredNick
{
    public function __construct(
        private(set) int $id,           // Set once, never changes externally
        private(set) string $nickname,  // Set once
        private(set) \DateTimeImmutable $createdAt,  // Immutable
        private string $password,       // Mutable via business methods
        private ?string $email = null,  // Mutable
    ) {}
}
```

Do NOT make the full entity class `readonly` if it needs state changes.

### No Public Setters

```php
// WRONG
public function setName(string $name): void { $this->name = $name; }
public function setEmail(string $email): void { $this->email = $email; }

// CORRECT — business methods
public function changeEmail(string $newEmail): void { ... }
public function rename(string $newName): void { ... }
public function suspend(string $reason, \DateTimeImmutable $expiresAt): void { ... }
```

### Constructor Property Promotion

Use for injection and data initialization:

```php
final readonly class ChannelAccess
{
    public function __construct(
        private(set) int $id,
        private(set) int $nickId,
        private(set) string $channelName,
        private(set) int $level,
        private(set) ?int $addedByNickId = null,
    ) {}
}
```

### Property Hooks (PHP 8.4)

Use `get`/`set` hooks where logic is needed:

```php
final class RegisteredNick
{
    private(set) string $password {
        set(string $value) {
            $this->password = $this->passwordHasher->hash($value);
        }
    }
}
```

---

## Constructor Promotion Exceptions

The following patterns use explicit property declaration + assignment (not promotion):

### Tagged Service Iterables

`*CommandRegistry.php` classes receive `iterable $commands` from Symfony DI and transform to associative arrays:

```php
final readonly class NickServCommandRegistry
{
    /** @var array<string, NickServCommandInterface> */
    private array $commands;

    public function __construct(iterable $commands)
    {
        $map = [];
        foreach ($commands as $command) {
            $map[strtoupper($command->getName())] = $command;
        }
        $this->commands = $map;
    }
}
```

### Post-processing in Constructor

`MaintenanceScheduler` transforms `iterable $tasks` via `iterator_to_array()` and `usort()` before storing.

### Array-building from Multiple Dependencies

Handlers that build associative arrays from individual dependencies (e.g., `SetCommand` building `$this->handlers = ['FOUNDER' => ..., 'SUCCESSOR' => ..., ...]`).

---

## Value Objects

MUST be `readonly class`:

```php
readonly class ChannelName
{
    public function __construct(
        public string $value,
    ) {
        if (!str_starts_with($this->value, '#')) {
            throw new \InvalidArgumentException('Channel name must start with #');
        }
    }

    public function equals(self $other): bool
    {
        return strcasecmp($this->value, $other->value) === 0;
    }
}
```

---

## Entity → Doctrine Mapping

Entities use XML mapping in `config/doctrine/`:

```xml
<!-- config/doctrine/NickServ.Entity.RegisteredNick.orm.xml -->
<doctrine-mapping>
    <entity name="App\Domain\NickServ\Entity\RegisteredNick"
            table="registered_nicks">
        <id name="id" type="integer">
            <generator strategy="AUTO"/>
        </id>
        <field name="nickname" type="string" length="30" unique="true"/>
        <field name="email" type="string" nullable="true"/>
        <field name="createdAt" type="datetimetz_immutable"/>
    </entity>
</doctrine-mapping>
```

---

## Related Skills

- `.agents/architecture/events.md` — Domain events dispatched by entities
- `.agents/architecture/drop-cleanup.md` — Cleanup when entities are dropped
- `.agents/database/README.md` — Doctrine ORM details
