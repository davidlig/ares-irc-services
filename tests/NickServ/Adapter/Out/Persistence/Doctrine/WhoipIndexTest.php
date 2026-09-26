<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Persistence\Doctrine;

use App\Migrations\Version20260927000001;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

#[CoversNothing]
#[Group('integration')]
final class WhoipIndexTest extends DoctrineIntegrationTestCase
{
    private const string INDEX_NAME = 'idx_registered_nicks_whoip';

    #[Test]
    public function mappingCreatesAnIndexForTheIpFilterAndNicknameOrder(): void
    {
        $metadata = $this->entityManager->getClassMetadata(RegisteredNick::class);
        $index = $metadata->table['indexes'][self::INDEX_NAME] ?? null;

        self::assertIsArray($index);
        self::assertSame(
            ['last_connect_ip', 'nickname_lower', 'id'],
            $index['columns'] ?? null,
        );
        $this->assertWhoipIndexExists();
    }

    #[Test]
    public function migrationCanBeReversedAndReappliedWithoutChangingStoredNicks(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement($connection->getDatabasePlatform()->getDropIndexSQL(self::INDEX_NAME, 'registered_nicks'));
        $nick = RegisteredNick::createPending(
            'MigrationNick',
            'hash',
            'migration@example.com',
            'en',
            new DateTimeImmutable('2026-09-28'),
            new DateTimeImmutable('2026-09-27'),
        );
        $nick->updateLastConnection('203.0.113.7', 'test.example');
        $this->entityManager->persist($nick);
        $this->flushAndClear();
        $storedNicks = $connection->fetchAllAssociative('SELECT * FROM registered_nicks');
        $migration = new Version20260927000001($connection, new NullLogger());

        $this->applySchemaChange($migration->up(...));
        $this->assertWhoipIndexExists();
        $this->assertWhoipQueryUsesIndex();
        self::assertSame($storedNicks, $connection->fetchAllAssociative('SELECT * FROM registered_nicks'));

        $this->applySchemaChange($migration->down(...));
        self::assertFalse($connection->createSchemaManager()->introspectTable('registered_nicks')->hasIndex(self::INDEX_NAME));
        self::assertSame($storedNicks, $connection->fetchAllAssociative('SELECT * FROM registered_nicks'));

        $this->applySchemaChange($migration->up(...));
        $this->assertWhoipIndexExists();
        self::assertSame($storedNicks, $connection->fetchAllAssociative('SELECT * FROM registered_nicks'));
    }

    #[Test]
    public function whoipQueryUsesTheIndexWithoutScanningOrSorting(): void
    {
        $this->assertWhoipQueryUsesIndex();
    }

    private function assertWhoipQueryUsesIndex(): void
    {
        $query = $this->entityManager->createQuery(
            'SELECT n.nickname FROM App\\NickServ\\Domain\\Entity\\RegisteredNick n'
            . ' WHERE n.lastConnectIp = :ip ORDER BY n.nicknameLower ASC, n.id ASC',
        );
        $sql = $query->getSQL();
        self::assertIsString($sql);
        $rows = $this->entityManager->getConnection()->fetchAllAssociative('EXPLAIN QUERY PLAN ' . $sql, ['203.0.113.7']);
        $details = [];
        foreach ($rows as $row) {
            self::assertIsString($row['detail']);
            $details[] = $row['detail'];
        }
        $plan = implode("\n", $details);

        self::assertStringContainsString('SEARCH', $plan);
        self::assertStringContainsString(self::INDEX_NAME, $plan);
        self::assertStringNotContainsString('SCAN', $plan);
        self::assertStringNotContainsString('USE TEMP B-TREE', $plan);
    }

    private function assertWhoipIndexExists(): void
    {
        $table = $this->entityManager->getConnection()->createSchemaManager()->introspectTable('registered_nicks');
        self::assertTrue($table->hasIndex(self::INDEX_NAME));
        self::assertSame(['last_connect_ip', 'nickname_lower', 'id'], $table->getIndex(self::INDEX_NAME)->getColumns());
    }

    /** @param callable(Schema): void $change */
    private function applySchemaChange(callable $change): void
    {
        $connection = $this->entityManager->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $before = $schemaManager->introspectSchema();
        $after = clone $before;
        $change($after);

        $difference = $schemaManager->createComparator()->compareSchemas($before, $after);
        foreach ($connection->getDatabasePlatform()->getAlterSchemaSQL($difference) as $sql) {
            $connection->executeStatement($sql);
        }
    }
}
