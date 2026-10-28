<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Persistence\Doctrine;

use App\Migrations\Version20261021000001;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversNothing]
final class RescueCredentialMigrationTest extends TestCase
{
    #[Test]
    public function upgradeLeavesExistingAccountsWithoutCredentialsAndPreservesSearchIndex(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE registered_nicks (id INTEGER PRIMARY KEY, nickname_lower VARCHAR(32) NOT NULL)');
        $connection->executeStatement('CREATE INDEX idx_registered_nicks_list_pattern ON registered_nicks (nickname_lower COLLATE NOCASE)');
        $connection->insert('registered_nicks', ['id' => 1, 'nickname_lower' => 'existing']);
        $schema = $connection->createSchemaManager()->introspectSchema();
        $migration = new Version20261021000001($connection, new NullLogger());
        self::assertNotSame('', $migration->getDescription());
        $migration->preUp($schema);
        $migration->up($schema);
        self::assertFalse($schema->getTable('registered_nicks')->hasColumn('token'));
        foreach ($migration->getSql() as $query) {
            self::assertSame([], $query->getParameters());
            self::assertSame([], $query->getTypes());
            $connection->executeStatement($query->getStatement());
        }
        $target = $connection->createSchemaManager()->introspectSchema();
        self::assertSame(64, $target->getTable('registered_nicks')->getColumn('token')->getLength());
        self::assertSame([['id' => 1, 'nickname_lower' => 'existing', 'token' => null]], $connection->fetchAllAssociative('SELECT * FROM registered_nicks'));
        self::assertNotFalse($connection->fetchOne("SELECT name FROM sqlite_master WHERE name = 'idx_registered_nicks_list_pattern'"));
        $rollback = new Version20261021000001($connection, new NullLogger());
        $rollback->preDown($target);
        $rollback->down($target);
        foreach ($rollback->getSql() as $query) {
            self::assertSame([], $query->getParameters());
            self::assertSame([], $query->getTypes());
            $connection->executeStatement($query->getStatement());
        }
        self::assertSame([['id' => 1, 'nickname_lower' => 'existing']], $connection->fetchAllAssociative('SELECT * FROM registered_nicks'));
        self::assertNotFalse($connection->fetchOne("SELECT name FROM sqlite_master WHERE name = 'idx_registered_nicks_list_pattern'"));
        $connection->close();
    }
}
