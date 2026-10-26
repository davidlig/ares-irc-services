<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\Migrations\Version20261026000001;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SQLiteSchemaManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversNothing]
final class RegistrationApprovalMigrationTest extends TestCase
{
    #[DataProvider('platforms')]
    #[Test]
    public function portableMigrationAndDetachedTarget(AbstractPlatform $platform): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $manager = $this->createStub(SQLiteSchemaManager::class);
        $manager->method('createComparator')->willReturn(new Comparator($platform));
        $connection->method('createSchemaManager')->willReturn($manager);
        $migration = new Version20261026000001($connection, new NullLogger());
        $schema = new Schema();
        $schema->createTable('registered_channels')->addColumn('id', 'integer');
        $migration->preUp($schema);
        $migration->up($schema);
        self::assertFalse($schema->hasTable('chanserv_registration_policy'));
        self::assertFalse($schema->getTable('registered_channels')->hasColumn('registration_rejection_reason'));
        $target = $migration->getEditedSchema();
        self::assertNotNull($target);
        self::assertTrue($target->hasTable('chanserv_registration_policy'));
        self::assertTrue($target->hasTable('channel_registration_audit'));
        self::assertTrue($target->getTable('registered_channels')->hasColumn('registration_restricted_until'));
        self::assertNotEmpty($migration->getSql());
        $migration->preDown($target);
        $migration->down($target);
        $down = $migration->getEditedSchema();
        self::assertNotNull($down);
        self::assertFalse($down->hasTable('chanserv_registration_policy'));
        self::assertFalse($down->hasTable('channel_registration_audit'));
        self::assertFalse($down->getTable('registered_channels')->hasColumn('registration_rejection_reason'));
    }

    #[Test]
    public function sqliteMigrationPreservesExistingRowsAndRollbackDropsOnlyItsOwnSchema(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE registered_channels (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->executeStatement('CREATE TABLE unrelated_data (id INTEGER PRIMARY KEY, value TEXT)');
        $connection->insert('registered_channels', ['id' => 1, 'name' => '#preserve']);
        $connection->insert('unrelated_data', ['id' => 1, 'value' => 'preserve']);
        $migration = new Version20261026000001($connection, new NullLogger());
        $migration->up($connection->createSchemaManager()->introspectSchema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        self::assertSame('#preserve', $connection->fetchOne('SELECT name FROM registered_channels WHERE id = 1'));
        $connection->insert('channel_registration_audit', [
            'channel_name' => '#preserve',
            'requester_account_id' => 7,
            'operator_account_id' => 9,
            'operator_nickname' => 'OperNick',
            'accepted' => false,
            'rejection_reason' => 'Reason',
            'restricted_until' => null,
            'occurred_at' => '2026-10-26 12:00:00',
        ]);
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM channel_registration_audit'));
        $rollback = new Version20261026000001($connection, new NullLogger());
        $rollback->down($connection->createSchemaManager()->introspectSchema());
        foreach ($rollback->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        self::assertFalse($connection->createSchemaManager()->tablesExist(['chanserv_registration_policy', 'channel_registration_audit']));
        self::assertSame('#preserve', $connection->fetchOne('SELECT name FROM registered_channels WHERE id = 1'));
        self::assertSame('preserve', $connection->fetchOne('SELECT value FROM unrelated_data WHERE id = 1'));
        $connection->close();
    }

    /** @return iterable<string, array{AbstractPlatform}> */
    public static function platforms(): iterable
    {
        yield 'sqlite' => [new SQLitePlatform()];
        yield 'mysql' => [new MySQLPlatform()];
        yield 'mariadb' => [new MariaDBPlatform()];
        yield 'postgresql' => [new PostgreSQLPlatform()];
    }
}
