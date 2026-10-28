<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Adapter\Out\Persistence\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\MigrationPlan;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_reverse;

/** Native-only feasibility: no application migration listener or schema bridge. */
#[CoversNothing]
final class NativeMigrationExecutionTest extends TestCase
{
    #[Test]
    public function nativeQueuedSqlPlansFreshUpAndDownWithoutWritingSchemaRowsOrMetadata(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $this->factory($connection);
            $before = $connection->fetchAllAssociative('SELECT type, name, sql FROM sqlite_master ORDER BY type, name');
            $original = $connection->createSchemaManager()->introspectSchema();
            $originalSql = $original->toSql($connection->getDatabasePlatform());
            $configuration = new MigratorConfiguration()->setDryRun(true)->setFromSchema($original);
            $classes = [NativeCreateTableMigrationFixture::class, NativeAddColumnMigrationFixture::class];

            $this->execute($connection, $classes, 'up', $configuration);

            self::assertSame($before, $connection->fetchAllAssociative('SELECT type, name, sql FROM sqlite_master ORDER BY type, name'));
            self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'));
            self::assertFalse($connection->createSchemaManager()->tablesExist(['native_migration_probe']));
            self::assertSame($originalSql, $original->toSql($connection->getDatabasePlatform()));

            $this->execute($connection, array_reverse($classes), 'down', $configuration);

            self::assertSame($before, $connection->fetchAllAssociative('SELECT type, name, sql FROM sqlite_master ORDER BY type, name'));
            self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'));
            self::assertSame($originalSql, $original->toSql($connection->getDatabasePlatform()));
        } finally {
            $connection->close();
        }
    }

    #[Test]
    public function nativeUpgradeDryRunPreservesExistingRowsAndRealUpgradeAndRollbackKeepThem(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $this->factory($connection);
            $before = $connection->fetchAllAssociative('SELECT type, name, sql FROM sqlite_master ORDER BY type, name');
            $this->execute($connection, [NativeCreateTableMigrationFixture::class], 'up', new MigratorConfiguration());
            $rows = [['id' => 7, 'label' => 'kept']];
            self::assertSame($rows, $connection->fetchAllAssociative('SELECT id, label FROM native_migration_probe'));
            self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'));
            $installed = $connection->fetchAllAssociative('SELECT type, name, sql FROM sqlite_master ORDER BY type, name');

            $this->execute($connection, [NativeAddColumnMigrationFixture::class], 'up', new MigratorConfiguration()->setDryRun(true));

            self::assertSame($installed, $connection->fetchAllAssociative('SELECT type, name, sql FROM sqlite_master ORDER BY type, name'));
            self::assertSame($rows, $connection->fetchAllAssociative('SELECT id, label FROM native_migration_probe'));
            self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'));

            $this->execute($connection, [NativeAddColumnMigrationFixture::class], 'up', new MigratorConfiguration());

            self::assertSame(['id', 'label', 'note'], array_keys($connection->createSchemaManager()->listTableColumns('native_migration_probe')));
            self::assertSame([['id' => 7, 'label' => 'kept', 'note' => null]], $connection->fetchAllAssociative('SELECT id, label, note FROM native_migration_probe'));
            self::assertSame(2, $connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'));

            $this->execute($connection, [NativeAddColumnMigrationFixture::class], 'down', new MigratorConfiguration());

            self::assertSame(['id', 'label'], array_keys($connection->createSchemaManager()->listTableColumns('native_migration_probe')));
            self::assertSame($rows, $connection->fetchAllAssociative('SELECT id, label FROM native_migration_probe'));
            self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'));

            $this->execute($connection, [NativeCreateTableMigrationFixture::class], 'down', new MigratorConfiguration());

            self::assertSame($before, $connection->fetchAllAssociative('SELECT type, name, sql FROM sqlite_master ORDER BY type, name'));
            self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'));
        } finally {
            $connection->close();
        }
    }

    private function factory(Connection $connection): DependencyFactory
    {
        $factory = DependencyFactory::fromConnection(new ExistingConfiguration(new Configuration()), new ExistingConnection($connection));
        $factory->getMetadataStorage()->ensureInitialized();

        return $factory;
    }

    /** @param list<class-string<AbstractMigration>> $classes */
    private function execute(Connection $connection, array $classes, string $direction, MigratorConfiguration $configuration): void
    {
        $factory = $this->factory($connection);
        $plans = [];
        foreach ($classes as $class) {
            $plans[] = new MigrationPlan(new Version($class), $factory->getMigrationFactory()->createVersion($class), $direction);
        }
        $factory->getMigrator()->migrate(new MigrationPlanList($plans, $direction), $configuration);
    }
}

final class NativeCreateTableMigrationFixture extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $table = Table::editor()->setUnquotedName('native_migration_probe')
            ->addColumn(Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->create())
            ->addColumn(Column::editor()->setUnquotedName('label')->setTypeName(Types::STRING)->setLength(8)->create())
            ->create();
        foreach ($this->platform->getCreateTableSQL($table) as $sql) {
            $this->addSql($sql);
        }
        $this->addSql('INSERT INTO native_migration_probe (id, label) VALUES (?, ?)', [7, 'kept'], [Types::INTEGER, Types::STRING]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->platform->getDropTableSQL('native_migration_probe'));
    }
}

final class NativeAddColumnMigrationFixture extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE native_migration_probe ADD COLUMN note VARCHAR(8) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE native_migration_probe DROP COLUMN note');
    }
}
