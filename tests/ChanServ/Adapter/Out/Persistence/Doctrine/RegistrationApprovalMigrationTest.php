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
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SQLiteSchemaManager;
use Doctrine\DBAL\Schema\Table;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function array_map;
use function implode;

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
        $connection->method('createSchemaManager')->willReturn($this->createStub(SQLiteSchemaManager::class));
        $before = new Schema([
            Table::editor()->setUnquotedName('registered_channels')->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->create())->create(),
            Table::editor()->setUnquotedName('nickserv_registration_policy')->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->create())->create(),
        ]);
        $beforeSql = $before->toSql($platform);
        $migration = new Version20261026000001($connection, new NullLogger());
        self::assertNotEmpty($migration->getDescription());
        $migration->preUp($before);
        $migration->up($before);
        self::assertSame($beforeSql, $before->toSql($platform));
        self::assertTrue($before->hasTable('nickserv_registration_policy'));
        self::assertFalse($before->hasTable('service_settings'));
        $sql = implode("\n", array_map(static fn ($query): string => $query->getStatement(), $migration->getSql()));
        foreach (['service_settings', 'nickserv_registration_policy', 'chanserv_registration_policy', 'channel_registration_audit'] as $unrelated) {
            self::assertStringNotContainsString($unrelated, $sql);
        }
        foreach (['registration_rejection_reason', 'registration_rejected_by_account_id', 'registration_rejected_by_nickname', 'registration_rejected_at', 'registration_restricted_until'] as $column) {
            self::assertStringContainsString($column, $sql);
        }
        $rollback = new Version20261026000001($connection, new NullLogger());
        $rollback->preDown($before);
        $rollback->down($before);
        self::assertSame([
            'ALTER TABLE registered_channels DROP COLUMN registration_rejection_reason',
            'ALTER TABLE registered_channels DROP COLUMN registration_rejected_by_account_id',
            'ALTER TABLE registered_channels DROP COLUMN registration_rejected_by_nickname',
            'ALTER TABLE registered_channels DROP COLUMN registration_rejected_at',
            'ALTER TABLE registered_channels DROP COLUMN registration_restricted_until',
        ], array_map(static fn ($query): string => $query->getStatement(), $rollback->getSql()));
        self::assertSame($beforeSql, $before->toSql($platform));
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
