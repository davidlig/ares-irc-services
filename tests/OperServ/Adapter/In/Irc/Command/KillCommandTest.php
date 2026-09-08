<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\OperServ\Adapter\In\Irc\Command\KillCommand;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\OperServ\Application\Port\Out\KillNetworkUser as KillNetworkUserPort;
use App\OperServ\Application\Port\Out\NetworkUser;
use App\OperServ\Application\Port\Out\NetworkUserLookup;
use App\OperServ\Application\Port\Out\OperatorAccountLookup;
use App\OperServ\Application\Port\Out\OperatorRoleAccess;
use App\OperServ\Application\Port\Out\RootIdentityRegistry;
use App\OperServ\Application\Security\OperServPermission;
use App\OperServ\Application\UseCase\Kill\KillNetworkUser;
use App\OperServ\Application\UseCase\Kill\KillNetworkUserHandler;
use App\OperServ\Application\UseCase\Kill\KillNetworkUserResult;
use App\Shared\Application\Port\TranslationInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function in_array;

#[CoversClass(KillCommand::class)]
#[CoversClass(KillNetworkUser::class)]
#[CoversClass(KillNetworkUserHandler::class)]
#[CoversClass(KillNetworkUserResult::class)]
final class KillCommandTest extends TestCase
{
    #[Test]
    public function exposesOperOnlyPermissionAndHelpMetadata(): void
    {
        $command = new KillCommand($this->handler(new KillCommandUsers(), new KillCommandRoots(), new KillCommandNetwork(new KillCommandTrace())));

        self::assertSame('KILL', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(2, $command->getMinArgs());
        self::assertSame('kill.syntax', $command->getSyntaxKey());
        self::assertSame('kill.help', $command->getHelpKey());
        self::assertSame(10, $command->getOrder());
        self::assertSame('kill.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertTrue($command->isOperOnly());
        self::assertSame(OperServPermission::KILL, $command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
    }

    #[Test]
    public function mapsActorServiceReasonAndTimestampThenPresentsSuccess(): void
    {
        $trace = new KillCommandTrace();
        $target = new NetworkUser('001BBB', 'Target', 'ident', 'host.test', 'encoded-ip', false, false);
        $network = new KillCommandNetwork($trace);
        $audit = new KillCommandAudit($trace);
        $translation = new KillCommandTranslation();
        $before = new DateTimeImmutable();

        new KillCommand($this->handler(new KillCommandUsers(['Target' => $target]), new KillCommandRoots(), $network, $audit))
            ->execute($this->context(['Target', 'repeated', 'abuse'], $translation, new KillCommandNotifier($trace)));

        $after = new DateTimeImmutable();
        self::assertSame(['effect', 'audit', 'reply'], $trace->events);
        self::assertSame([['001BBB', 'Killed (OperServ: RootOper): repeated abuse']], $network->kills);
        self::assertNotNull($audit->record);
        self::assertSame(CommandAuditCategory::OperatorAction, $audit->record->category);
        self::assertSame('RootOper', $audit->record->actor);
        self::assertSame('KILL', $audit->record->operation);
        self::assertSame('Target', $audit->record->target);
        self::assertSame('repeated abuse', $audit->record->reason);
        self::assertSame(OperServPermission::KILL, $audit->record->permission);
        self::assertSame('ident@host.test', $audit->record->targetHost);
        self::assertSame('encoded-ip', $audit->record->targetIp);
        self::assertGreaterThanOrEqual((float) $before->format('U.u'), (float) $audit->record->occurredAt->format('U.u'));
        self::assertLessThanOrEqual((float) $after->format('U.u'), (float) $audit->record->occurredAt->format('U.u'));
        self::assertSame('kill.done', $translation->lastKey);
        self::assertSame('Target', $translation->lastParameters['%nickname%']);
        self::assertSame('repeated abuse', $translation->lastParameters['%reason%']);
    }

    #[Test]
    public function presentsProtectedRootWithoutNetworkEffectOrAudit(): void
    {
        $trace = new KillCommandTrace();
        $network = new KillCommandNetwork($trace);
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::never())->method('record');
        $translation = new KillCommandTranslation();
        $target = new NetworkUser('001BBB', 'Protected', 'ident', 'host.test', 'encoded-ip', true, true);

        new KillCommand($this->handler(new KillCommandUsers(['Protected' => $target]), new KillCommandRoots(['protected']), $network, $audit))
            ->execute($this->context(['Protected', 'reason'], $translation, new KillCommandNotifier($trace)));

        self::assertSame([], $network->kills);
        self::assertSame(['reply'], $trace->events);
        self::assertSame('kill.protected_root', $translation->lastKey);
        self::assertSame('Protected', $translation->lastParameters['%nickname%']);
    }

    #[Test]
    public function missingSenderDoesNotReachTheUseCase(): void
    {
        $users = $this->createMock(NetworkUserLookup::class);
        $users->expects(self::never())->method('findByNickname');

        new KillCommand($this->handler($users, new KillCommandRoots(), new KillCommandNetwork(new KillCommandTrace())))
            ->execute($this->context(['Target', 'reason'], new KillCommandTranslation(), new KillCommandNotifier(new KillCommandTrace()), true));
    }

    #[Test]
    public function presentsOfflineTarget(): void
    {
        $translation = new KillCommandTranslation();

        new KillCommand($this->handler(new KillCommandUsers(), new KillCommandRoots(), new KillCommandNetwork(new KillCommandTrace())))
            ->execute($this->context(['Missing', 'reason'], $translation, new KillCommandNotifier(new KillCommandTrace())));

        self::assertSame('kill.user_not_online', $translation->lastKey);
        self::assertSame('Missing', $translation->lastParameters['%nickname%']);
    }

    #[Test]
    public function presentsProtectedAssignedIrcOperator(): void
    {
        $target = new NetworkUser('001BBB', 'ProtectedOper', 'ident', 'host.test', 'ip', true, true);
        $accounts = $this->createStub(OperatorAccountLookup::class);
        $accounts->method('findIdByNickname')->willReturn(42);
        $roles = $this->createStub(OperatorRoleAccess::class);
        $roles->method('hasAssignedRole')->willReturn(true);
        $translation = new KillCommandTranslation();

        new KillCommand($this->handler(
            new KillCommandUsers(['ProtectedOper' => $target]),
            new KillCommandRoots(),
            new KillCommandNetwork(new KillCommandTrace()),
            accounts: $accounts,
            roles: $roles,
        ))->execute($this->context(['ProtectedOper', 'reason'], $translation, new KillCommandNotifier(new KillCommandTrace())));

        self::assertSame('kill.protected_ircop', $translation->lastKey);
        self::assertSame('ProtectedOper', $translation->lastParameters['%nickname%']);
    }

    #[Test]
    public function networkFailureProducesNoSuccessPresentationOrAudit(): void
    {
        $target = new NetworkUser('001BBB', 'Target', 'ident', 'host.test', 'ip', false, false);
        $network = new KillCommandNetwork(new KillCommandTrace(), false);
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::never())->method('record');
        $translation = new KillCommandTranslation();

        new KillCommand($this->handler(new KillCommandUsers(['Target' => $target]), new KillCommandRoots(), $network, $audit))
            ->execute($this->context(['Target', 'reason'], $translation, new KillCommandNotifier(new KillCommandTrace())));

        self::assertSame('', $translation->lastKey);
    }

    private function handler(
        NetworkUserLookup $users,
        RootIdentityRegistry $roots,
        KillNetworkUserPort $network,
        ?CommandAuditRecorder $audit = null,
        ?OperatorAccountLookup $accounts = null,
        ?OperatorRoleAccess $roles = null,
    ): KillNetworkUserHandler {
        return new KillNetworkUserHandler(
            $users,
            $roots,
            $accounts ?? $this->createStub(OperatorAccountLookup::class),
            $roles ?? $this->createStub(OperatorRoleAccess::class),
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
            'KILL',
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

final class KillCommandTrace
{
    /** @var list<string> */
    public array $events = [];
}

final class KillCommandTranslation implements TranslationInterface
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

final readonly class KillCommandNotifier implements OperServNotifierInterface
{
    public function __construct(private KillCommandTrace $trace) {}

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

final readonly class KillCommandUsers implements NetworkUserLookup
{
    /** @param array<string, NetworkUser> $users */
    public function __construct(private array $users = []) {}

    public function findByNickname(string $nickname): ?NetworkUser
    {
        return $this->users[$nickname] ?? null;
    }
}

final readonly class KillCommandRoots implements RootIdentityRegistry
{
    /** @param list<string> $nicknames */
    public function __construct(private array $nicknames = []) {}

    public function contains(string $nickname): bool
    {
        return in_array(strtolower($nickname), $this->nicknames, true);
    }

    public function allNicknames(): array
    {
        return $this->nicknames;
    }
}

final class KillCommandNetwork implements KillNetworkUserPort
{
    /** @var list<array{string, string}> */
    public array $kills = [];

    public function __construct(private readonly KillCommandTrace $trace, private readonly bool $successful = true) {}

    public function kill(string $targetUid, string $reason): bool
    {
        $this->kills[] = [$targetUid, $reason];
        $this->trace->events[] = 'effect';

        return $this->successful;
    }
}

final class KillCommandAudit implements CommandAuditRecorder
{
    public ?CommandAuditRecord $record = null;

    public function __construct(private readonly KillCommandTrace $trace) {}

    public function record(CommandAuditRecord $record): void
    {
        $this->record = $record;
        $this->trace->events[] = 'audit';
    }
}
