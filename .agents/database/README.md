# Database — Doctrine ORM & Migrations

Use this skill for Doctrine ORM mapping, repository implementations, migrations, and memory management with the EntityManager.

---

## Doctrine ORM 3.6

### Entity Mapping (XML)

All entities use XML mapping in `config/doctrine/`:

```
config/doctrine/
├── NickServ.Entity.RegisteredNick.orm.xml
├── NickServ.Entity.NickHistory.orm.xml
├── NickServ.Entity.ForbiddenVhost.orm.xml
├── ChanServ.Entity.RegisteredChannel.orm.xml
├── ChanServ.Entity.ChannelAccess.orm.xml
├── ChanServ.Entity.ChannelAkick.orm.xml
├── ChanServ.Entity.ChannelLevel.orm.xml
├── ChanServ.Entity.ChannelHistory.orm.xml
├── OperServ.Entity.OperRole.orm.xml
├── OperServ.Entity.OperIrcop.orm.xml
├── OperServ.Entity.OperPermission.orm.xml
├── OperServ.Entity.Gline.orm.xml
├── OperServ.Entity.Motd.orm.xml
├── MemoServ.Entity.Memo.orm.xml
├── MemoServ.Entity.MemoSettings.orm.xml
└── MemoServ.Entity.MemoIgnore.orm.xml
```

### Mapping Template

```xml
<doctrine-mapping xmlns="https://doctrine-project.org/schemas/orm/doctrine-mapping">
    <entity name="App\Domain\MyService\Entity\MyEntity"
            table="my_entity"
            repository-class="App\Infrastructure\MyService\Doctrine\MyEntityDoctrineRepository">
        <id name="id" type="integer">
            <generator strategy="IDENTITY"/>
        </id>
        <field name="name" type="string" length="50" unique="true"/>
        <field name="createdAt" type="datetimetz_immutable"/>
        <field name="updatedAt" type="datetimetz_immutable" nullable="true"/>
        <field name="deletedAt" type="datetimetz_immutable" nullable="true"/>
        <indexes>
            <index columns="name"/>
        </indexes>
    </entity>
</doctrine-mapping>
```

---

## Repository Pattern

### Interface (Domain Layer)

```php
// src/Domain/MyService/Repository/MyEntityRepositoryInterface.php
interface MyEntityRepositoryInterface
{
    public function findById(int $id): ?MyEntity;
    public function save(MyEntity $entity): void;
    public function delete(MyEntity $entity): void;
}
```

### Implementation (Infrastructure Layer)

```php
// src/Infrastructure/MyService/Doctrine/MyEntityDoctrineRepository.php
final readonly class MyEntityDoctrineRepository implements MyEntityRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function findById(int $id): ?MyEntity
    {
        return $this->em->getRepository(MyEntity::class)->find($id);
    }

    public function save(MyEntity $entity): void
    {
        $this->em->persist($entity);
    }

    public function delete(MyEntity $entity): void
    {
        $this->em->remove($entity);
    }
}
```

### DI Configuration

```yaml
# config/services.yaml
App\Domain\MyService\Repository\MyEntityRepositoryInterface:
    alias: App\Infrastructure\MyService\Doctrine\MyEntityDoctrineRepository
```

---

## Migrations

### Structure

Migrations follow the naming convention `VersionYYYYMMDDHHMMSS.php`:

```
migrations/
├── Version20260301000000.php
├── ...
└── Version20260430140441.php  (most recent)
```

### Creating a Migration

```bash
php bin/console make:migration
```

Or manually with diff:

```bash
php bin/console doctrine:migrations:diff
```

### Applying

```bash
php bin/console doctrine:migrations:migrate
```

---

## EntityManager in Daemon Mode (CRITICAL)

The application runs as a long-lived daemon, not a request-response cycle. The EntityManager caches every entity it touches and grows indefinitely.

### Mandatory Pattern

```php
// AFTER flush, clear the identity map
$this->em->flush();
$this->em->clear();  // REQUIRED — detach all entities

// At end of message processing cycle
$this->em->clear();
```

### Memory Cleanup in Handlers

```php
public function execute(NickServContext $context): void
{
    // ... business logic ...
    $this->em->flush();
    $this->em->clear();  // Prevent memory leaks

    unset($entity, $result);  // Clean up large variables
}
```

### Bulk Operations

For batch operations, clear periodically:

```php
foreach ($entities as $i => $entity) {
    $this->em->persist($entity);
    if (0 === ($i % 50)) {
        $this->em->flush();
        $this->em->clear();
    }
}
$this->em->flush();
$this->em->clear();
```

---

## Doctrine Identity Map Clear Subscriber

The project has a global subscriber that clears the identity map:

```
src/Infrastructure/Shared/Subscriber/DoctrineIdentityMapClearSubscriber.php
```

---

## Integration Tests

Integration tests use SQLite in-memory database:

```php
// tests/Integration/Infrastructure/MyService/Doctrine/MyEntityDoctrineRepositoryTest.php
#[CoversClass(MyEntityDoctrineRepository::class)]
final class MyEntityDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    // Use real EntityManager with SQLite
    // Test actual database operations
}
```

Base class: `tests/Integration/DoctrineIntegrationTestCase.php`

---

## Related Skills

- `.agents/architecture/entities.md` — Entity design + Doctrine mapping
- `.agents/architecture/drop-cleanup.md` — Repository cleanup methods
- `.agents/memory/README.md` — Memory management in daemons
