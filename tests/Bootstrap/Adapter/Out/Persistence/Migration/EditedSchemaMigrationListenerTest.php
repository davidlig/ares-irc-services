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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(EditedSchemaMigrationListener::class)]
final class EditedSchemaMigrationListenerTest extends KernelTestCase
{
    #[Test]
    public function productionAutoconfigurationSuppliesTargetBeforeNextVersionPlanning(): void
    {
        self::bootKernel(['debug' => true]);
        $factory = self::getContainer()->get('doctrine.migrations.dependency_factory');
        self::assertInstanceOf(DependencyFactory::class, $factory);
        $connection = $factory->getConnection();
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
        $connection->close();
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
}
