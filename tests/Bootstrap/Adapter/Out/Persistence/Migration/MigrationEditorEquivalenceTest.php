<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Adapter\Out\Persistence\Migration;

use App\Bootstrap\Adapter\Out\Persistence\Migration\EditedSchemaMigrationListener;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Deprecations\Deprecation;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\EventDispatcher;
use Doctrine\Migrations\Events;
use Doctrine\Migrations\Metadata\MigrationPlan;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function dirname;

/** Migrations are outside the production coverage filter; exercise their executor explicitly. */
#[CoversNothing]
final class MigrationEditorEquivalenceTest extends TestCase
{
    #[Test]
    public function allMigrationsUseActualExecutorAndDryRunWithoutDeprecatedEditorApis(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->factory($connection)->getMetadataStorage()->ensureInitialized();
        $type = new ReflectionProperty(Deprecation::class, 'type');
        $previousType = $type->getValue();
        Deprecation::enableTrackingDeprecations();
        $before = Deprecation::getTriggeredDeprecations();
        try {
            $paths = glob(dirname(__DIR__, 6) . '/migrations/Version*.php');
            self::assertIsArray($paths);
            self::assertCount(32, $paths);
            $this->factory($connection)->getMetadataStorage()->ensureInitialized();
            foreach ($paths as $path) {
                $class = 'App\\Migrations\\' . basename($path, '.php');
                $beforeSql = $connection->fetchAllAssociative("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name");
                $this->execute($connection, $class, 'up', true);
                self::assertSame($beforeSql, $connection->fetchAllAssociative("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name"), $class . ' dry-run');
                $this->execute($connection, $class, 'up', false);
            }
            self::assertSame(31, $connection->createSchemaManager()->listTableColumns('udb_staged_transfers')['txid']->getLength());
            self::assertCount(3, $connection->fetchAllAssociative('SELECT name FROM oper_roles ORDER BY id'));
            self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM udb_authority_state'));
            foreach (array_reverse($paths) as $path) {
                $class = 'App\\Migrations\\' . basename($path, '.php');
                $beforeSql = $connection->fetchAllAssociative("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name");
                $this->execute($connection, $class, 'down', true);
                self::assertSame($beforeSql, $connection->fetchAllAssociative("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name"), $class . ' down dry-run');
                $this->execute($connection, $class, 'down', false);
            }
            self::assertFalse($connection->createSchemaManager()->tablesExist(['registered_nicks', 'udb_records', 'udb_staged_records', 'oper_roles']));
            // These are the installed DBAL contracts exercised by the old APIs.
            foreach (['https://github.com/doctrine/dbal/pull/7381', 'https://github.com/doctrine/dbal/pull/6867', 'https://github.com/doctrine/dbal/pull/7373', 'https://github.com/doctrine/dbal/pull/7389', 'https://github.com/doctrine/dbal/pull/7490'] as $link) {
                self::assertSame($before[$link] ?? 0, Deprecation::getTriggeredDeprecations()[$link] ?? 0, $link);
            }
        } finally {
            $type->setValue(null, $previousType);
            $connection->close();
        }
    }

    #[Test]
    public function combinedFreshInstallDryRunPropagatesEachEditedSchemaWithoutWriting(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $factory = $this->factory($connection);
            $factory->getMetadataStorage()->ensureInitialized();
            $paths = glob(dirname(__DIR__, 6) . '/migrations/Version*.php');
            self::assertIsArray($paths);
            $plans = [];
            foreach ($paths as $path) {
                $class = 'App\\Migrations\\' . basename($path, '.php');
                $plans[] = new MigrationPlan(new Version($class), $factory->getMigrationFactory()->createVersion($class), 'up');
            }
            $before = $connection->fetchAllAssociative("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name");
            $configuration = new MigratorConfiguration()->setDryRun(true);
            $sql = $factory->getMigrator()->migrate(new MigrationPlanList($plans, 'up'), $configuration);
            self::assertCount(32, $sql);
            self::assertSame($before, $connection->fetchAllAssociative("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name"));
            $schema = $configuration->getFromSchema();
            self::assertNotNull($schema);
            self::assertTrue($schema->hasTable('udb_staged_records'));
            self::assertSame(31, $schema->getTable('udb_staged_transfers')->getColumn('txid')->getLength());
            $downPlans = [];
            foreach (array_reverse($paths) as $path) {
                $class = 'App\\Migrations\\' . basename($path, '.php');
                $downPlans[] = new MigrationPlan(new Version($class), $factory->getMigrationFactory()->createVersion($class), 'down');
            }
            $down = $factory->getMigrator()->migrate(new MigrationPlanList($downPlans, 'down'), $configuration);
            self::assertCount(32, $down);
            $restoredSchema = $configuration->getFromSchema();
            self::assertNotNull($restoredSchema);
            self::assertFalse($restoredSchema->hasTable('udb_records'));
            self::assertSame($before, $connection->fetchAllAssociative("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name"));
            // The identical complete plan also installs and removes a fresh physical database.
            foreach (['up', 'down'] as $direction) {
                $actualPaths = 'up' === $direction ? $paths : array_reverse($paths);
                $actualPlans = [];
                foreach ($actualPaths as $path) {
                    $class = 'App\\Migrations\\' . basename($path, '.php');
                    $actualPlans[] = new MigrationPlan(new Version($class), $factory->getMigrationFactory()->createVersion($class), $direction);
                }
                self::assertCount(32, $factory->getMigrator()->migrate(new MigrationPlanList($actualPlans, $direction), new MigratorConfiguration()));
                if ('up' === $direction) {
                    self::assertSame(3, $connection->fetchOne('SELECT COUNT(*) FROM oper_roles'));
                    self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM udb_authority_state'));
                }
            }
            self::assertSame($before, $connection->fetchAllAssociative("SELECT type, name, sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type, name"));
        } finally {
            $connection->close();
        }
    }

    private function factory(Connection $connection): DependencyFactory
    {
        $factory = DependencyFactory::fromConnection(new ExistingConfiguration(new Configuration()), new ExistingConnection($connection));
        $events = new EventManager();
        $events->addEventListener([Events::onMigrationsVersionExecuted], new EditedSchemaMigrationListener());
        $factory->setService(EventDispatcher::class, new EventDispatcher($connection, $events));

        return $factory;
    }

    private function execute(Connection $connection, string $class, string $direction, bool $dryRun): void
    {
        self::assertTrue(is_a($class, AbstractMigration::class, true));
        $factory = $this->factory($connection);
        $factory->getMetadataStorage()->ensureInitialized();
        $migration = $factory->getMigrationFactory()->createVersion($class);
        $plan = new MigrationPlan(new Version($class), $migration, $direction);
        $factory->getMigrator()->migrate(new MigrationPlanList([$plan], $direction), new MigratorConfiguration()->setDryRun($dryRun));
    }
}
