<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Domain\IRC\Connection\ConnectionInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbRecordWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(UnrealUdbRecordWriter::class)]
final class UnrealUdbRecordWriterTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    private ActiveConnectionHolder $connectionHolder;

    protected function setUp(): void
    {
        $this->written = [];
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });
        $connection->method('isConnected')->willReturn(true);

        $this->connectionHolder = new ActiveConnectionHolder();
        $reflection = new ReflectionClass($this->connectionHolder);
        $property = $reflection->getProperty('connection');
        $property->setValue($this->connectionHolder, $connection);
        $sidProperty = $reflection->getProperty('serverSid');
        $sidProperty->setValue($this->connectionHolder, '001');
    }

    #[Test]
    public function insertWritesPrefixedDbInsWithTrailingValue(): void
    {
        $writer = new UnrealUdbRecordWriter($this->connectionHolder);

        $writer->insert('N', 'davidlig::pass', 'sha256:abc');

        self::assertSame([':001 DB * INS N::davidlig::pass :sha256:abc'], $this->written);
    }

    #[Test]
    public function insertWithMultiWordValueKeepsSpaces(): void
    {
        $writer = new UnrealUdbRecordWriter($this->connectionHolder);

        $writer->insert('C', '#chan::topic', 'Welcome to my channel');

        self::assertSame([':001 DB * INS C::#chan::topic :Welcome to my channel'], $this->written);
    }

    #[Test]
    public function insertWithEmptyValueWritesNothing(): void
    {
        $writer = new UnrealUdbRecordWriter($this->connectionHolder);

        $writer->insert('N', 'nick::vhost', '');

        self::assertSame([], $this->written);
    }

    #[Test]
    public function deleteWritesPrefixedDbDel(): void
    {
        $writer = new UnrealUdbRecordWriter($this->connectionHolder);

        $writer->delete('N', 'nick::vhost');

        self::assertSame([':001 DB * DEL N::nick::vhost'], $this->written);
    }

    #[Test]
    public function requestSyncWritesPrefixedUnicastRes(): void
    {
        $writer = new UnrealUdbRecordWriter($this->connectionHolder);

        $writer->requestSync('C', 'ABC');

        self::assertSame([':001 DB ABC RES C'], $this->written);
    }

    #[Test]
    public function dropBlockWritesPrefixedDrp(): void
    {
        $writer = new UnrealUdbRecordWriter($this->connectionHolder);

        $writer->dropBlock('K');

        self::assertSame([':001 DB * DRP K'], $this->written);
    }

    #[Test]
    public function writesWithoutPrefixWhenNoServerSid(): void
    {
        $holder = new ActiveConnectionHolder();
        $reflection = new ReflectionClass($holder);
        $property = $reflection->getProperty('connection');
        $property->setValue($holder, $this->connectionHolder->getConnection());

        $writer = new UnrealUdbRecordWriter($holder);

        $writer->insert('N', 'nick::vhost', 'host');

        self::assertSame(['DB * INS N::nick::vhost :host'], $this->written);
    }

    #[Test]
    public function doesNothingWhenNotConnected(): void
    {
        $writer = new UnrealUdbRecordWriter(new ActiveConnectionHolder());

        $writer->insert('N', 'nick::vhost', 'host');
        $writer->delete('N', 'nick::vhost');
        $writer->requestSync('N', '001');
        $writer->dropBlock('N');

        self::assertSame([], $this->written);
    }
}
