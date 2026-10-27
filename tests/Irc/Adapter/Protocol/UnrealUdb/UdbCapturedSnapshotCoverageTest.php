<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbDatasetCheckpoint;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbStreamOrigin;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValueKind;
use App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation\UdbCapturedSnapshot;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

use function fclose;
use function file_put_contents;
use function fopen;
use function iterator_to_array;
use function mkdir;
use function pack;
use function rmdir;
use function str_repeat;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[CoversClass(UdbCapturedSnapshot::class)]
final class UdbCapturedSnapshotCoverageTest extends TestCase
{
    #[Test]
    public function privateSpoolPreservesEveryExplicitKindAndClosesIdempotently(): void
    {
        $directory = $this->directory();
        $file = $directory . '/I';
        $stream = fopen($file, 'w');
        self::assertIsResource($stream);
        try {
            UdbCapturedSnapshot::appendRow($stream, '192.0.2.1', UdbValue::none());
            UdbCapturedSnapshot::appendRow($stream, '192.0.2.1::host', UdbValue::string('fixture.test'));
            UdbCapturedSnapshot::appendRow($stream, '192.0.2.1::clones', UdbValue::number(UdbUnsignedDecimal::fromInt(5)));
        } finally {
            fclose($stream);
        }
        $checkpoint = new UdbDatasetCheckpoint(new UdbStreamOrigin('001', str_repeat('1', 16)), UdbUnsignedDecimal::fromInt(7));
        $snapshot = new UdbCapturedSnapshot($directory, ['I' => $file], ['I' => str_repeat('a', 64)], ['I' => 3], $checkpoint);
        try {
            self::assertSame(str_repeat('a', 64), $snapshot->digest(UdbBlock::Ips));
            self::assertSame(3, $snapshot->count(UdbBlock::Ips));
            self::assertSame($checkpoint, $snapshot->checkpoint);
            $actual = iterator_to_array($snapshot->records(UdbBlock::Ips), false);
            self::assertCount(3, $actual);
            self::assertSame('192.0.2.1', $actual[0][0]);
            self::assertSame(UdbValueKind::None, $actual[0][1]->kind);
            self::assertSame('fixture.test', $actual[1][1]->value);
            self::assertSame(UdbValueKind::Number, $actual[2][1]->kind);
            self::assertSame('5', $actual[2][1]->value);
        } finally {
            $snapshot->close();
            $snapshot->close();
        }
        self::assertFileDoesNotExist($file);
        self::assertDirectoryDoesNotExist($directory);
    }

    #[Test]
    public function invalidNumericSpoolCannotBecomeAnImportedValue(): void
    {
        $directory = $this->directory();
        $file = $directory . '/I';
        $path = '192.0.2.1::clones';
        $raw = '*not-a-number';
        file_put_contents($file, pack('N', strlen($path)) . pack('C', 2) . pack('N', strlen($raw)) . $path . $raw);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('invalid number');
            iterator_to_array(UdbCapturedSnapshot::readFile($file), false);
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }

    #[Test]
    public function absentBlockCannotBeReadAsAnEmptySnapshot(): void
    {
        $directory = $this->directory();
        $snapshot = new UdbCapturedSnapshot($directory, [], [], [], new UdbDatasetCheckpoint(new UdbStreamOrigin('001', str_repeat('1', 16)), UdbUnsignedDecimal::fromInt(0)));
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('block is unavailable');
            iterator_to_array($snapshot->records(UdbBlock::Ips), false);
        } finally {
            $snapshot->close();
        }
    }

    #[Test]
    public function binaryHeaderBoundaryRejectsTruncationBeforeUnpacking(): void
    {
        $decode = new ReflectionMethod(UdbCapturedSnapshot::class, 'decodeHeader');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The UDB snapshot spool is malformed.');
        $decode->invoke(null, pack('N', 1));
    }

    #[Test]
    public function unknownKindIsRejectedWithoutLosingPayloadErrorPrecedence(): void
    {
        $directory = $this->directory();
        $file = $directory . '/I';
        try {
            file_put_contents($file, pack('NCN', 1, 255, 0) . 'x');
            try {
                iterator_to_array(UdbCapturedSnapshot::readFile($file), false);
                self::fail('An unknown kind must never yield a row.');
            } catch (RuntimeException $error) {
                self::assertSame('The UDB snapshot spool has an unknown value kind.', $error->getMessage());
            }
            file_put_contents($file, pack('NCN', 2, 255, 0) . 'x');
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('ended unexpectedly');
            iterator_to_array(UdbCapturedSnapshot::readFile($file), false);
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }

    private function directory(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ares-udb-coverage-');
        self::assertIsString($path);
        unlink($path);
        self::assertTrue(mkdir($path, 0o700));

        return $path;
    }
}
