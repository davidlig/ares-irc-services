<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Persistence\Doctrine;

use App\Migrations\Version20261101000001;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversNothing]
final class AutomaticModesMigrationTest extends TestCase
{
    public function testUpgradeDefaultsExistingNicksToNullAndRollbackPreservesTheirOtherState(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE registered_nicks (id INTEGER PRIMARY KEY, nickname_lower VARCHAR(32) NOT NULL)');
        $connection->executeStatement('CREATE INDEX idx_modeuser_nick ON registered_nicks (nickname_lower)');
        $connection->insert('registered_nicks', ['id' => 1, 'nickname_lower' => 'pedro']);
        $schema = $connection->createSchemaManager()->introspectSchema();
        $migration = new Version20261101000001($connection, new NullLogger());
        self::assertNotSame('', $migration->getDescription());
        $migration->up($schema);
        self::assertFalse($schema->getTable('registered_nicks')->hasColumn('automatic_modes'));
        foreach ($migration->getSql() as $query) {
            self::assertSame([], $query->getParameters());
            self::assertSame([], $query->getTypes());
            $connection->executeStatement($query->getStatement());
        }
        self::assertSame([['id' => 1, 'nickname_lower' => 'pedro', 'automatic_modes' => null]], $connection->fetchAllAssociative('SELECT * FROM registered_nicks'));
        $rollback = new Version20261101000001($connection, new NullLogger());
        $rollback->down($connection->createSchemaManager()->introspectSchema());
        foreach ($rollback->getSql() as $query) {
            self::assertSame([], $query->getParameters());
            self::assertSame([], $query->getTypes());
            $connection->executeStatement($query->getStatement());
        }
        self::assertSame([['id' => 1, 'nickname_lower' => 'pedro']], $connection->fetchAllAssociative('SELECT * FROM registered_nicks'));
        self::assertNotFalse($connection->fetchOne("SELECT name FROM sqlite_master WHERE name = 'idx_modeuser_nick'"));
        $connection->close();
    }
}
