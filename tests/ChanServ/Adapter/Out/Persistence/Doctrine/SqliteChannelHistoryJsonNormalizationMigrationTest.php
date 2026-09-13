<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\Migrations\Version20260913150000;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

use function dirname;

#[CoversNothing]
final class SqliteChannelHistoryJsonNormalizationMigrationTest extends DoctrineIntegrationTestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 6) . '/migrations/Version20260913150000.php';

        parent::setUp();
    }

    #[Test]
    public function legacyJsonDeclarationIsRebuiltAsCanonicalClobPreservingRows(): void
    {
        $connection = $this->entityManager->getConnection();
        $this->createLegacyTable($connection);

        $migration = new Version20260913150000($connection, new NullLogger());
        $migration->up(new Schema());
        $this->execute($connection, $migration);

        self::assertSame('CLOB', $this->declaredExtraDataType($connection));
        self::assertSame(
            [
                ['id' => 1, 'message' => 'first', 'extra_data' => '{"host":"a.test"}'],
                ['id' => 2, 'message' => 'second', 'extra_data' => null],
            ],
            $connection->fetchAllAssociative('SELECT id, message, extra_data FROM channel_history ORDER BY id'),
        );
        self::assertSame(
            ['idx_ch_history_channel_id', 'idx_ch_history_performed_at'],
            $connection->fetchFirstColumn(
                "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'channel_history' AND name LIKE 'idx_ch_history_%' ORDER BY name",
            ),
        );
        self::assertSame(
            Types::TEXT,
            Type::lookupName(
                $connection->createSchemaManager()->listTableColumns('channel_history')['extra_data']->getType(),
            ),
        );
    }

    #[Test]
    public function canonicalSchemaIsLeftUntouched(): void
    {
        $connection = $this->entityManager->getConnection();
        $migration = new Version20260913150000($connection, new NullLogger());

        $migration->up(new Schema());

        self::assertSame([], $migration->getSql());
        self::assertSame('CLOB', $this->declaredExtraDataType($connection));
    }

    #[Test]
    public function missingTableIsIgnored(): void
    {
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DROP TABLE channel_history');

        $migration = new Version20260913150000($connection, new NullLogger());
        $migration->up(new Schema());

        self::assertSame([], $migration->getSql());
    }

    #[Test]
    public function otherPlatformsAreNotTouched(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects($this->never())->method('fetchOne');

        $migration = new Version20260913150000($connection, new NullLogger());
        $migration->up(new Schema());

        self::assertSame([], $migration->getSql());
    }

    #[Test]
    public function downgradeIsRejected(): void
    {
        $migration = new Version20260913150000($this->entityManager->getConnection(), new NullLogger());

        $this->expectException(IrreversibleMigration::class);
        $migration->down(new Schema());
    }

    private function createLegacyTable(Connection $connection): void
    {
        $connection->executeStatement('DROP TABLE channel_history');
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE channel_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                channel_id INTEGER NOT NULL,
                "action" VARCHAR(50) NOT NULL,
                performed_by VARCHAR(32) NOT NULL,
                performed_by_nick_id INTEGER DEFAULT NULL,
                performed_at DATETIME NOT NULL,
                message VARCHAR(512) NOT NULL,
                extra_data JSON DEFAULT NULL
            )
            SQL);
        $connection->executeStatement('CREATE INDEX idx_ch_history_channel_id ON channel_history (channel_id)');
        $connection->executeStatement('CREATE INDEX idx_ch_history_performed_at ON channel_history (performed_at)');
        $connection->insert('channel_history', [
            'id' => 1,
            'channel_id' => 10,
            'action' => 'access.add',
            'performed_by' => 'Oper',
            'performed_at' => '2026-09-13 15:00:00',
            'message' => 'first',
            'extra_data' => '{"host":"a.test"}',
        ]);
        $connection->insert('channel_history', [
            'id' => 2,
            'channel_id' => 11,
            'action' => 'access.del',
            'performed_by' => 'Oper',
            'performed_at' => '2026-09-13 15:01:00',
            'message' => 'second',
        ]);
    }

    private function execute(Connection $connection, AbstractMigration $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $connection->executeStatement($query->getStatement());
        }
    }

    private function declaredExtraDataType(Connection $connection): string
    {
        $declaration = $connection->fetchOne(
            "SELECT type FROM pragma_table_info('channel_history') WHERE name = 'extra_data'",
        );

        self::assertIsString($declaration);

        return $declaration;
    }
}
