<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Adapter\Out\Persistence\Migration;

use App\Migrations\Version20260914000001;
use App\Migrations\Version20260914000002;
use App\Migrations\Version20260914000003;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\MigrationPlan;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

use function array_key_exists;
use function getenv;
use function putenv;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversNothing]
final class NativeMigrationIntegrationTest extends KernelTestCase
{
    #[Test]
    public function productionAutoconfigurationPlansNativeMigrationsWithoutWriting(): void
    {
        $this->assertProductionAutoconfigurationInPrivateDatabase();
    }

    #[Test]
    public function productionProofPreservesAlreadyMigratedConfiguredDatabaseAndEnvironment(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ares-native-ci-');
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
                $before = $connection->fetchAllAssociative('SELECT type, name, sql FROM sqlite_master ORDER BY type, name');
                $classes = [Version20260914000001::class, Version20260914000002::class, Version20260914000003::class];
                $plans = [];
                foreach ($classes as $class) {
                    $migration = $factory->getMigrationFactory()->createVersion($class);
                    self::assertInstanceOf($class, $migration);
                    $plans[] = new MigrationPlan(new Version($class), $migration, 'up');
                }
                $configuration = new MigratorConfiguration()->setDryRun(true);
                $sql = $factory->getMigrator()->migrate(new MigrationPlanList($plans, 'up'), $configuration);
                self::assertCount(3, $sql);
                self::assertSame($before, $connection->fetchAllAssociative('SELECT type, name, sql FROM sqlite_master ORDER BY type, name'));
                self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'));
                self::assertFalse($connection->createSchemaManager()->tablesExist(['oper_permissions', 'oper_roles', 'oper_ircops']));
                foreach ($plans as $plan) {
                    self::assertNotNull($plan->getResult());
                    self::assertNotEmpty($plan->getMigration()->getSql());
                }
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
