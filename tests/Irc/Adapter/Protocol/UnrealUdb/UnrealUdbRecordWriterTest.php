<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbRecordWriter;
use App\Irc\Application\Port\In\ActiveConnectionHolderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UnrealUdbRecordWriter::class)]
final class UnrealUdbRecordWriterTest extends TestCase
{
    private FakeUdbRecords $records;

    private RecordingSessionState $sessionState;

    /** @var list<string> */
    private array $written = [];

    private ActiveConnectionHolderInterface $holder;

    private UnrealUdbRecordWriter $writer;

    protected function setUp(): void
    {
        $this->records = new FakeUdbRecords();
        $this->sessionState = new RecordingSessionState(false);
        $this->written = [];

        $this->holder = $this->createStub(ActiveConnectionHolderInterface::class);
        $this->holder->method('isConnected')->willReturn(true);
        $this->holder->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });

        $this->writer = $this->createWriter();
    }

    private function createWriter(): UnrealUdbRecordWriter
    {
        return new UnrealUdbRecordWriter($this->holder, $this->sessionState, $this->records, '001');
    }

    #[Test]
    public function insertValidatesPersistsAndSendsWhenReady(): void
    {
        $this->sessionState->ready = true;

        $result = $this->writer->insert('N', 'davidlig::vhost', 'cloaked.example.net');

        self::assertTrue($result);
        self::assertSame(['davidlig::vhost' => 'cloaked.example.net'], $this->records->blocks['N']);
        self::assertSame([':001 DB * INS N::davidlig::vhost :cloaked.example.net'], $this->written);
    }

    #[Test]
    public function insertPersistsAndQueuesWhenNotReady(): void
    {
        $result = $this->writer->insert('N', 'davidlig::vhost', 'vhost.example.net');

        self::assertTrue($result);
        self::assertSame(['davidlig::vhost' => 'vhost.example.net'], $this->records->blocks['N']);
        self::assertSame([], $this->written);
        self::assertCount(1, $this->sessionState->queue);
        self::assertSame('N::davidlig::vhost', 'N::' . $this->sessionState->queue[0]->encodedPath);
    }

    #[Test]
    public function echoedChannelTopicPersistsSuccessfullyWithoutSendingOrQueueingAnotherMutation(): void
    {
        $this->sessionState->ready = true;

        self::assertTrue($this->writer->insert('C', '#ares::topic', 'Canal oficial'));
        self::assertTrue($this->writer->insert('C', '#ARES::TOPIC', 'Canal oficial'));

        self::assertSame([':001 DB * INS C::#ares::topic :Canal oficial'], $this->written);
        self::assertSame([], $this->sessionState->queue);
        self::assertSame(['#ares::topic' => 'Canal oficial'], $this->records->blocks['C']);
    }

    #[Test]
    public function insertEncodesSpecialCharactersCanonically(): void
    {
        $this->sessionState->ready = true;

        $this->writer->insert('K', 'G::bad@host::reason', 'no colon allowed: here');

        self::assertSame(
            [':001 DB * INS K::G::bad@host::reason :no colon allowed: here'],
            $this->written,
        );
    }

    #[Test]
    public function insertRejectsUnknownBlock(): void
    {
        $result = $this->writer->insert('X', 'path', 'value');

        self::assertFalse($result);
        self::assertSame([], $this->records->blocks);
        self::assertSame([], $this->written);
    }

    #[Test]
    public function insertRejectsEmptyPath(): void
    {
        $result = $this->writer->insert('N', '', 'value');

        self::assertFalse($result);
        self::assertSame([], $this->written);
    }

    #[Test]
    public function insertRejectsInvalidSchemaValues(): void
    {
        // S::encryption_key must be 64 hex chars.
        $result = $this->writer->insert('S', 'encryption_key', 'not-a-key');

        self::assertFalse($result);
        self::assertSame([], $this->records->blocks);
        self::assertSame([], $this->written);
    }

    #[Test]
    public function insertRejectsInvalidSchemaPaths(): void
    {
        $result = $this->writer->insert('N', 'davidlig::unknown-key', 'value');

        self::assertFalse($result);
        self::assertSame([], $this->records->blocks);
    }

    #[Test]
    public function insertRejectsValuesExceedingTheWireLimit(): void
    {
        $result = $this->writer->insert('N', 'davidlig::swhois', str_repeat('x', 5000));

        self::assertFalse($result);
        self::assertSame([], $this->records->blocks);
        self::assertSame([], $this->written);
    }

    #[Test]
    public function insertWithoutStoreWriteDoesNotSendAnything(): void
    {
        $this->records->fail = true;
        $this->sessionState->ready = true;

        $result = $this->writer->insert('N', 'davidlig::vhost', 'vhost.example.net');

        self::assertFalse($result);
        self::assertSame([], $this->written);
        self::assertSame([], $this->sessionState->queue);
    }

    #[Test]
    public function deleteCascadesToChildrenAndSendsDelWhenReady(): void
    {
        $this->sessionState->ready = true;
        $this->records->blocks['N'] = [
            'davidlig::vhost' => 'v',
            'davidlig::pass' => 'p',
            'other::vhost' => 'v',
        ];

        $result = $this->writer->delete('N', 'davidlig');

        self::assertTrue($result);
        self::assertSame(['other::vhost' => 'v'], $this->records->blocks['N']);
        self::assertSame([':001 DB * DEL N::davidlig'], $this->written);
    }

    #[Test]
    public function deleteQueuesWhenNotReady(): void
    {
        $this->records->blocks['N'] = ['davidlig::vhost' => 'v'];

        $result = $this->writer->delete('N', 'davidlig');

        self::assertTrue($result);
        self::assertSame([], $this->written);
        self::assertCount(1, $this->sessionState->queue);
    }

    #[Test]
    public function deletingAnUnknownPathSucceedsWithoutSendingOrQueueingAMutation(): void
    {
        $this->sessionState->ready = true;

        self::assertTrue($this->writer->delete('N', 'davidlig'));

        self::assertSame([], $this->written);
        self::assertSame([], $this->sessionState->queue);
        self::assertSame([], $this->records->blocks);
    }

    #[Test]
    public function deleteRejectsUnknownBlockAndEmptyPaths(): void
    {
        self::assertFalse($this->writer->delete('X', 'path'));
        self::assertFalse($this->writer->delete('N', ''));
        self::assertSame([], $this->written);
    }

    #[Test]
    public function deleteRejectsUnencodablePaths(): void
    {
        self::assertFalse($this->writer->delete('N', 'nick::'));
        self::assertSame([], $this->written);
    }

    #[Test]
    public function deleteWithoutStoreWriteDoesNotSendAnything(): void
    {
        $this->records->fail = true;
        $this->sessionState->ready = true;

        $result = $this->writer->delete('N', 'davidlig');

        self::assertFalse($result);
        self::assertSame([], $this->written);
    }

    #[Test]
    public function sendQueuesWhenTheConnectionIsLost(): void
    {
        $holder = $this->createStub(ActiveConnectionHolderInterface::class);
        $holder->method('isConnected')->willReturn(false);
        $holder->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });
        $writer = new UnrealUdbRecordWriter($holder, $this->sessionState, $this->records, '001');
        $this->sessionState->ready = true;

        $writer->insert('N', 'davidlig::vhost', 'v');

        self::assertSame(['N' => ['davidlig::vhost' => 'v']], $this->records->blocks);
        self::assertSame([], $this->written);
        self::assertCount(1, $this->sessionState->queue);
    }
}
