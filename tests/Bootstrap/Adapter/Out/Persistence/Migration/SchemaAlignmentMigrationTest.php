<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Adapter\Out\Persistence\Migration;

use App\Migrations\Version20261025000002;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversNothing]
final class SchemaAlignmentMigrationTest extends TestCase
{
    #[DataProvider('platforms')]
    public function testPlatformSqlOnlyNeverRebuildsTablesAndRollbackTouchesOnlyOwnedIndexes(AbstractPlatform $platform): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $migration = new Version20261025000002($connection, new NullLogger());
        self::assertSame(!$platform instanceof AbstractMySQLPlatform, $migration->isTransactional());
        self::assertNotEmpty($migration->getDescription());
        $migration->up(new Schema());
        self::assertSame([
            'CREATE UNIQUE INDEX uniq_udb_records_block_identity ON udb_records (block, identity_hash)',
            'CREATE UNIQUE INDEX uniq_udb_block_states_block ON udb_block_states (block)',
            $platform->getDropIndexSQL('idx_pattern', 'forbidden_vhosts'),
        ], array_map(static fn ($sql): string => $sql->getStatement(), $migration->getSql()));
        $down = new Version20261025000002($connection, new NullLogger());
        $down->down(new Schema());
        self::assertSame([
            'CREATE INDEX idx_pattern ON forbidden_vhosts (pattern)',
            $platform->getDropIndexSQL('uniq_udb_records_block_identity', 'udb_records'),
            $platform->getDropIndexSQL('uniq_udb_block_states_block', 'udb_block_states'),
        ], array_map(static fn ($sql): string => $sql->getStatement(), $down->getSql()));
    }

    /** @return iterable<string, array{AbstractPlatform}> */
    public static function platforms(): iterable
    {
        yield 'sqlite' => [new SQLitePlatform()];
        yield 'postgresql SQL only' => [new PostgreSQLPlatform()];
        yield 'mysql SQL only' => [new MySQLPlatform()];
        yield 'mariadb SQL only' => [new MariaDBPlatform()];
    }
}
