<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\OperServ\Adapter\In\Irc\Command\GlobalCommand;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\OperServ\Application\Port\Out\GlobalMessageTransport;
use App\OperServ\Application\Port\Out\NetworkUser;
use App\OperServ\Application\Port\Out\NetworkUserLookup;
use App\OperServ\Application\Port\Out\OperatorAccountLookup;
use App\OperServ\Application\Security\OperServPermission;
use App\OperServ\Application\UseCase\Global\GlobalMessageType;
use App\OperServ\Application\UseCase\Global\SendGlobalMessage;
use App\OperServ\Application\UseCase\Global\SendGlobalMessageHandler;
use App\OperServ\Application\UseCase\Global\SendGlobalMessageResult;
use App\OperServ\Domain\ValueObject\GlobalMessageMask;
use App\Shared\Application\Port\TranslationInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlobalCommand::class)]
#[CoversClass(SendGlobalMessage::class)]
#[CoversClass(SendGlobalMessageHandler::class)]
#[CoversClass(SendGlobalMessageResult::class)]
final class GlobalCommandTest extends TestCase
{
    #[Test]
    public function exposesPermissionAndHelpMetadata(): void
    {
        $command = new GlobalCommand($this->handler(new GlobalCommandNetwork(new GlobalCommandTrace())));

        self::assertSame('GLOBAL', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(3, $command->getMinArgs());
        self::assertSame('global.syntax', $command->getSyntaxKey());
        self::assertSame('global.help', $command->getHelpKey());
        self::assertSame(35, $command->getOrder());
        self::assertSame('global.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertSame(OperServPermission::GLOBAL, $command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
    }

    #[Test]
    public function mapsActorMessageAndTimestampThenPresentsSafeSuccess(): void
    {
        $trace = new GlobalCommandTrace();
        $network = new GlobalCommandNetwork($trace, ['GlobalServ' => '001GS'], 7);
        $audit = new GlobalCommandAudit($trace);
        $translation = new GlobalCommandTranslation();
        $before = new DateTimeImmutable();

        new GlobalCommand($this->handler($network, $audit))->execute(
            $this->context(['GlobalServ', 'notice', 'credential=must-not-enter-audit'], $translation, new GlobalCommandNotifier($trace)),
        );

        $after = new DateTimeImmutable();
        self::assertSame(['effect', 'audit', 'reply'], $trace->events);
        self::assertSame(['001GS', 'credential=must-not-enter-audit', GlobalMessageType::Notice], $network->broadcast);
        self::assertNotNull($audit->record);
        self::assertSame(CommandAuditCategory::OperatorAction, $audit->record->category);
        self::assertSame('RootOper', $audit->record->actor);
        self::assertSame('GLOBAL', $audit->record->operation);
        self::assertSame('GlobalServ', $audit->record->target);
        self::assertSame(OperServPermission::GLOBAL, $audit->record->permission);
        self::assertSame([
            'message_type' => 'NOTICE',
            'recipient_count' => 7,
            'sender_kind' => 'service',
        ], $audit->record->metadata);
        self::assertStringNotContainsString('must-not-enter-audit', serialize($audit->record));
        self::assertGreaterThanOrEqual((float) $before->format('U.u'), (float) $audit->record->occurredAt->format('U.u'));
        self::assertLessThanOrEqual((float) $after->format('U.u'), (float) $audit->record->occurredAt->format('U.u'));
        self::assertSame('global.done', $translation->lastKey);
        self::assertSame('GlobalServ', $translation->lastParameters['%nickname%']);
        self::assertSame('7', $translation->lastParameters['%count%']);
    }

    #[Test]
    public function presentsInvalidMessageTypeWithoutRecordingAudit(): void
    {
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::never())->method('record');
        $translation = new GlobalCommandTranslation();

        new GlobalCommand($this->handler(new GlobalCommandNetwork(new GlobalCommandTrace()), $audit))
            ->execute($this->context(['GlobalServ', 'INVALID', 'message'], $translation, new GlobalCommandNotifier(new GlobalCommandTrace())));

        self::assertSame('global.type_invalid', $translation->lastKey);
    }

    #[Test]
    public function missingSenderDoesNotReachTheUseCase(): void
    {
        $network = new GlobalCommandNetwork(new GlobalCommandTrace());

        new GlobalCommand($this->handler($network))->execute(
            $this->context(['GlobalServ', 'NOTICE', 'message'], new GlobalCommandTranslation(), new GlobalCommandNotifier(new GlobalCommandTrace()), true),
        );

        self::assertNull($network->broadcast);
    }

    /** @param array<string, mixed> $expectedParameters */
    #[Test]
    #[DataProvider('failurePresentations')]
    public function presentsSemanticFailures(
        string $sender,
        ?int $recipientCount,
        bool $connected,
        bool $registered,
        string $expectedKey,
        array $expectedParameters,
    ): void {
        $translation = new GlobalCommandTranslation();
        $users = $this->createStub(NetworkUserLookup::class);
        $users->method('findByNickname')->willReturn($connected ? new NetworkUser('001BBB', 'Taken', 'ident', 'host', 'ip', true, false) : null);
        $accounts = $this->createStub(OperatorAccountLookup::class);
        $accounts->method('findIdByNickname')->willReturn($registered ? 42 : null);
        $network = new GlobalCommandNetwork(
            new GlobalCommandTrace(),
            'GlobalServ' === $sender || str_starts_with($sender, 'GlobalServ!') ? ['GlobalServ' => '001GS'] : [],
            $recipientCount,
        );

        new GlobalCommand(new SendGlobalMessageHandler($users, $accounts, $network, $this->createStub(CommandAuditRecorder::class)))
            ->execute($this->context([$sender, 'NOTICE', 'message'], $translation, new GlobalCommandNotifier(new GlobalCommandTrace())));

        self::assertSame($expectedKey, $translation->lastKey);
        foreach ($expectedParameters as $key => $value) {
            self::assertSame($value, $translation->lastParameters['%' . $key . '%'] ?? null);
        }
    }

    /** @return iterable<string, array{string, ?int, bool, bool, string, array<string, mixed>}> */
    public static function failurePresentations(): iterable
    {
        yield 'invalid mask' => ['not-a-mask', 1, false, false, 'global.mask_invalid', ['error' => 'Invalid mask format. Expected: nick!ident@vhost']];
        yield 'connected nickname' => ['Taken!ident@host.test', 1, true, false, 'global.nick_connected', ['nickname' => 'Taken']];
        yield 'registered nickname' => ['Registered!ident@host.test', 1, false, true, 'global.nick_registered', ['nickname' => 'Registered']];
        yield 'service network unavailable' => ['GlobalServ', null, false, false, '', []];
        yield 'masked service network unavailable' => ['GlobalServ!ident@host.test', null, false, false, '', []];
        yield 'masked service success' => ['GlobalServ!ident@host.test', 2, false, false, 'global.done', ['nickname' => 'GlobalServ', 'count' => '2']];
        yield 'temporary network unavailable' => ['Temporary!ident@host.test', null, false, false, '', []];
    }

    private function handler(GlobalMessageTransport $network, ?CommandAuditRecorder $audit = null): SendGlobalMessageHandler
    {
        return new SendGlobalMessageHandler(
            $this->createStub(NetworkUserLookup::class),
            $this->createStub(OperatorAccountLookup::class),
            $network,
            $audit ?? $this->createStub(CommandAuditRecorder::class),
        );
    }

    /** @param list<string> $arguments */
    private function context(array $arguments, TranslationInterface $translator, OperServNotifierInterface $notifier, bool $withoutSender = false): OperServContext
    {
        return new OperServContext(
            $withoutSender ? null : new SenderView('001AAA', 'RootOper', 'ident', 'host', 'cloak', 'ip', true, true),
            null,
            'GLOBAL',
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

final class GlobalCommandTrace
{
    /** @var list<string> */
    public array $events = [];
}

final class GlobalCommandTranslation implements TranslationInterface
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

final readonly class GlobalCommandNotifier implements OperServNotifierInterface
{
    public function __construct(private GlobalCommandTrace $trace) {}

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

final class GlobalCommandNetwork implements GlobalMessageTransport
{
    /** @var array{string, string, GlobalMessageType}|null */
    public ?array $broadcast = null;

    /**
     * @param array<string, string> $serviceUids
     */
    public function __construct(
        private readonly GlobalCommandTrace $trace,
        private readonly array $serviceUids = [],
        private readonly ?int $recipientCount = null,
    ) {}

    public function serviceUidForNickname(string $nickname): ?string
    {
        return $this->serviceUids[$nickname] ?? null;
    }

    public function broadcastFromService(string $senderUid, string $message, GlobalMessageType $messageType): ?int
    {
        $this->broadcast = [$senderUid, $message, $messageType];
        $this->trace->events[] = 'effect';

        return $this->recipientCount;
    }

    public function broadcastFromTemporaryClient(GlobalMessageMask $sender, string $message, GlobalMessageType $messageType, string $actorNickname): ?int
    {
        $this->trace->events[] = 'effect';

        return $this->recipientCount;
    }
}

final class GlobalCommandAudit implements CommandAuditRecorder
{
    public ?CommandAuditRecord $record = null;

    public function __construct(private readonly GlobalCommandTrace $trace) {}

    public function record(CommandAuditRecord $record): void
    {
        $this->record = $record;
        $this->trace->events[] = 'audit';
    }
}
