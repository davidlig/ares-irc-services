<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\Migrations\Version20261030000001;
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
final class AkickEnhancementMigrationTest extends TestCase
{
    #[Test]
    public function upgradeAndDowngradePreserveExistingRows(): void
    {
        $db = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $db->executeStatement('CREATE TABLE channel_akick (id INTEGER PRIMARY KEY, channel_id INTEGER NOT NULL, mask VARCHAR(255) NOT NULL)');
        $db->executeStatement('CREATE TABLE registered_channels (id INTEGER PRIMARY KEY, name VARCHAR(64) NOT NULL)');
        $db->insert('channel_akick', ['id' => 1, 'channel_id' => 10, 'mask' => '*!*@test.com']);
        $db->insert('registered_channels', ['id' => 10, 'name' => '#test']);

        $up = new Version20261030000001($db, new NullLogger());
        $up->up($db->createSchemaManager()->introspectSchema());
        foreach ($up->getSql() as $query) {
            $db->executeStatement($query->getStatement());
        }

        $akick = $db->fetchAssociative('SELECT * FROM channel_akick WHERE id = 1');
        self::assertIsArray($akick);
        self::assertSame('*!*@test.com', $akick['mask']);
        $uses = $akick['uses'];
        self::assertIsScalar($uses);
        self::assertSame(0, (int) $uses);
        self::assertNull($akick['last_used_at']);

        $channel = $db->fetchAssociative('SELECT * FROM registered_channels WHERE id = 10');
        self::assertIsArray($channel);
        self::assertSame('#test', $channel['name']);
        $verbose = $channel['verbose'];
        self::assertIsScalar($verbose);
        self::assertSame(1, (int) $verbose);

        $down = new Version20261030000001($db, new NullLogger());
        $down->down($db->createSchemaManager()->introspectSchema());
        foreach ($down->getSql() as $query) {
            $db->executeStatement($query->getStatement());
        }

        self::assertSame('*!*@test.com', $db->fetchOne('SELECT mask FROM channel_akick WHERE id = 1'));
        self::assertSame('#test', $db->fetchOne('SELECT name FROM registered_channels WHERE id = 10'));
        $db->close();
    }

    #[DataProvider('platforms')]
    #[Test]
    public function portableMigrationGeneratesExpectedSql(AbstractPlatform $platform): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('createSchemaManager')->willReturn($this->createStub(SQLiteSchemaManager::class));
        $before = new Schema([
            Table::editor()->setUnquotedName('channel_akick')->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->create())->create(),
            Table::editor()->setUnquotedName('registered_channels')->addColumn(Column::editor()->setUnquotedName('id')->setTypeName('integer')->create())->create(),
        ]);
        $beforeSql = $before->toSql($platform);

        $migration = new Version20261030000001($connection, new NullLogger());
        self::assertNotEmpty($migration->getDescription());
        $migration->preUp($before);
        $migration->up($before);
        self::assertSame($beforeSql, $before->toSql($platform));

        $sql = implode("\n", array_map(static fn ($query): string => $query->getStatement(), $migration->getSql()));
        self::assertStringContainsString('uses', $sql);
        self::assertStringContainsString('last_used_at', $sql);
        self::assertStringContainsString('verbose', $sql);

        $rollback = new Version20261030000001($connection, new NullLogger());
        $rollback->preDown($before);
        $rollback->down($before);
        $drop = $platform instanceof SQLitePlatform ? 'DROP COLUMN ' : 'DROP ';
        self::assertSame([
            'ALTER TABLE channel_akick ' . $drop . 'uses',
            'ALTER TABLE channel_akick ' . $drop . 'last_used_at',
            'ALTER TABLE registered_channels ' . $drop . $platform->quoteSingleIdentifier('verbose'),
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
