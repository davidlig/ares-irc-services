<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Application\UseCase\ExecuteRaw;

use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\Out\RawDatabaseMutationFailure;
use App\OperServ\Application\Port\Out\RawDatabaseMutationHandler;
use App\OperServ\Application\Port\Out\RawDatabaseMutationResult;
use App\OperServ\Application\Port\Out\RawLineTransport;
use App\OperServ\Application\UseCase\ExecuteRaw\ExecuteRaw;
use App\OperServ\Application\UseCase\ExecuteRaw\ExecuteRawHandler;
use App\OperServ\Application\UseCase\ExecuteRaw\ExecuteRawResult;
use App\OperServ\Application\UseCase\ExecuteRaw\RawDatabaseExecutionOutcome;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExecuteRaw::class)]
#[CoversClass(ExecuteRawHandler::class)]
#[CoversClass(ExecuteRawResult::class)]
#[CoversClass(RawDatabaseMutationResult::class)]
final class ExecuteRawHandlerTest extends TestCase
{
    #[Test]
    public function sendsNonUdbLinesWithoutPreservingTheirPayloadInTheResult(): void
    {
        $transport = new RecordingRawLineTransport();
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(static function (CommandAuditRecord $record): bool {
            self::assertSame(CommandAuditCategory::OperatorAction, $record->category);
            self::assertSame('RAW', $record->operation);
            self::assertSame('KILL', $record->target);
            self::assertSame(['transport' => 'irc'], $record->metadata);

            return true;
        }));
        $handler = new ExecuteRawHandler($transport, new FakeRawDatabaseMutationHandler(), $audit);

        $result = $handler->handle($this->command(['KILL', 'UID', ':reason']));

        self::assertSame(RawDatabaseExecutionOutcome::Executed, $result->outcome);
        self::assertSame('KILL', $result->operation);
        self::assertSame(['KILL UID :reason'], $transport->lines);
    }

    #[Test]
    public function rejectsEmptyTooLongAndDisconnectedInputBeforeTransport(): void
    {
        $transport = new RecordingRawLineTransport();
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::never())->method('record');
        $handler = new ExecuteRawHandler($transport, new FakeRawDatabaseMutationHandler(), $audit);

        self::assertSame(RawDatabaseExecutionOutcome::Empty, $handler->handle($this->command([]))->outcome);
        self::assertSame(RawDatabaseExecutionOutcome::TooLong, $handler->handle($this->command([str_repeat('x', 511)]))->outcome);
        $transport->connected = false;
        self::assertSame(RawDatabaseExecutionOutcome::Disconnected, $handler->handle($this->command(['PING']))->outcome);
        self::assertSame([], $transport->lines);
    }

    #[Test]
    public function delegatesOnlySupportedUdbMutationsAndDoesNotWriteTheirPayload(): void
    {
        $transport = new RecordingRawLineTransport();
        $database = new FakeRawDatabaseMutationHandler(true);
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(static function (CommandAuditRecord $record) use ($database): bool {
            self::assertSame([['N::nick::vhost', 'cloak.example']], $database->inserts);
            self::assertSame('DB INS', $record->target);
            self::assertSame(['transport' => 'udb'], $record->metadata);

            return true;
        }));
        $handler = new ExecuteRawHandler($transport, $database, $audit);

        $result = $handler->handle($this->command(['DB', '*', 'INS', 'N::nick::vhost', ':cloak.example']));

        self::assertSame(RawDatabaseExecutionOutcome::DatabaseExecuted, $result->outcome);
        self::assertSame('DB INS', $result->operation);
        self::assertSame([['N::nick::vhost', 'cloak.example']], $database->inserts);
        self::assertSame([], $transport->lines);
    }

    #[Test]
    public function rejectsInvalidDatabaseCommandsAndExecutesDeletes(): void
    {
        $database = new FakeRawDatabaseMutationHandler(true);
        $handler = new ExecuteRawHandler(new RecordingRawLineTransport(), $database, $this->createStub(CommandAuditRecorder::class));

        self::assertSame(
            RawDatabaseExecutionOutcome::DatabaseTargetInvalid,
            $handler->handle($this->command(['DB', 'users', 'DEL', 'users/7']))->outcome,
        );
        self::assertSame(
            RawDatabaseExecutionOutcome::DatabaseSyntaxInvalid,
            $handler->handle($this->command(['DB', '*', 'INS', '']))->outcome,
        );
        self::assertSame(
            RawDatabaseExecutionOutcome::DatabaseSyntaxInvalid,
            $handler->handle($this->command(['DB', '*', 'DEL', 'users/7', 'extra']))->outcome,
        );
        self::assertSame(
            RawDatabaseExecutionOutcome::DatabaseUnsupported,
            $handler->handle($this->command(['DB', '*', 'DRP', 'users/7']))->outcome,
        );

        $result = $handler->handle($this->command(['DB', '*', 'DEL', 'users/7']));

        self::assertSame(RawDatabaseExecutionOutcome::DatabaseExecuted, $result->outcome);
        self::assertSame(['users/7'], $database->deletes);
    }

    #[Test]
    public function forwardsUnknownDatabaseSubcommandsAndDecodesQuotedValues(): void
    {
        $database = new FakeRawDatabaseMutationHandler(true);
        $transport = new RecordingRawLineTransport();
        $handler = new ExecuteRawHandler($transport, $database, $this->createStub(CommandAuditRecorder::class));

        $forwarded = $handler->handle($this->command(['DB', '*', 'GET', 'users/7']));
        $inserted = $handler->handle($this->command(['DB', '*', 'INS', 'users/7', ':"secret value"']));

        self::assertSame(RawDatabaseExecutionOutcome::Executed, $forwarded->outcome);
        self::assertSame(['DB * GET users/7'], $transport->lines);
        self::assertSame(RawDatabaseExecutionOutcome::DatabaseExecuted, $inserted->outcome);
        self::assertSame([['users/7', 'secret value']], $database->inserts);
    }

    #[Test]
    #[DataProvider('databaseFailures')]
    public function exposesOnlySemanticDatabaseFailures(
        RawDatabaseMutationResult $failure,
        RawDatabaseExecutionOutcome $expectedOutcome,
        ?string $expectedRecordType,
        ?string $expectedRecordPath,
    ): void {
        $transport = new RecordingRawLineTransport();
        $database = new FakeRawDatabaseMutationHandler(true);
        $database->insertResult = $failure;
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::never())->method('record');
        $handler = new ExecuteRawHandler($transport, $database, $audit);

        $result = $handler->handle($this->command(['DB', '*', 'INS', 'N::nick::pass', ':secret']));

        self::assertSame($expectedOutcome, $result->outcome);
        self::assertSame($expectedRecordType, $result->recordType);
        self::assertSame($expectedRecordPath, $result->recordPath);
        self::assertSame([], $transport->lines);
    }

    #[Test]
    public function rawDatabaseMutationFailureFactoryPreservesOnlySemanticContext(): void
    {
        $result = RawDatabaseMutationResult::failure(
            RawDatabaseMutationFailure::InvalidRecordPath,
            recordType: 'N',
            recordPath: 'N::nick::vhost',
        );

        self::assertFalse($result->successful);
        self::assertSame(RawDatabaseMutationFailure::InvalidRecordPath, $result->failure);
        self::assertSame('N', $result->recordType);
        self::assertSame('N::nick::vhost', $result->recordPath);
    }

    /** @return iterable<string, array{RawDatabaseMutationResult, RawDatabaseExecutionOutcome, ?string, ?string}> */
    public static function databaseFailures(): iterable
    {
        yield 'unsupported record type' => [
            RawDatabaseMutationResult::failure(RawDatabaseMutationFailure::UnsupportedRecordType, recordType: 'X'),
            RawDatabaseExecutionOutcome::DatabaseRecordTypeInvalid,
            'X',
            null,
        ];
        yield 'invalid record path' => [
            RawDatabaseMutationResult::failure(RawDatabaseMutationFailure::InvalidRecordPath, recordPath: 'N::bad'),
            RawDatabaseExecutionOutcome::DatabasePathInvalid,
            null,
            'N::bad',
        ];
        yield 'invalid record value' => [
            RawDatabaseMutationResult::failure(RawDatabaseMutationFailure::InvalidRecordValue, recordPath: 'N::nick::pass <redacted>'),
            RawDatabaseExecutionOutcome::DatabaseValueInvalid,
            null,
            'N::nick::pass <redacted>',
        ];
        yield 'mutation rejected' => [
            RawDatabaseMutationResult::failure(RawDatabaseMutationFailure::Rejected),
            RawDatabaseExecutionOutcome::DatabaseFailed,
            null,
            null,
        ];
    }

    /** @param list<string> $arguments */
    private function command(array $arguments): ExecuteRaw
    {
        return new ExecuteRaw('Oper', $arguments, new DateTimeImmutable('2026-09-08T10:00:00+00:00'));
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
