<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Adapter\Out\Persistence\Migration;

use App\Migrations\Version20261027000001;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

#[CoversNothing]
final class ServiceMemoSenderMigrationTest extends TestCase
{
    #[Test]
    public function migrationPreservesHistoricalHumanMemosAndAddsNullableServiceSnapshot(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE memos (id INTEGER PRIMARY KEY, target_nick_id INTEGER DEFAULT NULL, target_channel_id INTEGER DEFAULT NULL, sender_nick_id INTEGER NOT NULL, message TEXT NOT NULL, created_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL)');
        $connection->executeStatement("INSERT INTO memos (id, target_nick_id, sender_nick_id, message, created_at) VALUES (1, 12, 7, 'historic', '2026-10-26 10:00:00')");
        $schema = $connection->createSchemaManager()->introspectSchema();
        $migration = new Version20261027000001($connection, new NullLogger());
        $migration->up($schema);
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }

        self::assertTrue(false === $connection->createSchemaManager()->introspectSchema()->getTable('memos')->getColumn('sender_nick_id')->getNotnull());
        self::assertTrue(false === $connection->createSchemaManager()->introspectSchema()->getTable('memos')->getColumn('sender_service_name')->getNotnull());
        self::assertSame(['sender_nick_id' => 7, 'sender_service_name' => null, 'message' => 'historic'], $connection->fetchAssociative('SELECT sender_nick_id, sender_service_name, message FROM memos WHERE id = 1'));

        $connection->executeStatement("INSERT INTO memos (target_nick_id, sender_nick_id, sender_service_name, message, created_at) VALUES (13, NULL, 'ChannelBot', 'new service memo', '2026-10-27 10:00:00')");
        self::assertSame(['sender_nick_id' => null, 'sender_service_name' => 'ChannelBot'], $connection->fetchAssociative('SELECT sender_nick_id, sender_service_name FROM memos WHERE id = 2'));
        $connection->close();
    }

    #[Test]
    public function migrationRollbackRefusesToDeleteServiceAuthoredMemos(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE memos (id INTEGER PRIMARY KEY, sender_nick_id INTEGER NOT NULL, message TEXT NOT NULL)');
        $connection->executeStatement("INSERT INTO memos (id, sender_nick_id, message) VALUES (1, 7, 'human')");
        $migration = new Version20261027000001($connection, new NullLogger());
        $schema = $connection->createSchemaManager()->introspectSchema();
        $migration->up($schema);
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
        $connection->executeStatement("INSERT INTO memos (sender_nick_id, sender_service_name, message) VALUES (NULL, 'ChannelBot', 'service')");

        $rollback = new Version20261027000001($connection, new NullLogger());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot remove service memo sender support');
        $rollback->down($connection->createSchemaManager()->introspectSchema());
    }
}
