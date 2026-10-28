<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Adapter\Out\Persistence\Migration;

use App\Migrations\Version20260919000001;
use App\Migrations\Version20260919000002;
use App\Migrations\Version20260927000001;
use Doctrine\DBAL\DriverManager;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function hash;

#[CoversNothing]
final class NativeIndexMigrationTest extends TestCase
{
    /**
     * @param class-string<AbstractMigration> $class
     * @param array<string, int|string|null>  $row
     * @param array<string, list<string>>     $indexes
     */
    #[Test]
    #[DataProvider('indexMigrations')]
    public function indexChangesPreserveLaterOwnedColumnsRowsAndNativeListIndexes(string $class, string $table, string $definition, array $row, array $indexes, string $listColumn): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        try {
            $connection->executeStatement($definition);
            $listIndex = 'idx_' . $table . '_list_pattern';
            $connection->executeStatement('CREATE INDEX ' . $listIndex . ' ON ' . $table . ' (' . $listColumn . ' COLLATE NOCASE)');
            $connection->insert($table, $row);
            $factory = DependencyFactory::fromConnection(new ExistingConfiguration(new Configuration()), new ExistingConnection($connection));
            $factory->getMetadataStorage()->ensureInitialized();
            $stored = $connection->fetchAllAssociative('SELECT * FROM ' . $table);
            $tableSql = $connection->fetchOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $table]);
            $listSql = $connection->fetchOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['index', $listIndex]);

            foreach (['up', 'down', 'up'] as $direction) {
                $schema = $connection->createSchemaManager()->introspectSchema();
                $inputSql = $schema->toSql($connection->getDatabasePlatform());
                $metadata = $connection->fetchAllAssociative('SELECT * FROM doctrine_migration_versions ORDER BY version');
                $planned = $factory->getMigrationFactory()->createVersion($class);
                $plan = new MigrationPlan(new Version($class), $planned, $direction);
                $factory->getMigrator()->migrate(new MigrationPlanList([$plan], $direction), new MigratorConfiguration()->setDryRun(true)->setFromSchema($schema));
                self::assertSame($inputSql, $schema->toSql($connection->getDatabasePlatform()));
                self::assertSame($metadata, $connection->fetchAllAssociative('SELECT * FROM doctrine_migration_versions ORDER BY version'));
                self::assertSame($stored, $connection->fetchAllAssociative('SELECT * FROM ' . $table));
                self::assertSame($tableSql, $connection->fetchOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $table]));
                self::assertCount(count($indexes), $planned->getSql());
                foreach ($planned->getSql() as $query) {
                    self::assertStringStartsWith('up' === $direction ? 'CREATE INDEX ' : 'DROP INDEX ', $query->getStatement());
                    self::assertSame([], $query->getParameters());
                    self::assertSame([], $query->getTypes());
                }

                $migration = $factory->getMigrationFactory()->createVersion($class);
                $actual = new MigrationPlan(new Version($class), $migration, $direction);
                $factory->getMigrator()->migrate(new MigrationPlanList([$actual], $direction), new MigratorConfiguration());
                self::assertSame($stored, $connection->fetchAllAssociative('SELECT * FROM ' . $table));
                self::assertSame($tableSql, $connection->fetchOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['table', $table]));
                self::assertSame($listSql, $connection->fetchOne('SELECT sql FROM sqlite_master WHERE type = ? AND name = ?', ['index', $listIndex]));
                self::assertSame('up' === $direction ? 1 : 0, $connection->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions'));
                $current = $connection->createSchemaManager()->introspectTable($table);
                foreach ($indexes as $name => $columns) {
                    self::assertSame('up' === $direction, $current->hasIndex($name));
                    if ('up' === $direction) {
                        self::assertSame($columns, $current->getIndex($name)->getColumns());
                    }
                }
            }
        } finally {
            $connection->close();
        }
    }

    /** @return iterable<string, array{class-string<AbstractMigration>, string, string, array<string, int|string|null>, array<string, list<string>>, string}> */
    public static function indexMigrations(): iterable
    {
        $channels = 'CREATE TABLE registered_channels (name VARCHAR(64) NOT NULL, name_lower VARCHAR(64) NOT NULL, status VARCHAR(16) NOT NULL, suspended_until DATETIME DEFAULT NULL, no_expire BOOLEAN NOT NULL, last_used_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, registration_restricted_until DATETIME DEFAULT NULL, id INTEGER PRIMARY KEY)';
        $channelRow = ['name' => '#preserve', 'name_lower' => '#preserve', 'status' => 'registered', 'suspended_until' => null, 'no_expire' => 0, 'last_used_at' => null, 'created_at' => '2026-09-28 10:00:00', 'registration_restricted_until' => '2030-01-01 00:00:00', 'id' => 17];
        yield 'channel keyset' => [Version20260919000001::class, 'registered_channels', $channels, $channelRow, ['idx_registered_channels_name_id' => ['name', 'id']], 'name_lower'];
        yield 'channel maintenance' => [Version20260919000002::class, 'registered_channels', $channels, $channelRow, ['idx_registered_channels_status_suspended_until' => ['status', 'suspended_until'], 'idx_registered_channels_inactivity' => ['no_expire', 'last_used_at', 'created_at']], 'name_lower'];
        yield 'nickname WHOIP' => [
            Version20260927000001::class,
            'registered_nicks',
            'CREATE TABLE registered_nicks (nickname_lower VARCHAR(32) NOT NULL, last_connect_ip VARCHAR(45) DEFAULT NULL, token VARCHAR(64) NOT NULL, id INTEGER PRIMARY KEY)',
            ['nickname_lower' => 'preservenick', 'last_connect_ip' => '203.0.113.7', 'token' => hash('sha256', 'owned-token-fixture'), 'id' => 19],
            ['idx_registered_nicks_whoip' => ['last_connect_ip', 'nickname_lower', 'id']],
            'nickname_lower',
        ];
    }
}
