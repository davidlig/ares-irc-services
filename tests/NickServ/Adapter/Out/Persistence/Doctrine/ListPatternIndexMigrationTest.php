<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Persistence\Doctrine;

use App\Migrations\Version20260927000002;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SQLiteSchemaManager;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\Migrations\Query\Query;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversNothing]
final class ListPatternIndexMigrationTest extends TestCase
{
    #[Test]
    #[DataProvider('platforms')]
    public function createsNativeIndexesAndReversesOnlyTheirOwnExtensions(AbstractPlatform $platform): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('createSchemaManager')->willReturn($this->createStub(SQLiteSchemaManager::class));
        $connection->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci'],
            ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin'],
        );
        $migration = new Version20260927000002($connection, new NullLogger());
        $migration->up(new Schema());
        $sql = array_map(static fn (Query $query): string => $query->getStatement(), $migration->getSql());

        if ($platform instanceof AbstractMySQLPlatform) {
            self::assertSame([
                'ALTER TABLE registered_nicks ADD list_search_key VARCHAR(32) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_unicode_ci` GENERATED ALWAYS AS (LOWER(nickname_lower)) VIRTUAL',
                'CREATE INDEX idx_registered_nicks_list_pattern ON registered_nicks (list_search_key)',
                'ALTER TABLE registered_channels ADD list_search_key VARCHAR(64) CHARACTER SET `utf8mb4` COLLATE `utf8mb4_bin` GENERATED ALWAYS AS (LOWER(name_lower)) VIRTUAL',
                'CREATE INDEX idx_registered_channels_list_pattern ON registered_channels (list_search_key)',
            ], $sql);
        } else {
            $nickExpression = $platform instanceof SQLitePlatform ? 'nickname_lower COLLATE NOCASE' : 'LOWER(nickname_lower) text_pattern_ops';
            $channelExpression = $platform instanceof SQLitePlatform ? 'name_lower COLLATE NOCASE' : 'LOWER(name_lower) text_pattern_ops';
            self::assertSame([
                'CREATE INDEX idx_registered_nicks_list_pattern ON registered_nicks (' . $nickExpression . ')',
                'CREATE INDEX idx_registered_channels_list_pattern ON registered_channels (' . $channelExpression . ')',
            ], $sql);
        }

        $rollback = new Version20260927000002($connection, new NullLogger());
        $rollback->down(new Schema());
        $down = array_map(static fn (Query $query): string => $query->getStatement(), $rollback->getSql());
        $expected = [
            $platform->getDropIndexSQL('idx_registered_nicks_list_pattern', 'registered_nicks'),
            $platform->getDropIndexSQL('idx_registered_channels_list_pattern', 'registered_channels'),
        ];
        if ($platform instanceof AbstractMySQLPlatform) {
            $expected[] = 'ALTER TABLE registered_nicks DROP COLUMN list_search_key';
            $expected[] = 'ALTER TABLE registered_channels DROP COLUMN list_search_key';
        }
        self::assertSame($expected, $down);
        self::assertSame(!$platform instanceof AbstractMySQLPlatform, $migration->isTransactional());
    }

    /** @return iterable<string, array{AbstractPlatform}> */
    public static function platforms(): iterable
    {
        yield 'sqlite' => [new SQLitePlatform()];
        yield 'postgres' => [new PostgreSQLPlatform()];
        yield 'mysql' => [new MySQLPlatform()];
        yield 'mariadb' => [new MariaDBPlatform()];
    }

    #[Test]
    public function refusesUnknownSourceCollationsInsteadOfChangingSearchSemantics(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->method('createSchemaManager')->willReturn($this->createStub(SQLiteSchemaManager::class));
        $connection->method('fetchAssociative')->willReturn(false);
        $migration = new Version20260927000002($connection, new NullLogger());

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('Cannot determine the source collation for registered_nicks.nickname_lower');

        $migration->up(new Schema());
    }
}
