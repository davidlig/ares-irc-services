<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Adapter\Out\Persistence\Migration;

use App\Migrations\Version20261021000002;
use App\Migrations\Version20261026000002;
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
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversNothing]
final class ServiceSettingsMigrationTest extends TestCase
{
    #[DataProvider('platforms')]
    #[Test]
    public function portableSettingsMigrationDoesNotChangeChanServLifecycle(AbstractPlatform $platform): void
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
        $migration = new Version20261026000002($connection, new NullLogger());
        self::assertNotEmpty($migration->getDescription());
        self::assertNull($migration->getEditedSchema());
        $migration->preUp($before);
        $migration->up($before);
        $target = $migration->getEditedSchema();
        self::assertNotNull($target);
        self::assertTrue($before->hasTable('nickserv_registration_policy'));
        self::assertFalse($before->hasTable('service_settings'));
        self::assertTrue($target->hasTable('service_settings'));
        self::assertSame('json', $target->getTable('service_settings')->getColumn('setting_value')->getTypeName());
        foreach (['nickserv_registration_policy', 'chanserv_registration_policy', 'channel_registration_audit'] as $obsolete) {
            self::assertFalse($target->hasTable($obsolete));
        }
        self::assertFalse($target->getTable('registered_channels')->hasColumn('registration_restricted_until'));
        $sql = implode("\n", array_map(static fn ($query): string => $query->getStatement(), $migration->getSql()));
        self::assertStringContainsString("'chanserv', 'registration_mode', '\"DIRECT\"'", $sql);
        self::assertStringContainsString("'nickserv', 'registration_mode', '\"TOKEN\"'", $sql);
        self::assertStringNotContainsString('registered_channels', $sql);
        self::assertStringNotContainsString('chanserv_registration_policy', $sql);
        self::assertStringNotContainsString('channel_registration_audit', $sql);
        $migration->preDown($target);
        self::assertNull($migration->getEditedSchema());
        $migration->down($target);
        $restored = $migration->getEditedSchema();
        self::assertNotNull($restored);
        self::assertTrue(new Comparator($platform)->compareSchemas($before, $restored)->isEmpty());
    }

    #[Test]
    #[TestWith(['EMAIL'])]
    #[TestWith(['TOKEN'])]
    #[TestWith([null])]
    public function commonSettingsMigrationDiscardsPreviousNickServMode(?string $oldMode): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE registered_channels (id INTEGER PRIMARY KEY, name TEXT)');
        $connection->executeStatement('CREATE TABLE nickserv_registration_policy (id INTEGER PRIMARY KEY, mode VARCHAR(5) NOT NULL)');
        $connection->executeStatement('CREATE TABLE unrelated_data (id INTEGER PRIMARY KEY, value TEXT)');
        $connection->insert('registered_channels', ['id' => 1, 'name' => '#preserve']);
        $connection->insert('unrelated_data', ['id' => 1, 'value' => 'preserve']);
        if (null !== $oldMode) {
            $connection->insert('nickserv_registration_policy', ['id' => 1, 'mode' => $oldMode]);
        }
        $migration = new Version20261026000002($connection, new NullLogger());
        $migration->up($connection->createSchemaManager()->introspectSchema());
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        foreach (['nickserv_registration_policy', 'chanserv_registration_policy', 'channel_registration_audit'] as $obsolete) {
            self::assertFalse($connection->createSchemaManager()->tablesExist([$obsolete]));
        }
        self::assertSame([
            ['service_name' => 'chanserv', 'setting_name' => 'registration_mode', 'setting_value' => '"DIRECT"'],
            ['service_name' => 'nickserv', 'setting_name' => 'registration_mode', 'setting_value' => '"TOKEN"'],
        ], $connection->fetchAllAssociative('SELECT * FROM service_settings ORDER BY service_name'));
        foreach (['integer' => 25, 'float' => 1.0, 'boolean' => true] as $name => $value) {
            $connection->insert('service_settings', [
                'service_name' => 'operserv',
                'setting_name' => $name,
                'setting_value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            ]);
            $stored = $connection->fetchOne('SELECT setting_value FROM service_settings WHERE service_name = ? AND setting_name = ?', ['operserv', $name]);
            self::assertIsString($stored);
            self::assertSame($value, json_decode($stored, true, 512, JSON_THROW_ON_ERROR));
        }
        self::assertSame('#preserve', $connection->fetchOne('SELECT name FROM registered_channels WHERE id = 1'));
        self::assertSame('preserve', $connection->fetchOne('SELECT value FROM unrelated_data WHERE id = 1'));
        $rollback = new Version20261026000002($connection, new NullLogger());
        $rollback->down($connection->createSchemaManager()->introspectSchema());
        foreach ($rollback->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        self::assertFalse($connection->createSchemaManager()->tablesExist(['service_settings']));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM nickserv_registration_policy'));
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
