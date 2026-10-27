<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\Migrations\Version20261026000001;
use App\Migrations\Version20261027000001;
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
    public function removalMigrationDropsOnlyAuditAndRecreatesItsHistoricalSchema(AbstractPlatform $platform): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $manager = $this->createStub(SQLiteSchemaManager::class);
        $manager->method('createComparator')->willReturn(new Comparator($platform));
        $connection->method('createSchemaManager')->willReturn($manager);
        $schema = new Schema();
        $schema->createTable('registered_channels')->addColumn('id', 'integer');
        $legacy = new Version20261026000001($connection, new NullLogger());
        $legacy->up($schema);
        $before = $legacy->getEditedSchema();
        self::assertNotNull($before);
        $migration = new Version20261027000001($connection, new NullLogger());
        self::assertNotEmpty($migration->getDescription());
        self::assertNull($migration->getEditedSchema());
        $migration->preUp($before);
        $migration->up($before);
        $target = $migration->getEditedSchema();
        self::assertNotNull($target);
        self::assertTrue($before->hasTable('channel_registration_audit'));
        self::assertFalse($target->hasTable('channel_registration_audit'));
        self::assertTrue($target->hasTable('chanserv_registration_policy'));
        self::assertTrue($target->getTable('registered_channels')->hasColumn('registration_restricted_until'));
        self::assertCount(1, $migration->getSql());
        $migration->preDown($target);
        self::assertNull($migration->getEditedSchema());
        $migration->down($target);
        $restored = $migration->getEditedSchema();
        self::assertNotNull($restored);
        self::assertFalse($target->hasTable('channel_registration_audit'));
        self::assertTrue(new Comparator($platform)->compareSchemas($before, $restored)->isEmpty());
    }

    #[Test]
    public function sqliteRemovalPreservesRegistrationAndUnrelatedRowsAndDownRestoresAnEmptyTable(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE registered_channels (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->executeStatement('CREATE TABLE unrelated_data (id INTEGER PRIMARY KEY, value TEXT)');
        $legacy = new Version20261026000001($connection, new NullLogger());
        $legacy->up($connection->createSchemaManager()->introspectSchema());
        foreach ($legacy->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        $connection->insert('registered_channels', ['id' => 1, 'name' => '#preserve', 'registration_rejection_reason' => 'Reason', 'registration_rejected_by_account_id' => 9, 'registration_rejected_by_nickname' => 'OperNick', 'registration_rejected_at' => '2026-10-27 12:00:00', 'registration_restricted_until' => '2026-10-28 12:00:00']);
        $connection->insert('chanserv_registration_policy', ['id' => 1, 'mode' => 'APPROVAL']);
        $connection->insert('unrelated_data', ['id' => 1, 'value' => 'preserve']);
        $connection->insert('channel_registration_audit', ['channel_name' => '#preserve', 'requester_account_id' => 7, 'operator_account_id' => 9, 'operator_nickname' => 'OperNick', 'accepted' => false, 'rejection_reason' => 'Reason', 'restricted_until' => null, 'occurred_at' => '2026-10-27 12:00:00']);
        $before = [];
        foreach (['registered_channels', 'chanserv_registration_policy', 'unrelated_data'] as $table) {
            $before[$table] = $connection->fetchAllAssociative('SELECT * FROM ' . $table);
        }
        $migration = new Version20261027000001($connection, new NullLogger());
        $migration->up($connection->createSchemaManager()->introspectSchema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        self::assertFalse($connection->createSchemaManager()->tablesExist(['channel_registration_audit']));
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $connection->fetchAllAssociative('SELECT * FROM ' . $table));
        }
        $down = new Version20261027000001($connection, new NullLogger());
        $down->down($connection->createSchemaManager()->introspectSchema());
        foreach ($down->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM channel_registration_audit'));
        foreach ($before as $table => $rows) {
            self::assertSame($rows, $connection->fetchAllAssociative('SELECT * FROM ' . $table));
        }
        $connection->close();
    }

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
