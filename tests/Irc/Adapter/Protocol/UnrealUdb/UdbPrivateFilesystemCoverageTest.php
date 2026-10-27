<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation\UdbCapturedSnapshot;
use App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation\UdbSnapshotProvider;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbLogicalDigest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

use function fclose;
use function fopen;
use function iterator_to_array;
use function restore_error_handler;
use function set_error_handler;
use function str_repeat;
use function str_starts_with;
use function stream_wrapper_register;
use function stream_wrapper_restore;
use function stream_wrapper_unregister;

use const E_WARNING;

/** Controlled synchronous filesystem faults; the native file wrapper is always restored. */
#[CoversClass(UdbLogicalDigest::class)]
#[CoversClass(UdbCapturedSnapshot::class)]
#[CoversClass(UdbSnapshotProvider::class)]
final class UdbPrivateFilesystemCoverageTest extends TestCase
{
    #[Test]
    public function digestAndSnapshotPrivateDirectoryFailuresAreVisible(): void
    {
        $provider = new UdbSnapshotProvider(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $this->withFaults(static function () use ($provider): void {
            foreach ([static fn () => UdbLogicalDigest::fromRecords(UdbBlock::Nicks, []), static fn () => $provider->capture()] as $capture) {
                try {
                    $capture();
                    self::fail('Private spool creation failure must abort capture.');
                } catch (RuntimeException $error) {
                    self::assertStringContainsString('private UDB', $error->getMessage());
                }
            }
        });
    }

    #[Test]
    public function failedOpenAndZeroWritesCannotProduceDigestOrSnapshotRows(): void
    {
        $methods = [];
        foreach (['measure', 'readRows', 'append', 'split'] as $name) {
            $methods[$name] = new ReflectionMethod(UdbLogicalDigest::class, $name);
        }
        $this->withFaults(static function () use ($methods): void {
            foreach ([['measure', ['/private']], ['readRows', ['/private']], ['append', ['/private', 'x']], ['split', ['/private', 0, '/left', '/right']]] as [$name, $args]) {
                try {
                    $methods[$name]->invokeArgs(null, $args);
                    self::fail('Failed spool opening must be visible.');
                } catch (RuntimeException $error) {
                    self::assertStringContainsString('spool', $error->getMessage());
                }
            }
            try {
                iterator_to_array(UdbCapturedSnapshot::readFile('/private'), false);
                self::fail('Failed snapshot opening must not yield empty success.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('opened', $error->getMessage());
            }
        });
        self::assertTrue(stream_wrapper_register('udbcoverage', UdbCoverageFaultStream::class));
        $stream = fopen('udbcoverage://zero', 'w');
        self::assertIsResource($stream);
        try {
            foreach ([static fn () => UdbCapturedSnapshot::appendRow($stream, 'nick', UdbValue::none()), static fn () => new ReflectionMethod(UdbLogicalDigest::class, 'writeAll')->invoke(null, $stream, 'x')] as $write) {
                try {
                    $write();
                    self::fail('A zero-byte write must terminate with failure.');
                } catch (RuntimeException $error) {
                    self::assertStringContainsString('spool', $error->getMessage());
                }
            }
        } finally {
            fclose($stream);
            stream_wrapper_unregister('udbcoverage');
        }
    }

    #[Test]
    public function absentSplitInputIsAnEmptyPartitionNotAnOpenError(): void
    {
        $split = new ReflectionMethod(UdbLogicalDigest::class, 'split');
        self::assertNull($split->invoke(null, null, 0, '/unused-left', '/unused-right'));
    }

    #[Test]
    public function failedSnapshotBlockOpeningAbortsBeforeAnyImmutablePublication(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->method('fetchAssociative')->willReturn(['dataset_sid' => '002', 'dataset_epoch' => str_repeat('1', 16), 'dataset_watermark' => '0']);
        $provider = new UdbSnapshotProvider($connection);
        UdbCoverageFaultStream::$allowDirectory = true;
        try {
            $this->withFaults(function () use ($provider): void {
                $this->expectException(RuntimeException::class);
                $this->expectExceptionMessage('snapshot block spool');
                $provider->capture();
            });
        } finally {
            UdbCoverageFaultStream::$allowDirectory = false;
        }
    }

    private function withFaults(callable $operation): void
    {
        self::assertTrue(stream_wrapper_register('udbcoverage', UdbCoverageFaultStream::class));
        self::assertTrue(stream_wrapper_unregister('file'));
        self::assertTrue(stream_wrapper_register('file', UdbCoverageFaultStream::class));
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_WARNING);
        try {
            $operation();
        } finally {
            restore_error_handler();
            stream_wrapper_restore('file');
            stream_wrapper_unregister('udbcoverage');
        }
    }
}

final class UdbCoverageFaultStream
{
    public mixed $context;

    public static bool $allowDirectory = false;

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return self::$allowDirectory;
    }

    /** @return array<string, int> */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => self::$allowDirectory ? 0o40000 : 0o100000, 'size' => 0];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return str_starts_with($path, 'udbcoverage://');
    }

    public function stream_write(string $bytes): int
    {
        return 0;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void {}

    public function rmdir(string $path, int $options): bool
    {
        return true;
    }

    public function dir_opendir(string $path, int $options): bool
    {
        return false;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return true;
    }
}
