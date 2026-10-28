<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Adapter\Out\Persistence;

use App\Bootstrap\Adapter\In\Console\ValidateSchemaCommand;
use App\Bootstrap\Adapter\Out\Persistence\MigrationOwnedSchemaListener;
use App\Kernel;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Tools\Console\Command\ValidateSchemaCommand as NativeValidateSchemaCommand;
use Doctrine\ORM\Tools\Console\EntityManagerProvider\SingleManagerProvider;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function array_intersect_key;
use function dirname;
use function serialize;
use function str_repeat;

#[CoversClass(ValidateSchemaCommand::class)]
#[CoversClass(MigrationOwnedSchemaListener::class)]
final class MigratedSchemaValidationTest extends TestCase
{
    private Kernel $kernel;

    private Application $application;

    private Connection $connection;

    private EntityManagerInterface $entityManager;

    /** @var array{env: array<string, mixed>, server: array<string, mixed>} */
    private array $previousDatabaseUrl;

    public function testFullyMigratedDatabaseMatchesOrmMetadataWithoutChangingDataOrTechnicalAssets(): void
    {
        $this->migrate();
        $this->insertRecord();
        foreach (['channel_registration_audit', 'chanserv_registration_policy', 'nickserv_registration_policy'] as $obsolete) {
            self::assertFalse($this->connection->createSchemaManager()->tablesExist([$obsolete]));
        }
        self::assertSame(2, $this->connection->fetchOne('SELECT COUNT(*) FROM service_settings'));
        $before = $this->snapshot();
        $this->validate(0);
        self::assertSame($before, $this->snapshot());
        self::assertTrue($this->connection->createSchemaManager()->tablesExist([
            'doctrine_migration_versions', 'service_nick_reservations', 'udb_staged_generations',
            'udb_staged_mutations', 'udb_staged_records', 'udb_staged_transfers',
        ]));
        $this->assertNativeIndexes();
    }

    public function testRealOrmColumnIndexAndUnknownTableDriftStillFailWithoutApplyingSuggestedSql(): void
    {
        $this->migrate();
        $this->insertRecord();
        foreach ([
            ['ALTER TABLE service_settings ADD unexpected_column TEXT', 'ALTER TABLE service_settings DROP COLUMN unexpected_column'],
            ['ALTER TABLE motd ADD unexpected_column TEXT', 'ALTER TABLE motd DROP COLUMN unexpected_column'],
            ['ALTER TABLE registered_nicks ADD list_search_key TEXT', 'ALTER TABLE registered_nicks DROP COLUMN list_search_key'],
            ['DROP INDEX idx_memos_sender', 'CREATE INDEX idx_memos_sender ON memos (sender_nick_id)'],
            ['CREATE TABLE service_nick_reservations_extra (id INTEGER)', 'DROP TABLE service_nick_reservations_extra'],
        ] as [$change, $restore]) {
            $this->connection->executeStatement($change);
            $before = $this->snapshot();
            $this->validate(2);
            self::assertSame($before, $this->snapshot());
            $this->connection->executeStatement($restore);
            $this->validate(0);
        }
    }

    public function testForwardMigrationDryRunApplyRollbackPreserveRowsAndNativeIndexes(): void
    {
        $previous = 'App\\Migrations\\Version20261025000001';
        $this->migrate($previous);
        $this->insertRecord();
        $before = $this->snapshot();
        $this->migrate('latest', true);
        self::assertSame($before, $this->snapshot());
        $this->migrate();
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM udb_records ORDER BY id');
        $this->assertNativeIndexes();
        $this->validate(0);
        $this->migrate($previous);
        self::assertSame($before, $this->snapshot());
        self::assertSame($rows, $this->connection->fetchAllAssociative('SELECT * FROM udb_records ORDER BY id'));
        $this->assertNativeIndexes();
    }

    public function testDuplicateLegacyIdentityFailsClosedWithoutDeletingRows(): void
    {
        $this->migrate('App\\Migrations\\Version20261025000001');
        // Model a legacy installation whose identity uniqueness was already missing.
        $table = $this->connection->createSchemaManager()->introspectTableByUnquotedName('udb_records')->edit()->setUniqueConstraints()->create();
        $this->connection->executeStatement('DROP TABLE udb_records');
        foreach ($this->connection->getDatabasePlatform()->getCreateTableSQL($table) as $sql) {
            $this->connection->executeStatement($sql);
        }
        $this->insertRecord();
        $this->insertRecord();
        $before = $this->snapshot();
        try {
            $this->migrate();
            self::fail('Duplicate legacy identities require operator resolution, not data deletion.');
        } catch (UniqueConstraintViolationException) {
            self::assertSame($before, $this->snapshot());
        }
    }

    public function testRestoredUniquenessRejectsDuplicateRecords(): void
    {
        $this->migrate();
        $this->insertRecord();
        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertRecord();
    }

    public function testExistingSkipSyncOutputAndConnectionFilterArePreserved(): void
    {
        $this->migrate();
        $listeners = $this->entityManager->getEventManager()->getListeners('postGenerateSchema');
        $provider = new SingleManagerProvider($this->entityManager);
        $native = new CommandTester(new NativeValidateSchemaCommand($provider));
        $owned = new CommandTester(new ValidateSchemaCommand($provider));
        $options = ['--skip-sync' => true];
        self::assertSame($native->execute($options), $owned->execute($options));
        self::assertSame($native->getDisplay(), $owned->getDisplay());
        $filter = static fn (string $name): bool => 'external_data' !== $name;
        $configuration = $this->connection->getConfiguration();
        $configuration->setSchemaAssetsFilter($filter);
        $this->connection->executeStatement('CREATE TABLE external_data (id INTEGER)');
        $this->validate(0);
        self::assertSame($filter, $configuration->getSchemaAssetsFilter());
        self::assertSame($listeners, $this->entityManager->getEventManager()->getListeners('postGenerateSchema'));
        self::assertTrue($this->connection->createSchemaManager()->tablesExist(['udb_staged_records']));
    }

    public function testValidationExceptionRestoresConnectionAndListeners(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $filter = static fn (string $name): bool => 'external' !== $name;
        $connection->getConfiguration()->setSchemaAssetsFilter($filter);
        $factory = $this->createStub(ClassMetadataFactory::class);
        $factory->method('getAllMetadata')->willThrowException(new RuntimeException('synthetic validation failure'));
        $events = new EventManager();
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getEventManager')->willReturn($events);
        $em->method('getMetadataFactory')->willReturn($factory);
        $command = new CommandTester(new ValidateSchemaCommand(new SingleManagerProvider($em)));
        try {
            $command->execute([]);
            self::fail('Expected synthetic validation failure.');
        } catch (RuntimeException $error) {
            self::assertSame('synthetic validation failure', $error->getMessage());
        } finally {
            self::assertSame($filter, $connection->getConfiguration()->getSchemaAssetsFilter());
            self::assertSame([], $events->getListeners('postGenerateSchema'));
            $connection->close();
        }
    }

    protected function setUp(): void
    {
        $this->previousDatabaseUrl = [
            'env' => array_intersect_key($_ENV, ['DATABASE_URL' => true]),
            'server' => array_intersect_key($_SERVER, ['DATABASE_URL' => true]),
        ];
        // Each case owns its database, regardless of the runner's configured URL.
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = 'sqlite:///:memory:';
        $this->kernel = new Kernel('test', true);
        $this->application = new Application($this->kernel);
        $this->application->setAutoExit(false);
        $this->kernel->boot();
        $registry = $this->kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $registry);
        $manager = $registry->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $this->entityManager = $manager;
        $this->connection = $manager->getConnection();
        self::assertTrue($this->connection->getParams()['memory'] ?? false);
    }

    protected function tearDown(): void
    {
        try {
            $this->kernel->shutdown();
        } finally {
            unset($_ENV['DATABASE_URL'], $_SERVER['DATABASE_URL']);
            $_ENV += $this->previousDatabaseUrl['env'];
            $_SERVER += $this->previousDatabaseUrl['server'];
        }
    }

    private function migrate(string $version = 'latest', bool $dryRun = false): void
    {
        // A real CLI invocation gets fresh migration instances, including after a dry run.
        $configuration = new Configuration();
        $configuration->addMigrationsDirectory('App\\Migrations', dirname(__DIR__, 5) . '/migrations');
        $factory = DependencyFactory::fromConnection(new ExistingConfiguration($configuration), new ExistingConnection($this->connection));
        $migration = new CommandTester(new MigrateCommand($factory));
        self::assertSame(0, $migration->execute(['version' => $version, '--dry-run' => $dryRun], ['interactive' => false]), $migration->getDisplay());
    }

    private function validate(int $expected): void
    {
        $validation = new CommandTester($this->application->find('doctrine:schema:validate'));
        self::assertSame($expected, $validation->execute([]), $validation->getDisplay());
    }

    private function insertRecord(): void
    {
        $this->connection->insert('udb_records', [
            'block' => 'N', 'path' => 'fixture', 'identity_path' => 'fixture',
            'identity_hash' => str_repeat('a', 32), 'value' => 'fixture', 'value_kind' => 2,
            'updated_at' => '2026-10-25 00:00:00',
        ]);
    }

    private function snapshot(): string
    {
        return serialize([
            $this->connection->fetchAllAssociative('SELECT name, sql FROM sqlite_master ORDER BY name'),
            $this->connection->fetchAllAssociative('SELECT * FROM udb_records ORDER BY id'),
            $this->connection->fetchAllAssociative('SELECT * FROM doctrine_migration_versions ORDER BY version'),
        ]);
    }

    private function assertNativeIndexes(): void
    {
        foreach (['registered_nicks', 'registered_channels'] as $table) {
            $sql = $this->connection->fetchOne('SELECT sql FROM sqlite_master WHERE name = ?', ['idx_' . $table . '_list_pattern']);
            self::assertIsString($sql);
            self::assertStringContainsString('COLLATE NOCASE', $sql);
        }
    }
}
