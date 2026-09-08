<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc\Command;

use App\Application\Port\TranslationInterface;
use App\Irc\Application\Port\In\SenderView;
use App\OperServ\Adapter\In\Irc\Command\RawCommand;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\OperServ\Application\Port\Out\RawDatabaseMutationFailure;
use App\OperServ\Application\Port\Out\RawDatabaseMutationHandler;
use App\OperServ\Application\Port\Out\RawDatabaseMutationResult;
use App\OperServ\Application\Port\Out\RawLineTransport;
use App\OperServ\Application\UseCase\ExecuteRaw\ExecuteRaw;
use App\OperServ\Application\UseCase\ExecuteRaw\ExecuteRawHandler;
use App\OperServ\Application\UseCase\ExecuteRaw\ExecuteRawResult;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RawCommand::class)]
#[CoversClass(ExecuteRaw::class)]
#[CoversClass(ExecuteRawHandler::class)]
#[CoversClass(ExecuteRawResult::class)]
#[CoversClass(RawDatabaseMutationResult::class)]
final class RawCommandTest extends TestCase
{
    #[Test]
    public function exposesOperOnlyPermissionAndHelpMetadata(): void
    {
        $command = new RawCommand(new ExecuteRawHandler(
            new RawCommandTransport(new RawCommandTrace()),
            new RawCommandDatabase(RawDatabaseMutationResult::success()),
            $this->createStub(CommandAuditRecorder::class),
        ));

        self::assertSame('RAW', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('raw.syntax', $command->getSyntaxKey());
        self::assertSame('raw.help', $command->getHelpKey());
        self::assertSame(40, $command->getOrder());
        self::assertSame('raw.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertTrue($command->isOperOnly());
        self::assertSame('operserv.raw', $command->getRequiredPermission());
    }

    /** @param array<string, string> $expectedParameters */
    #[Test]
    #[DataProvider('semanticFailures')]
    public function choosesIrcPresentationForSemanticDatabaseFailures(
        RawDatabaseMutationResult $failure,
        string $expectedKey,
        array $expectedParameters,
    ): void {
        $translator = new RawCommandTranslation();
        $context = $this->context(
            ['DB', '*', 'INS', 'N::nick::field', ':value'],
            $translator,
            new RawCommandNotifier(new RawCommandTrace()),
        );
        $database = new RawCommandDatabase($failure);
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::never())->method('record');
        $command = new RawCommand(new ExecuteRawHandler(new RawCommandTransport(new RawCommandTrace()), $database, $audit));

        $command->execute($context);

        self::assertSame($expectedKey, $translator->lastKey);
        foreach ($expectedParameters as $key => $value) {
            self::assertSame($value, $translator->lastParameters['%' . $key . '%'] ?? null);
        }
    }

    #[Test]
    public function recordsOnlyVerbAndTransportAfterEffectAndBeforeReply(): void
    {
        $trace = new RawCommandTrace();
        $audit = new RawCommandAudit($trace);
        $translator = new RawCommandTranslation();
        $command = new RawCommand(new ExecuteRawHandler(
            new RawCommandTransport($trace),
            new RawCommandDatabase(RawDatabaseMutationResult::success(), false),
            $audit,
        ));

        $command->execute($this->context(['KILL', 'UID', ':password=secret'], $translator, new RawCommandNotifier($trace)));

        self::assertSame(['effect', 'audit', 'reply'], $trace->events);
        self::assertSame('raw.done', $translator->lastKey);
        self::assertNotNull($audit->record);
        self::assertSame('KILL', $audit->record->target);
        self::assertSame(['transport' => 'irc'], $audit->record->metadata);
    }

    #[Test]
    public function missingSenderProducesNoEffect(): void
    {
        $trace = new RawCommandTrace();
        $command = new RawCommand(new ExecuteRawHandler(
            new RawCommandTransport($trace),
            new RawCommandDatabase(RawDatabaseMutationResult::success()),
            $this->createStub(CommandAuditRecorder::class),
        ));

        $command->execute($this->context(['PING'], new RawCommandTranslation(), new RawCommandNotifier($trace), true));

        self::assertSame([], $trace->events);
    }

    /** @param list<string> $arguments
     * @param array<string, string> $expectedParameters
     */
    #[Test]
    #[DataProvider('nonDatabaseRejections')]
    public function presentsInputAndConnectivityRejections(
        array $arguments,
        bool $connected,
        string $expectedKey,
        array $expectedParameters,
    ): void {
        $translator = new RawCommandTranslation();
        $trace = new RawCommandTrace();
        $command = new RawCommand(new ExecuteRawHandler(
            new RawCommandTransport($trace, $connected),
            new RawCommandDatabase(RawDatabaseMutationResult::success()),
            $this->createStub(CommandAuditRecorder::class),
        ));

        $command->execute($this->context($arguments, $translator, new RawCommandNotifier($trace)));

        self::assertSame($expectedKey, $translator->lastKey);
        foreach ($expectedParameters as $key => $value) {
            self::assertSame($value, $translator->lastParameters['%' . $key . '%'] ?? null);
        }
    }

    /** @return iterable<string, array{list<string>, bool, string, array<string, string>}> */
    public static function nonDatabaseRejections(): iterable
    {
        yield 'empty' => [[], true, 'raw.empty', []];
        yield 'too long' => [[str_repeat('x', 511)], true, 'raw.too_long', []];
        yield 'disconnected' => [['PING'], false, 'raw.not_connected', []];
        yield 'invalid database target' => [['DB', 'server', 'INS', 'N::nick::field', ':value'], true, 'raw.udb.target', ['target' => 'server']];
        yield 'insert syntax' => [['DB', '*', 'INS'], true, 'raw.udb.syntax', []];
        yield 'delete syntax' => [['DB', '*', 'DEL', 'N::nick', 'extra'], true, 'raw.udb.syntax', []];
        yield 'unknown database subcommand falls back to raw transport' => [['DB', '*', 'UNKNOWN'], true, 'raw.done', []];
        yield 'unsupported database mutation' => [['DB', '*', 'DRP', 'N::nick'], true, 'raw.udb.unsupported', []];
    }

    #[Test]
    public function successfulQuotedDatabaseInsertIsDecodedAuditedAndPresented(): void
    {
        $trace = new RawCommandTrace();
        $database = new RawCommandDatabase(RawDatabaseMutationResult::success());
        $audit = new RawCommandAudit($trace);
        $translator = new RawCommandTranslation();
        $command = new RawCommand(new ExecuteRawHandler(new RawCommandTransport($trace), $database, $audit));

        $command->execute($this->context(
            ['DB', '*', 'INS', 'N::nick::field', ':"quoted', 'value"'],
            $translator,
            new RawCommandNotifier($trace),
        ));

        self::assertSame('quoted value', $database->insertedValue);
        self::assertSame('raw.udb.done', $translator->lastKey);
        self::assertNotNull($audit->record);
        self::assertSame('DB INS', $audit->record->target);
        self::assertSame(['transport' => 'udb'], $audit->record->metadata);
    }

    #[Test]
    public function successfulDatabaseDeleteIsPresented(): void
    {
        $translator = new RawCommandTranslation();
        $trace = new RawCommandTrace();
        $command = new RawCommand(new ExecuteRawHandler(
            new RawCommandTransport($trace),
            new RawCommandDatabase(RawDatabaseMutationResult::success()),
            new RawCommandAudit($trace),
        ));

        $command->execute($this->context(['DB', '*', 'DEL', 'N::nick'], $translator, new RawCommandNotifier($trace)));

        self::assertSame('raw.udb.done', $translator->lastKey);
    }

    /** @return iterable<string, array{RawDatabaseMutationResult, string, array<string, string>}> */
    public static function semanticFailures(): iterable
    {
        yield 'record type' => [
            RawDatabaseMutationResult::failure(RawDatabaseMutationFailure::UnsupportedRecordType, recordType: 'X'),
            'raw.udb.invalid_block',
            ['block' => 'X'],
        ];
        yield 'record path' => [
            RawDatabaseMutationResult::failure(RawDatabaseMutationFailure::InvalidRecordPath, recordPath: 'N::bad'),
            'raw.udb.invalid_path',
            ['path' => 'N::bad'],
        ];
        yield 'record value' => [
            RawDatabaseMutationResult::failure(RawDatabaseMutationFailure::InvalidRecordValue, recordPath: 'N::nick::pass <redacted>'),
            'raw.udb.invalid_value',
            ['path' => 'N::nick::pass <redacted>'],
        ];
        yield 'generic rejection' => [
            RawDatabaseMutationResult::failure(RawDatabaseMutationFailure::Rejected),
            'raw.udb.error',
            [],
        ];
    }

    /** @param list<string> $arguments */
    private function context(array $arguments, TranslationInterface $translator, OperServNotifierInterface $notifier, bool $withoutSender = false): OperServContext
    {
        return new OperServContext(
            $withoutSender ? null : new SenderView('001AAA', 'Oper', 'ident', 'host', 'cloak', 'ip', true, true),
            null,
            'RAW',
            $arguments,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new OperServCommandRegistry([]),
            new ServiceNicknameRegistry([]),
            $this->createStub(OperatorAuthorizationQuery::class),
        );
    }
}

final class RawCommandTrace
{
    /** @var list<string> */
    public array $events = [];
}

final class RawCommandTranslation implements TranslationInterface
{
    public string $lastKey = '';

    /** @var array<string, mixed> */
    public array $lastParameters = [];

    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $this->lastKey = $id;
        $this->lastParameters = $parameters;

        return $id;
    }
}

final readonly class RawCommandNotifier implements OperServNotifierInterface
{
    public function __construct(private RawCommandTrace $trace) {}

    public function sendNotice(string $targetUidOrNick, string $message): void
    {
        $this->trace->events[] = 'reply';
    }

    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void
    {
        $this->trace->events[] = 'reply';
    }

    public function getNick(): string
    {
        return 'OperServ';
    }

    public function getUid(): string
    {
        return '001OS';
    }

    public function getServiceKey(): string
    {
        return 'operserv';
    }
}

final readonly class RawCommandTransport implements RawLineTransport
{
    public function __construct(private RawCommandTrace $trace, private bool $connected = true) {}

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function send(string $line): void
    {
        $this->trace->events[] = 'effect';
    }
}

final class RawCommandDatabase implements RawDatabaseMutationHandler
{
    public ?string $insertedValue = null;

    public function __construct(private RawDatabaseMutationResult $result, private bool $available = true) {}

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function insert(string $path, string $value): RawDatabaseMutationResult
    {
        $this->insertedValue = $value;

        return $this->result;
    }

    public function delete(string $path): RawDatabaseMutationResult
    {
        return $this->result;
    }
}

final class RawCommandAudit implements CommandAuditRecorder
{
    public ?CommandAuditRecord $record = null;

    public function __construct(private readonly RawCommandTrace $trace) {}

    public function record(CommandAuditRecord $record): void
    {
        $this->record = $record;
        $this->trace->events[] = 'audit';
    }
}
