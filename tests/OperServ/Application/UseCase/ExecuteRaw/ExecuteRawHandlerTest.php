<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Application\UseCase\ExecuteRaw;

use App\OperServ\Application\Port\Out\RawDatabaseMutationHandler;
use App\OperServ\Application\Port\Out\RawDatabaseMutationResult;
use App\OperServ\Application\Port\Out\RawLineTransport;
use App\OperServ\Application\UseCase\ExecuteRaw\ExecuteRaw;
use App\OperServ\Application\UseCase\ExecuteRaw\ExecuteRawHandler;
use App\OperServ\Application\UseCase\ExecuteRaw\RawDatabaseExecutionOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExecuteRaw::class)]
#[CoversClass(ExecuteRawHandler::class)]
final class ExecuteRawHandlerTest extends TestCase
{
    #[Test]
    public function sendsNonUdbLinesWithoutPreservingTheirPayloadInTheResult(): void
    {
        $transport = new RecordingRawLineTransport();
        $handler = new ExecuteRawHandler($transport, new FakeRawDatabaseMutationHandler());

        $result = $handler->handle(new ExecuteRaw(['KILL', 'UID', ':reason']));

        self::assertSame(RawDatabaseExecutionOutcome::Executed, $result->outcome);
        self::assertSame('KILL', $result->operation);
        self::assertSame(['KILL UID :reason'], $transport->lines);
    }

    #[Test]
    public function rejectsEmptyTooLongAndDisconnectedInputBeforeTransport(): void
    {
        $transport = new RecordingRawLineTransport();
        $handler = new ExecuteRawHandler($transport, new FakeRawDatabaseMutationHandler());

        self::assertSame(RawDatabaseExecutionOutcome::Empty, $handler->handle(new ExecuteRaw([]))->outcome);
        self::assertSame(RawDatabaseExecutionOutcome::TooLong, $handler->handle(new ExecuteRaw([str_repeat('x', 511)]))->outcome);
        $transport->connected = false;
        self::assertSame(RawDatabaseExecutionOutcome::Disconnected, $handler->handle(new ExecuteRaw(['PING']))->outcome);
        self::assertSame([], $transport->lines);
    }

    #[Test]
    public function delegatesOnlySupportedUdbMutationsAndDoesNotWriteTheirPayload(): void
    {
        $transport = new RecordingRawLineTransport();
        $database = new FakeRawDatabaseMutationHandler(true);
        $handler = new ExecuteRawHandler($transport, $database);

        $result = $handler->handle(new ExecuteRaw(['DB', '*', 'INS', 'N::nick::vhost', ':cloak.example']));

        self::assertSame(RawDatabaseExecutionOutcome::DatabaseExecuted, $result->outcome);
        self::assertSame('DB INS', $result->operation);
        self::assertSame([['N::nick::vhost', 'cloak.example']], $database->inserts);
        self::assertSame([], $transport->lines);
    }

    #[Test]
    public function exposesOnlyTheSemanticUdbErrorForFailedMutation(): void
    {
        $transport = new RecordingRawLineTransport();
        $database = new FakeRawDatabaseMutationHandler(true);
        $database->insertResult = RawDatabaseMutationResult::failure('raw.udb.invalid_path', ['%path%' => 'N::nick::pass <redacted>']);
        $handler = new ExecuteRawHandler($transport, $database);

        $result = $handler->handle(new ExecuteRaw(['DB', '*', 'INS', 'N::nick::pass', ':secret']));

        self::assertSame(RawDatabaseExecutionOutcome::DatabaseFailed, $result->outcome);
        self::assertSame('raw.udb.invalid_path', $result->parameters['errorKey']);
        self::assertSame([], $transport->lines);
    }
}

final class RecordingRawLineTransport implements RawLineTransport
{
    public bool $connected = true;

    /** @var list<string> */
    public array $lines = [];

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function send(string $line): void
    {
        $this->lines[] = $line;
    }
}

final class FakeRawDatabaseMutationHandler implements RawDatabaseMutationHandler
{
    /** @var list<array{string, string}> */
    public array $inserts = [];

    /** @var list<string> */
    public array $deletes = [];

    public ?RawDatabaseMutationResult $insertResult = null;

    public function __construct(private bool $available = false) {}

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function insert(string $path, string $value): RawDatabaseMutationResult
    {
        $this->inserts[] = [$path, $value];

        return $this->insertResult ?? RawDatabaseMutationResult::success();
    }

    public function delete(string $path): RawDatabaseMutationResult
    {
        $this->deletes[] = $path;

        return RawDatabaseMutationResult::success();
    }
}
