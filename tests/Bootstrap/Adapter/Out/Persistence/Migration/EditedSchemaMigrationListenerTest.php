<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Adapter\Out\Persistence\Migration;

use App\Bootstrap\Adapter\Out\Persistence\Migration\EditedSchemaMigrationListener;
use App\Migrations\Version20260914000001;
use App\Migrations\Version20260927000002;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Event\MigrationsVersionEventArgs;
use Doctrine\Migrations\Metadata\MigrationPlan;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\ExecutionResult;
use Doctrine\Migrations\Version\Version;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_key_exists;
use function getenv;
use function putenv;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(EditedSchemaMigrationListener::class)]
final class EditedSchemaMigrationListenerTest extends KernelTestCase
{
    #[Test]
    public function productionAutoconfigurationSuppliesTargetBeforeNextVersionPlanning(): void
    {
        $this->assertProductionAutoconfigurationInPrivateDatabase();
    }

    #[Test]
    public function productionProofPreservesAlreadyMigratedConfiguredDatabaseAndEnvironment(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ares-listener-ci-');
        self::assertIsString($path);
        $shared = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        try {
            // CI migrates its persistent database before running the test suite.
            $shared->executeStatement('CREATE TABLE oper_permissions (id INTEGER PRIMARY KEY, name VARCHAR(64) NOT NULL)');
            $shared->insert('oper_permissions', ['id' => 1, 'name' => 'disposable shared fixture']);
            $schema = $shared->fetchAllAssociative('SELECT type, name, sql FROM sqlite_master ORDER BY type, name');
            $rows = $shared->fetchAllAssociative('SELECT * FROM oper_permissions');
            $url = 'sqlite:///' . $path;
            $this->withDatabaseUrl($url, function () use ($url, $shared, $schema, $rows): void {
                $this->assertProductionAutoconfigurationInPrivateDatabase();
                self::assertSame($url, $_ENV['DATABASE_URL']);
                self::assertSame($url, $_SERVER['DATABASE_URL']);
                self::assertSame($url, getenv('DATABASE_URL'));
                self::assertSame($schema, $shared->fetchAllAssociative('SELECT type, name, sql FROM sqlite_master ORDER BY type, name'));
                self::assertSame($rows, $shared->fetchAllAssociative('SELECT * FROM oper_permissions'));
            });
        } finally {
            $shared->close();
            unlink($path);
        }
    }

    #[Test]
    public function databaseOverrideRestoresEnvironmentWhenProofFails(): void
    {
        $beforeEnv = $_ENV;
        $beforeServer = $_SERVER;
        $beforeProcess = getenv('DATABASE_URL');
        try {
            $this->withDatabaseUrl('sqlite:///:memory:', static function (): void {
                self::bootKernel(['debug' => true]);
                throw new RuntimeException('Controlled disposable proof failure.');
            });
            self::fail('The proof failure must propagate.');
        } catch (RuntimeException $failure) {
            self::assertSame('Controlled disposable proof failure.', $failure->getMessage());
        }
        self::assertSame($beforeEnv, $_ENV);
        self::assertSame($beforeServer, $_SERVER);
        self::assertSame($beforeProcess, getenv('DATABASE_URL'));
        self::assertFalse(self::$booted);
        self::assertNull(self::$kernel);
    }

    #[Test]
    public function leavesSqlOnlyMigrationResultUntouched(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $migration = new Version20260927000002($connection, new NullLogger());
            $version = new Version($migration::class);
            $plan = new MigrationPlan($version, $migration, 'up');
            $result = new ExecutionResult($version);
            $original = new Schema();
            $result->setToSchema($original);
            $plan->markAsExecuted($result);
            new EditedSchemaMigrationListener()->onMigrationsVersionExecuted(new MigrationsVersionEventArgs($connection, $plan, new MigratorConfiguration()));
            self::assertSame($original, $result->getToSchema());
        } finally {
            $connection->close();
        }
    }

    #[Test]
    public function targetWithoutExecutedResultFailsClosed(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $migration = new Version20260914000001($connection, new NullLogger());
            $migration->up(new Schema());
            self::assertNotNull($migration->getEditedSchema());
            $plan = new MigrationPlan(new Version($migration::class), $migration, 'up');
            $this->expectException(LogicException::class);
            new EditedSchemaMigrationListener()->onMigrationsVersionExecuted(new MigrationsVersionEventArgs($connection, $plan, new MigratorConfiguration()));
        } finally {
            $connection->close();
        }
    }

    #[Test]
    public function missingTargetFailsClosed(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $migration = new Version20260914000001($connection, new NullLogger());
            $plan = new MigrationPlan(new Version($migration::class), $migration, 'up');
            $this->expectException(LogicException::class);
            new EditedSchemaMigrationListener()->onMigrationsVersionExecuted(new MigrationsVersionEventArgs($connection, $plan, new MigratorConfiguration()));
        } finally {
            $connection->close();
        }
    }

    private function assertProductionAutoconfigurationInPrivateDatabase(): void
    {
        $this->withDatabaseUrl('sqlite:///:memory:', static function (): void {
            self::bootKernel(['debug' => true]);
            $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');
            self::assertInstanceOf(DependencyFactory::class, $factory);
            $connection = $factory->getConnection();
            try {
                self::assertTrue($connection->getParams()['memory'] ?? false);
                $factory->getMetadataStorage()->ensureInitialized();
                $class = Version20260914000001::class;
                $migration = $factory->getMigrationFactory()->createVersion($class);
                $plan = new MigrationPlan(new Version($class), $migration, 'up');
                $configuration = new MigratorConfiguration()->setDryRun(true);
                $factory->getMigrator()->migrate(new MigrationPlanList([$plan], 'up'), $configuration);
                self::assertNotNull($configuration->getFromSchema());
                self::assertTrue($configuration->getFromSchema()->hasTable('oper_permissions'));
                self::assertFalse($connection->createSchemaManager()->tablesExist(['oper_permissions']));
                self::assertInstanceOf(Version20260914000001::class, $migration);
                $result = $plan->getResult();
                self::assertNotNull($result);
                self::assertNotSame($migration->getEditedSchema(), $result->getToSchema());
            } finally {
                $connection->close();
            }
        });
    }

    /** @param callable(): void $operation */
    private function withDatabaseUrl(string $url, callable $operation): void
    {
        self::ensureKernelShutdown();
        $hadEnv = array_key_exists('DATABASE_URL', $_ENV);
        $previousEnv = $_ENV['DATABASE_URL'] ?? null;
        $hadServer = array_key_exists('DATABASE_URL', $_SERVER);
        $previousServer = $_SERVER['DATABASE_URL'] ?? null;
        $previousProcess = getenv('DATABASE_URL');
        $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = $url;
        putenv('DATABASE_URL=' . $url);
        try {
            $operation();
        } finally {
            try {
                self::ensureKernelShutdown();
            } finally {
                self::$kernel = null;
                self::$booted = false;
                if ($hadEnv) {
                    $_ENV['DATABASE_URL'] = $previousEnv;
                } else {
                    unset($_ENV['DATABASE_URL']);
                }
                if ($hadServer) {
                    $_SERVER['DATABASE_URL'] = $previousServer;
                } else {
                    unset($_SERVER['DATABASE_URL']);
                }
                putenv(false === $previousProcess ? 'DATABASE_URL' : 'DATABASE_URL=' . $previousProcess);
            }
        }
    }
}
