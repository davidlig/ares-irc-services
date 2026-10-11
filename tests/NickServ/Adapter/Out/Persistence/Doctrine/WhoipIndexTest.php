<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Persistence\Doctrine;

use App\Migrations\Version20260927000001;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\MigrationPlan;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

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

        $this->applyMigration('up');
        $this->assertWhoipIndexExists();
        $this->assertWhoipQueryUsesIndex();
        self::assertSame($storedNicks, $connection->fetchAllAssociative('SELECT * FROM registered_nicks'));

        $this->applyMigration('down');
        self::assertFalse($connection->createSchemaManager()->introspectTable('registered_nicks')->hasIndex(self::INDEX_NAME));
        self::assertSame($storedNicks, $connection->fetchAllAssociative('SELECT * FROM registered_nicks'));

        $this->applyMigration('up');
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

    private function applyMigration(string $direction): void
    {
        $connection = $this->entityManager->getConnection();
        $factory = DependencyFactory::fromConnection(new ExistingConfiguration(new Configuration()), new ExistingConnection($connection));
        $factory->getMetadataStorage()->ensureInitialized();
        $class = Version20260927000001::class;
        $migration = $factory->getMigrationFactory()->createVersion($class);
        $plan = new MigrationPlan(new Version($class), $migration, $direction);
        $factory->getMigrator()->migrate(new MigrationPlanList([$plan], $direction), new MigratorConfiguration());
    }
}
