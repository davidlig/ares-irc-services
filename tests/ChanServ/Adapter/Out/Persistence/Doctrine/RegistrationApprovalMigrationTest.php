<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\Migrations\Version20261021000002;
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
    #[Test]
    public function approvalUpgradeAndDowngradePreserveGlobalSettingsAndExistingRows(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE registered_channels (id INTEGER PRIMARY KEY, name TEXT)');
        $db->executeStatement('CREATE TABLE nickserv_registration_policy (id INTEGER PRIMARY KEY, mode VARCHAR(5) NOT NULL)');
        $db->executeStatement('CREATE TABLE service_settings (service_name VARCHAR(32) NOT NULL, setting_name VARCHAR(64) NOT NULL, setting_value TEXT NOT NULL, PRIMARY KEY (service_name, setting_name))');
        $db->insert('registered_channels', ['id' => 1, 'name' => '#preserve']);
        $db->insert('nickserv_registration_policy', ['id' => 1, 'mode' => 'EMAIL']);
        $db->insert('service_settings', ['service_name' => 'chanserv', 'setting_name' => 'registration_mode', 'setting_value' => '"APPROVAL"']);
        $beforePolicy = $db->fetchAllAssociative('SELECT * FROM nickserv_registration_policy');
        $beforeSettings = $db->fetchAllAssociative('SELECT * FROM service_settings');

        $up = new Version20261026000001($db, new NullLogger());
        $up->up($db->createSchemaManager()->introspectSchema());
        foreach ($up->getSql() as $query) {
            $db->executeStatement($query->getStatement());
        }
        self::assertSame($beforePolicy, $db->fetchAllAssociative('SELECT * FROM nickserv_registration_policy'));
        self::assertSame($beforeSettings, $db->fetchAllAssociative('SELECT * FROM service_settings'));
        self::assertSame('#preserve', $db->fetchOne('SELECT name FROM registered_channels WHERE id = 1'));

        $down = new Version20261026000001($db, new NullLogger());
        $down->down($db->createSchemaManager()->introspectSchema());
        foreach ($down->getSql() as $query) {
            $db->executeStatement($query->getStatement());
        }
        self::assertSame($beforePolicy, $db->fetchAllAssociative('SELECT * FROM nickserv_registration_policy'));
        self::assertSame($beforeSettings, $db->fetchAllAssociative('SELECT * FROM service_settings'));
        self::assertSame('#preserve', $db->fetchOne('SELECT name FROM registered_channels WHERE id = 1'));
        $db->close();
    }

    #[DataProvider('platforms')]
    #[Test]
    public function portableApprovalMigrationDoesNotTouchGlobalSettings(AbstractPlatform $platform): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $manager = $this->createStub(SQLiteSchemaManager::class);
        $manager->method('createComparator')->willReturn(new Comparator($platform));
        $connection->method('createSchemaManager')->willReturn($manager);
        $schema = new Schema();
        $schema->createTable('registered_channels')->addColumn('id', 'integer');
        $legacy = new Version20261021000002($connection, new NullLogger());
        $legacy->up($schema);
        $before = $legacy->getEditedSchema();
        self::assertNotNull($before);
        $migration = new Version20261026000001($connection, new NullLogger());
        self::assertNotEmpty($migration->getDescription());
        self::assertNull($migration->getEditedSchema());
        $migration->preUp($before);
        $migration->up($before);
        $target = $migration->getEditedSchema();
        self::assertNotNull($target);
        self::assertTrue($before->hasTable('nickserv_registration_policy'));
        self::assertFalse($before->hasTable('service_settings'));
        self::assertFalse($target->hasTable('service_settings'));
        self::assertTrue($target->hasTable('nickserv_registration_policy'));
        foreach (['chanserv_registration_policy', 'channel_registration_audit'] as $obsolete) {
            self::assertFalse($target->hasTable($obsolete));
        }
        self::assertTrue($target->getTable('registered_channels')->hasColumn('registration_restricted_until'));
        $sql = implode("\n", array_map(static fn ($query): string => $query->getStatement(), $migration->getSql()));
        self::assertStringNotContainsString('service_settings', $sql);
        self::assertStringNotContainsString('nickserv_registration_policy', $sql);
        self::assertStringNotContainsString('chanserv_registration_policy', $sql);
        self::assertStringNotContainsString('channel_registration_audit', $sql);
        $migration->preDown($target);
        self::assertNull($migration->getEditedSchema());
        $migration->down($target);
        $restored = $migration->getEditedSchema();
        self::assertNotNull($restored);
        self::assertTrue(new Comparator($platform)->compareSchemas($before, $restored)->isEmpty());
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
