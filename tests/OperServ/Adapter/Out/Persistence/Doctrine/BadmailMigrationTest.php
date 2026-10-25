<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Persistence\Doctrine;

use App\Migrations\Version20261025000001;
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
final class BadmailMigrationTest extends TestCase
{
    #[DataProvider('platforms')]
    #[Test]
    public function portableQueuedMigrationAndDetachedTarget(AbstractPlatform $platform): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $manager = $this->createStub(SQLiteSchemaManager::class);
        $manager->method('createComparator')->willReturn(new Comparator($platform));
        $connection->method('createSchemaManager')->willReturn($manager);
        $migration = new Version20261025000001($connection, new NullLogger());
        self::assertSame('Create the OperServ blocked email domain list', $migration->getDescription());
        self::assertNull($migration->getEditedSchema());
        $schema = new Schema();
        $migration->preUp($schema);
        $migration->up($schema);
        self::assertFalse($schema->hasTable('operserv_badmail'));
        $target = $migration->getEditedSchema();
        self::assertNotNull($target);
        self::assertTrue($target->hasTable('operserv_badmail'));
        self::assertNotEmpty($migration->getSql());
        $migration->preDown($target);
        self::assertNull($migration->getEditedSchema());
        $migration->down($target);
        $down = $migration->getEditedSchema();
        self::assertNotNull($down);
        self::assertFalse($down->hasTable('operserv_badmail'));
        self::assertTrue($target->hasTable('operserv_badmail'));
    }

    #[Test]
    public function sqliteMigrationPreservesUnrelatedDataAndRollsBackOwnTableOnly(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE existing_data (id INTEGER PRIMARY KEY, value TEXT)');
        $connection->insert('existing_data', ['id' => 1, 'value' => 'preserve']);
        $schema = $connection->createSchemaManager()->introspectSchema();
        $migration = new Version20261025000001($connection, new NullLogger());
        $migration->up($schema);
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM operserv_badmail'));
        self::assertSame('preserve', $connection->fetchOne('SELECT value FROM existing_data'));
        $rollback = new Version20261025000001($connection, new NullLogger());
        $rollback->down($connection->createSchemaManager()->introspectSchema());
        foreach ($rollback->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        self::assertFalse($connection->createSchemaManager()->tablesExist(['operserv_badmail']));
        self::assertSame('preserve', $connection->fetchOne('SELECT value FROM existing_data'));
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
