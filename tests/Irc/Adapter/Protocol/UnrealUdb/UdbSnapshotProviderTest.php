<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation\UdbCapturedSnapshot;
use App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation\UdbSnapshotProvider;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbChecksum;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\TransactionIsolationLevel;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use UnexpectedValueException;

use function count;

#[CoversClass(UdbSnapshotProvider::class)]
final class UdbSnapshotProviderTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement('CREATE TABLE udb_authority_state (id INTEGER PRIMARY KEY, dataset_sid VARCHAR(3) NOT NULL, dataset_epoch VARCHAR(16) NOT NULL, dataset_watermark VARCHAR(20) NOT NULL)');
        $this->connection->executeStatement('CREATE TABLE udb_records (id INTEGER PRIMARY KEY, block VARCHAR(1) NOT NULL, path VARCHAR(8191) NOT NULL, value BLOB NOT NULL, value_kind INTEGER NOT NULL)');
        $this->connection->insert('udb_authority_state', [
            'id' => 1,
            'dataset_sid' => '002',
            'dataset_epoch' => '0123456789abcdef',
            'dataset_watermark' => '7',
        ]);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    #[Test]
    public function malformedDirectSqlBinaryValueFailsClosedWithTheSqlFieldDiagnostic(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('fetchAssociative')->willReturn(['dataset_sid' => '002', 'dataset_epoch' => '0123456789abcdef', 'dataset_watermark' => '7']);
        $connection->method('fetchAllAssociative')->willReturn([['id' => 1, 'path' => 'alice::swhois', 'value' => 7, 'value_kind' => 1]]);
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('invalid SQL fields');
        new UdbSnapshotProvider($connection)->capture();
    }

    #[Test]
    public function directSqlByteaResourceBecomesLogicalBytesBeforeSnapshotValidation(): void
    {
        $bytes = "fixture\0\xff";
        $stream = fopen('php://temp', 'w+');
        self::assertIsResource($stream);
        fwrite($stream, $bytes);
        rewind($stream);
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('fetchAssociative')->willReturn(['dataset_sid' => '002', 'dataset_epoch' => '0123456789abcdef', 'dataset_watermark' => '7']);
        $connection->method('fetchAllAssociative')->willReturnCallback(static fn (string $sql, array $params): array => 'N' === $params[0] && 0 === $params[1] ? [['id' => 1, 'path' => 'alice::swhois', 'value' => $stream, 'value_kind' => 1]] : []);
        try {
            $snapshot = new UdbSnapshotProvider($connection)->capture();
            try {
                self::assertTrue([['alice::swhois', UdbValue::string($bytes)]] == iterator_to_array($snapshot->records(UdbBlock::Nicks), false));
            } finally {
                $snapshot->close();
            }
        } finally {
            fclose($stream);
        }
    }

    #[Test]
    public function binaryLogicalValueIsCapturedWithoutTextOrByteaEscapeInterpretation(): void
    {
        $bytes = "fixture\0\xff\x80\\x4142";
        $this->connection->insert('udb_records', ['id' => 1, 'block' => 'N', 'path' => 'alice::swhois', 'value' => $bytes, 'value_kind' => 1], ['value' => Types::BINARY]);
        $snapshot = new UdbSnapshotProvider($this->connection)->capture();
        try {
            self::assertTrue([['alice::swhois', UdbValue::string($bytes)]] == iterator_to_array($snapshot->records(UdbBlock::Nicks), false));
            self::assertSame(UdbChecksum::fromMap(UdbBlock::Nicks, ['alice::swhois' => UdbValue::string($bytes)]), $snapshot->digest(UdbBlock::Nicks));
        } finally {
            $snapshot->close();
        }
    }

    #[Test]
    public function snapshotPinsOneCheckpointAndStreamsTypedRowsFromAnImmutableSpool(): void
    {
        $this->connection->insert('udb_records', [
            'id' => 1,
            'block' => 'I',
            'path' => '1.2.3.4::clones',
            'value' => '*5',
            'value_kind' => 2,
        ]);

        $snapshot = new UdbSnapshotProvider($this->connection)->capture();
        try {
            $this->connection->update('udb_records', ['value' => '*6'], ['id' => 1]);

            self::assertSame('002', $snapshot->checkpoint->origin->sid);
            self::assertSame('0123456789abcdef', $snapshot->checkpoint->origin->epoch);
            self::assertSame('7', (string) $snapshot->checkpoint->watermark);
            self::assertSame(1, $snapshot->count(UdbBlock::Ips));
            self::assertSame(UdbChecksum::fromMap(UdbBlock::Ips, ['1.2.3.4::clones' => UdbValue::number(UdbUnsignedDecimal::fromInt(5))]), $snapshot->digest(UdbBlock::Ips));
            self::assertTrue(
                [['1.2.3.4::clones', UdbValue::number(UdbUnsignedDecimal::fromInt(5))]] ==
                iterator_to_array($snapshot->records(UdbBlock::Ips), false),
            );
        } finally {
            $snapshot->close();
        }
    }

    #[Test]
    public function snapshotDistinguishesExplicitNoneFromAnEmptyString(): void
    {
        $directory = sys_get_temp_dir() . '/udb-typed-row-' . bin2hex(random_bytes(8));
        mkdir($directory, 0o700);
        $file = $directory . '/rows';
        $stream = fopen($file, 'x');
        self::assertNotFalse($stream);
        UdbCapturedSnapshot::appendRow($stream, 'alice', UdbValue::none());
        UdbCapturedSnapshot::appendRow($stream, 'alice::vhost', UdbValue::string(''));
        fclose($stream);
        try {
            self::assertTrue(
                [
                    ['alice', UdbValue::none()],
                    ['alice::vhost', UdbValue::string('')],
                ] ==
                iterator_to_array(UdbCapturedSnapshot::readFile($file), false),
            );
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }

    #[Test]
    public function missingCheckpointRollsBackTheCaptureTransaction(): void
    {
        $this->connection->executeStatement('DELETE FROM udb_authority_state');
        try {
            new UdbSnapshotProvider($this->connection)->capture();
            self::fail('A missing checkpoint was accepted.');
        } catch (UnexpectedValueException) {
            self::assertFalse($this->connection->isTransactionActive());
        }
    }

    /** @return iterable<string, array{string, string, int|string}> */
    public static function corruptedRows(): iterable
    {
        yield 'unknown kind' => ['clones', '*5', 99];
        yield 'malformed kind' => ['clones', '*5', 'invalid'];
        yield 'invalid number' => ['clones', '*bad', 2];
        yield 'unmarked number' => ['clones', '5', 2];
        yield 'wrong schema kind' => ['clones', '*5', 1];
        yield 'nonempty structural node' => ['clones', 'bad', 0];
        yield 'invalid schema path' => ['unknown', 'value', 1];
    }

    #[Test]
    #[DataProvider('corruptedRows')]
    public function corruptedPersistedRowsCannotProduceASnapshot(string $path, string $value, int|string $kind): void
    {
        $this->connection->insert('udb_records', ['id' => 1, 'block' => 'S', 'path' => $path, 'value' => $value, 'value_kind' => $kind]);
        try {
            new UdbSnapshotProvider($this->connection)->capture();
            self::fail('A corrupted row was accepted.');
        } catch (UnexpectedValueException) {
            self::assertFalse($this->connection->isTransactionActive());
        }
    }

    #[Test]
    public function invalidCheckpointCannotBecomeASnapshot(): void
    {
        $this->connection->executeStatement("UPDATE udb_authority_state SET dataset_watermark = 'invalid'");
        try {
            new UdbSnapshotProvider($this->connection)->capture();
            self::fail('An invalid checkpoint was accepted.');
        } catch (UnexpectedValueException) {
            self::assertFalse($this->connection->isTransactionActive());
        }
    }

    #[Test]
    public function integerBoundaryAcceptsDecimalSqlStringsAndRejectsOverflow(): void
    {
        $decode = new ReflectionMethod(UdbSnapshotProvider::class, 'sqlInt');
        self::assertSame(7, $decode->invoke(null, '7'));
        foreach ([null, 'bad', '9223372036854775808'] as $invalid) {
            try {
                $decode->invoke(null, $invalid);
                self::fail('Invalid SQL integer must not enter pagination.');
            } catch (UnexpectedValueException $error) {
                self::assertStringContainsString('integer', $error->getMessage());
            }
        }
    }

    #[Test]
    public function captureFailureRemovesPartialSpoolsAndRollsBack(): void
    {
        $before = glob(sys_get_temp_dir() . '/ares-udb4-*') ?: [];
        $this->connection->update('udb_authority_state', ['dataset_watermark' => 'bad'], ['id' => 1]);
        try {
            new UdbSnapshotProvider($this->connection)->capture();
            self::fail('An invalid checkpoint must prevent capture.');
        } catch (UnexpectedValueException $error) {
            self::assertStringContainsString('watermark', $error->getMessage());
        }
        self::assertFalse($this->connection->isTransactionActive());
        self::assertSame($before, glob(sys_get_temp_dir() . '/ares-udb4-*') ?: []);
    }

    #[Test]
    public function corruptPersistedRowsFailBeforeImmutableSnapshotPublication(): void
    {
        foreach ([[255, 'value'], [0, 'not-empty'], [2, '*bad'], [1, 'wrong']] as [$kind, $value]) {
            $this->connection->executeStatement('DELETE FROM udb_records');
            $this->connection->insert('udb_records', ['id' => 1, 'block' => 'I', 'path' => '192.0.2.1::clones', 'value' => $value, 'value_kind' => $kind]);
            try {
                new UdbSnapshotProvider($this->connection)->capture();
                self::fail('A corrupt row must not publish a snapshot.');
            } catch (UnexpectedValueException $error) {
                self::assertStringContainsString('persisted', $error->getMessage());
            }
            self::assertFalse($this->connection->isTransactionActive());
        }
    }

    #[Test]
    public function nonSqliteIsolationRequestsRepeatableReadWithoutClaimingPostgresRuntime(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects(self::once())->method('setTransactionIsolation')->with(TransactionIsolationLevel::REPEATABLE_READ);
        new ReflectionMethod(UdbSnapshotProvider::class, 'setConsistentIsolation')->invoke(new UdbSnapshotProvider($connection));
    }

    #[Test]
    public function unexpectedSqlFieldAndPartialFileFailureCleanAllPrivateSpools(): void
    {
        $before = glob(sys_get_temp_dir() . '/ares-udb4-*') ?: [];
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('fetchAssociative')->willReturnCallback(static function () use ($before): array {
            $new = array_values(array_diff(glob(sys_get_temp_dir() . '/ares-udb4-*') ?: [], $before));
            if (1 !== count($new)) {
                throw new RuntimeException('The private test spool must be unique.');
            }
            file_put_contents($new[0] . '/partial.rows', 'partial');

            return ['dataset_sid' => '002', 'dataset_epoch' => str_repeat('1', 16), 'dataset_watermark' => '0'];
        });
        $connection->method('fetchAllAssociative')->willReturn([['id' => 1, 'path' => 7, 'value' => 'x', 'value_kind' => 1]]);
        $this->expectException(UnexpectedValueException::class);
        try {
            new UdbSnapshotProvider($connection)->capture();
        } finally {
            self::assertSame($before, glob(sys_get_temp_dir() . '/ares-udb4-*') ?: []);
        }
    }
}
