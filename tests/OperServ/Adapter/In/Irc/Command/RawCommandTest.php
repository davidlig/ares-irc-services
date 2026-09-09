<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\ActiveConnectionHolderInterface;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\OperServ\Adapter\In\Irc\Command\ProtocolRawCommandInterceptorInterface;
use App\OperServ\Adapter\In\Irc\Command\RawCommand;
use App\OperServ\Adapter\In\Irc\Command\RawCommandExecutionOutcome;
use App\OperServ\Adapter\In\Irc\Command\RawCommandExecutionResult;
use App\OperServ\Adapter\In\Irc\Command\RawCommandExecutor;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(RawCommand::class)]
final class RawCommandTest extends TestCase
{
    #[Test]
    public function exposesOperOnlyPermissionAndHelpMetadata(): void
    {
        $command = $this->command(null);

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

    #[Test]
    public function recordsSafeAuditAfterEffectAndBeforeReply(): void
    {
        $trace = new RawCommandTrace();
        $connection = new RawCommandConnection($trace);
        $audit = new RawCommandAudit($trace);
        $command = new RawCommand(
            new RawCommandExecutor($connection, new RawCommandInterceptor(null)),
            $audit,
        );
        $translation = new RawCommandTranslation();

        $command->execute($this->context(['KILL', 'UID', ':password=secret'], $translation, new RawCommandNotifier($trace)));

        self::assertSame(['effect', 'audit', 'reply'], $trace->events);
        self::assertSame('raw.done', $translation->lastKey);
        self::assertNotNull($audit->record);
        self::assertSame('KILL', $audit->record->target);
        self::assertSame(['transport' => 'irc'], $audit->record->metadata);
    }

    #[Test]
    public function presentsInterceptedExecutionAndUsesProtocolNeutralAuditMetadata(): void
    {
        $trace = new RawCommandTrace();
        $audit = new RawCommandAudit($trace);
        $translation = new RawCommandTranslation();
        $command = new RawCommand(
            new RawCommandExecutor(
                new RawCommandConnection($trace),
                new RawCommandInterceptor(RawCommandExecutionResult::executed('PROTOCOL ACTION', true)),
            ),
            $audit,
        );

        $command->execute($this->context(['opaque'], $translation, new RawCommandNotifier($trace)));

        self::assertSame('raw.protocol.done', $translation->lastKey);
        self::assertNotNull($audit->record);
        self::assertSame('PROTOCOL ACTION', $audit->record->target);
        self::assertSame(['transport' => 'protocol'], $audit->record->metadata);
    }

    /** @param array<string, string> $expectedParameters */
    #[Test]
    #[DataProvider('protocolFailures')]
    public function presentsProtocolNeutralFailures(
        RawCommandExecutionResult $interception,
        string $expectedKey,
        array $expectedParameters,
    ): void {
        $translation = new RawCommandTranslation();
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::never())->method('record');
        $command = new RawCommand(
            new RawCommandExecutor(new RawCommandConnection(new RawCommandTrace()), new RawCommandInterceptor($interception)),
            $audit,
        );

        $command->execute($this->context(['opaque'], $translation, new RawCommandNotifier(new RawCommandTrace())));

        self::assertSame($expectedKey, $translation->lastKey);
        foreach ($expectedParameters as $key => $value) {
            self::assertSame($value, $translation->lastParameters['%' . $key . '%'] ?? null);
        }
    }

    /** @return iterable<string, array{RawCommandExecutionResult, string, array<string, string>}> */
    public static function protocolFailures(): iterable
    {
        yield 'target' => [RawCommandExecutionResult::rejected(RawCommandExecutionOutcome::TargetInvalid, resourceIdentifier: 'server'), 'raw.protocol.target', ['target' => 'server']];
        yield 'syntax' => [RawCommandExecutionResult::rejected(RawCommandExecutionOutcome::SyntaxInvalid), 'raw.protocol.syntax', []];
        yield 'unsupported' => [RawCommandExecutionResult::rejected(RawCommandExecutionOutcome::Unsupported), 'raw.protocol.unsupported', []];
        yield 'resource type' => [RawCommandExecutionResult::rejected(RawCommandExecutionOutcome::ResourceTypeInvalid, resourceType: 'X'), 'raw.protocol.invalid_resource_type', ['resource_type' => 'X']];
        yield 'resource' => [RawCommandExecutionResult::rejected(RawCommandExecutionOutcome::ResourceIdentifierInvalid, resourceIdentifier: 'bad'), 'raw.protocol.invalid_resource', ['resource' => 'bad']];
        yield 'value' => [RawCommandExecutionResult::rejected(RawCommandExecutionOutcome::ValueInvalid, resourceIdentifier: '<redacted>'), 'raw.protocol.invalid_value', ['resource' => '<redacted>']];
        yield 'generic' => [RawCommandExecutionResult::rejected(RawCommandExecutionOutcome::Failed), 'raw.protocol.error', []];
    }

    #[Test]
    public function presentsLocalRejectionsAndIgnoresMissingSender(): void
    {
        $trace = new RawCommandTrace();
        $translation = new RawCommandTranslation();
        $command = $this->command(null, $trace);

        $command->execute($this->context([], $translation, new RawCommandNotifier($trace)));
        self::assertSame('raw.empty', $translation->lastKey);
        $command->execute($this->context([str_repeat('x', 511)], $translation, new RawCommandNotifier($trace)));
        self::assertSame('raw.too_long', $translation->lastKey);
        $connection = new RawCommandConnection($trace, false);
        $command = new RawCommand(new RawCommandExecutor($connection, new RawCommandInterceptor(null)), $this->createStub(CommandAuditRecorder::class));
        $command->execute($this->context(['PING'], $translation, new RawCommandNotifier($trace)));
        self::assertSame('raw.not_connected', $translation->lastKey);

        $before = $trace->events;
        $command->execute($this->context(['PING'], $translation, new RawCommandNotifier($trace), true));
        self::assertSame($before, $trace->events);
    }

    private function command(?RawCommandExecutionResult $interception, ?RawCommandTrace $trace = null): RawCommand
    {
        $trace ??= new RawCommandTrace();

        return new RawCommand(
            new RawCommandExecutor(new RawCommandConnection($trace), new RawCommandInterceptor($interception)),
            $this->createStub(CommandAuditRecorder::class),
        );
    }

    /** @param list<string> $arguments */
    private function context(array $arguments, TranslatorInterface $translator, OperServNotifierInterface $notifier, bool $withoutSender = false): OperServContext
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

final class RawCommandTranslation implements TranslatorInterface
{
    public string $lastKey = '';

    /** @var array<string, mixed> */
    public array $lastParameters = [];

    /** @param array<string, mixed> $parameters */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $this->lastKey = $id;
        $this->lastParameters = $parameters;

        return $id;
    }

    public function getLocale(): string
    {
        return 'en';
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

final class RawCommandConnection implements ActiveConnectionHolderInterface
{
    public function __construct(private RawCommandTrace $trace, private bool $connected = true) {}

    public function getServerSid(): string
    {
        return '001';
    }

    public function writeLine(string $line): void
    {
        $this->trace->events[] = 'effect';
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }
}

final readonly class RawCommandInterceptor implements ProtocolRawCommandInterceptorInterface
{
    public function __construct(private ?RawCommandExecutionResult $result) {}

    public function intercept(array $arguments): ?RawCommandExecutionResult
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
