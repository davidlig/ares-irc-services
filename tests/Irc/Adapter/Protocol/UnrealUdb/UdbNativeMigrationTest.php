<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbSchema;
use App\Migrations\Version20261011000001;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\Migrations\Metadata\MigrationPlan;
use Doctrine\Migrations\Metadata\MigrationPlanList;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function array_column;
use function array_map;
use function implode;
use function str_repeat;
use function strlen;

#[CoversClass(UdbSchema::class)]
final class UdbNativeMigrationTest extends TestCase
{
    #[Test]
    public function realExecutorBinaryUpgradeAndUtf8DowngradePreserveLiteralBackslashes(): void
    {
        $connection = $this->oldDatabase();
        $text = 'literal\\x4142 café';
        $connection->update('udb_records', ['value' => $text], ['id' => 2]);
        try {
            $this->migrate($connection, false);
            self::assertSame($text, $connection->fetchOne('SELECT value FROM udb_records WHERE id = 2'));
            self::assertSame(31, $connection->createSchemaManager()->listTableColumns('udb_staged_transfers')['txid']->getLength());
            $before = $connection->fetchAllAssociative('SELECT id, block, path, value FROM udb_records ORDER BY id');
            $this->migrate($connection, true, 'down');
            self::assertArrayHasKey('value_kind', $connection->createSchemaManager()->listTableColumns('udb_records'));
            $this->migrate($connection, false, 'down');
            self::assertSame($before, $connection->fetchAllAssociative('SELECT id, block, path, value FROM udb_records ORDER BY id'));
            self::assertSame('text', $connection->fetchOne('SELECT typeof(value) FROM udb_records WHERE id = 2'));
        } finally {
            $connection->close();
        }
    }

    #[Test]
    #[DataProvider('nonTextBytes')]
    public function downgradeRefusesLossyBinaryValuesWithoutChangingSchemaOrRows(string $bytes): void
    {
        $connection = $this->oldDatabase();
        try {
            $this->migrate($connection, false);
            $connection->update('udb_records', ['value' => $bytes], ['id' => 2], ['value' => Types::BINARY]);
            foreach ([true, false] as $dryRun) {
                try {
                    $this->migrate($connection, $dryRun, 'down');
                    self::fail('A non-text binary value must make downgrade fail closed.');
                } catch (AbortMigration $failure) {
                    self::assertStringContainsString('losslessly downgraded', $failure->getMessage());
                }
                self::assertSame($bytes, $connection->fetchOne('SELECT value FROM udb_records WHERE id = 2'));
                self::assertArrayHasKey('value_kind', $connection->createSchemaManager()->listTableColumns('udb_records'));
                self::assertTrue($connection->createSchemaManager()->tablesExist(['udb_staged_records']));
            }
        } finally {
            $connection->close();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function nonTextBytes(): iterable
    {
        yield 'NUL' => ["fixture\0suffix"];
        yield 'invalid UTF8' => ["fixture\xff"];
    }

    #[Test]
    public function oversizedLegacyValueCannotBeTruncatedByBinaryUpgrade(): void
    {
        $connection = $this->oldDatabase();
        try {
            $connection->update('udb_records', ['value' => str_repeat('x', 4097)], ['id' => 2]);
            $this->expectException(AbortMigration::class);
            $this->expectExceptionMessage('exceeds the native limit');
            $this->migrate($connection, false);
        } finally {
            $value = $connection->fetchOne('SELECT value FROM udb_records WHERE id = 2');
            self::assertIsString($value);
            self::assertSame(4097, strlen($value));
            self::assertFalse($connection->createSchemaManager()->tablesExist(['udb_staged_records']));
            $connection->close();
        }
    }

    #[Test]
    #[DataProvider('binaryPlatforms')]
    public function platformPlansDeclareBinaryValuesAndPreserveExplicitPostgresEncoding(AbstractPlatform $platform): void
    {
        $database = $this->oldDatabase();
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $manager = $this->createStub(AbstractSchemaManager::class);
        $manager->method('createComparator')->willReturn(new Comparator($platform));
        $connection->method('createSchemaManager')->willReturn($manager);
        $connection->method('executeQuery')->willReturnCallback(static fn (string $sql) => $database->executeQuery($sql));
        try {
            $schema = $database->createSchemaManager()->introspectSchema();
            $migration = new Version20261011000001($connection, new NullLogger());
            $migration->up($schema);
            $statements = array_map(static fn ($query): string => $query->getStatement(), $migration->getSql());
            $sql = implode("\n", $statements);
            self::assertStringContainsString('VARCHAR(31)', $sql);
            self::assertStringNotContainsString('txid VARCHAR(8)', $sql);
            if ($platform instanceof PostgreSQLPlatform) {
                self::assertSame("ALTER TABLE udb_records ALTER COLUMN value TYPE BYTEA USING convert_to(value, 'UTF8')", $statements[0]);
                self::assertStringContainsString('value BYTEA', $sql);
            } else {
                self::assertStringContainsString('VARBINARY(4096)', $sql);
            }
            // Planning must not execute the conversion, even with real query rows.
            self::assertArrayNotHasKey('value_kind', $database->createSchemaManager()->listTableColumns('udb_records'));
        } finally {
            $database->close();
        }
    }

    /** @return iterable<string, array{AbstractPlatform}> */
    public static function binaryPlatforms(): iterable
    {
        yield 'postgresql SQL only' => [new PostgreSQLPlatform()];
        yield 'mysql SQL only' => [new MySQLPlatform()];
        yield 'mariadb SQL only' => [new MariaDBPlatform()];
    }

    #[Test]
    public function realExecutorAppliesDdlBeforeBackfillAndPreservesLogicalBytes(): void
    {
        $connection = $this->oldDatabase();
        try {
            $this->migrate($connection, false);
            self::assertTrue($connection->createSchemaManager()->tablesExist(['udb_staged_generations', 'udb_staged_transfers', 'udb_staged_records', 'udb_staged_mutations']));
            $rows = $connection->fetchAllAssociative('SELECT path, value, value_kind FROM udb_records ORDER BY id');
            self::assertSame(['*12', '*12', '*secret', '*42', '*7', ''], array_column($rows, 'value'));
            self::assertSame([2, 1, 1, 1, 2, 0], array_column($rows, 'value_kind'));
        } finally {
            $connection->close();
        }
    }

    #[Test]
    public function actualExecutorDryRunLeavesOldSchemaAndRowsUnchanged(): void
    {
        $connection = $this->oldDatabase();
        try {
            $before = $connection->fetchAllAssociative('SELECT * FROM udb_records ORDER BY id');
            $this->migrate($connection, true);
            self::assertFalse($connection->createSchemaManager()->tablesExist(['udb_staged_generations']));
            self::assertArrayNotHasKey('value_kind', $connection->createSchemaManager()->listTableColumns('udb_records'));
            self::assertSame($before, $connection->fetchAllAssociative('SELECT * FROM udb_records ORDER BY id'));
        } finally {
            $connection->close();
        }
    }

    private function oldDatabase(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE udb_records (id INTEGER PRIMARY KEY AUTOINCREMENT, block VARCHAR(1) NOT NULL, path VARCHAR(8192) NOT NULL, value TEXT NOT NULL)');
        $connection->executeStatement('CREATE TABLE udb_authority_state (id INTEGER PRIMARY KEY, approved BOOLEAN NOT NULL, approved_at DATETIME DEFAULT NULL, fingerprint VARCHAR(64) DEFAULT NULL, protocol_revision VARCHAR(40) DEFAULT NULL)');
        foreach ([['C', '#test::access::alice', '*12'], ['N', 'alice::pass', '*12'], ['N', 'bob::pass', '*secret'], ['N', 'carol::vhost', '*42'], ['I', '192.0.2.1::clones', '*7'], ['C', '#test::access', '']] as [$block, $path, $value]) {
            $connection->insert('udb_records', ['block' => $block, 'path' => $path, 'value' => $value]);
        }

        return $connection;
    }

    private function migrate(Connection $connection, bool $dryRun, string $direction = 'up'): void
    {
        $factory = DependencyFactory::fromConnection(new ExistingConfiguration(new Configuration()), new ExistingConnection($connection));
        $factory->getMetadataStorage()->ensureInitialized();
        $migration = $factory->getMigrationFactory()->createVersion(Version20261011000001::class);
        $plan = new MigrationPlan(new Version(Version20261011000001::class), $migration, $direction);
        $factory->getMigrator()->migrate(new MigrationPlanList([$plan], $direction), new MigratorConfiguration()->setDryRun($dryRun));
    }
}
