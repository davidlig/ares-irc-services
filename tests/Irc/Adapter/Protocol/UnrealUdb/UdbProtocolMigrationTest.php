<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Migrations\Version20260913160000;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

use function dirname;

#[CoversNothing]
final class UdbProtocolMigrationTest extends DoctrineIntegrationTestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 5) . '/migrations/Version20260913160000.php';

        parent::setUp();
    }

    #[Test]
    public function migrationUsesBinaryIdentityStorageOnEveryDatabasePlatform(): void
    {
        $schema = new Schema();
        $records = $schema->createTable('udb_records');
        $records->addColumn('identity_path', 'string', ['length' => 8192]);

        $states = $schema->createTable('udb_block_states');
        $states->addColumn('checksum', 'string', ['length' => 8]);
        $states->addColumn('synced_at', 'datetimetz_immutable');

        $authority = $schema->createTable('udb_authority_state');

        $migration = new Version20260913160000($this->entityManager->getConnection(), new NullLogger());
        $migration->up($schema);

        self::assertSame(Types::BINARY, Type::lookupName($records->getColumn('identity_path')->getType()));

        $migration->down($schema);

        self::assertSame(Types::STRING, Type::lookupName($records->getColumn('identity_path')->getType()));
        self::assertFalse($authority->hasColumn('protocol_revision'));
    }

    #[Test]
    public function postgresqlIdentityConversionsUseExplicitBidirectionalCasts(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->createStub(AbstractSchemaManager::class));
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $migration = new Version20260913160000($connection, new NullLogger());
        $schema = $this->legacySchema();

        $migration->up($schema);
        $migration->down($schema);

        $sql = array_map(static fn ($query): string => $query->getStatement(), $migration->getSql());
        self::assertContains(
            "ALTER TABLE udb_records ALTER identity_path TYPE BYTEA USING convert_to(identity_path, 'UTF8')",
            $sql,
        );
        self::assertContains(
            "ALTER TABLE udb_records ALTER identity_path TYPE VARCHAR(8192) USING convert_from(identity_path, 'UTF8')",
            $sql,
        );
    }

    #[Test]
    public function postgresqlDowngradeReadsBinaryIdentitiesThroughUtf8Conversion(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($this->createStub(AbstractSchemaManager::class));
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects($this->once())
            ->method('fetchAllAssociative')
            ->with("SELECT id, block, convert_from(identity_path, 'UTF8') AS identity_path FROM udb_records")
            ->willReturn([]);

        new Version20260913160000($connection, new NullLogger())->preDown(new Schema());
    }

    #[Test]
    public function postMigrationClearsEverySqlOwnedRecordBeforeForcedReseed(): void
    {
        $connection = $this->entityManager->getConnection();
        $now = new DateTimeImmutable('2026-09-13 16:00:00');
        $records = [
            ['N', 'Nick::suspended', 'legacy suspension'],
            ['N', 'Nick::challenge', 'sha256'],
            ['N', 'Nick::pass', 'sha256:' . str_repeat('a', 64)],
            ['C', '#channel::suspended', '1'],
            ['C', '#channel::pass', 'sha256:' . str_repeat('b', 64)],
            ['C', '#channel::challenge', 'sha256'],
            ['C', '#channel::founder', 'Nick'],
            ['K', 'G::*@example.test', 'legacy root'],
            ['K', 'G::*@example.test::duration', '*3600'],
            ['K', 'F::legacy::reason', 'legacy filter'],
            ['I', '192.0.2.1::clones', '*0005'],
        ];

        foreach ($records as [$block, $path, $value]) {
            $connection->insert('udb_records', [
                'block' => $block,
                'path' => $path,
                'identity_path' => strtolower($path),
                'value' => $value,
                'updated_at' => $now,
            ], ['updated_at' => Types::DATETIMETZ_IMMUTABLE]);
        }

        $connection->insert('udb_block_states', [
            'block' => 'N',
            'checksum' => '12345678',
            'record_count' => 1,
            'modified_at' => $now,
        ], ['modified_at' => Types::DATETIMETZ_IMMUTABLE]);

        $migration = new Version20260913160000($connection, new NullLogger());
        $migration->postUp($connection->createSchemaManager()->introspectSchema());

        self::assertSame(
            [['block' => 'I', 'path' => '192.0.2.1::clones', 'value' => '*5']],
            $connection->fetchAllAssociative('SELECT block, path, value FROM udb_records ORDER BY block, path'),
        );
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM udb_block_states'));
    }

    #[Test]
    public function downgradeRejectsBinaryIdentitiesThatWouldCollapseWhenFolded(): void
    {
        $connection = $this->entityManager->getConnection();
        $now = new DateTimeImmutable('2026-09-13 16:00:00');

        foreach (['F::b64:QWJj::reason', 'F::b64:qwjj::reason'] as $path) {
            $connection->insert('udb_records', [
                'block' => 'K',
                'path' => $path,
                'identity_path' => 'f::' . substr($path, 3),
                'value' => 'reason',
                'updated_at' => $now,
            ], ['identity_path' => Types::BINARY, 'updated_at' => Types::DATETIMETZ_IMMUTABLE]);
        }

        $migration = new Version20260913160000($connection, new NullLogger());

        $this->expectException(IrreversibleMigration::class);
        $migration->preDown($connection->createSchemaManager()->introspectSchema());
    }

    private function legacySchema(): Schema
    {
        $schema = new Schema();
        $records = $schema->createTable('udb_records');
        $records->addColumn('identity_path', 'string', ['length' => 8192]);

        $states = $schema->createTable('udb_block_states');
        $states->addColumn('checksum', 'string', ['length' => 8]);
        $states->addColumn('synced_at', 'datetimetz_immutable');

        $schema->createTable('udb_authority_state');

        return $schema;
    }
}
