<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc\Command;

use App\Application\Port\TranslationInterface;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Application\Port\In\NickAccountData;
use App\OperServ\Adapter\In\Irc\Command\MotdCommand;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\OperServ\Application\UseCase\ManageMotd\ManageMotd;
use App\OperServ\Application\UseCase\ManageMotd\ManageMotdHandlerInterface;
use App\OperServ\Application\UseCase\ManageMotd\ManageMotdOutcome;
use App\OperServ\Application\UseCase\ManageMotd\ManageMotdResult;
use App\OperServ\Application\UseCase\ManageMotd\MotdAction;
use App\OperServ\Application\UseCase\ManageMotd\MotdListEntry;
use App\Shared\Application\ServiceNicknameRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_slice;

#[CoversClass(MotdCommand::class)]
#[CoversClass(ManageMotd::class)]
#[CoversClass(ManageMotdResult::class)]
#[CoversClass(MotdListEntry::class)]
final class MotdCommandTest extends TestCase
{
    #[Test]
    public function exposesCommandMetadata(): void
    {
        $command = new MotdCommand(new RecordingMotdCommandHandler());

        self::assertSame('MOTD', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('motd.syntax', $command->getSyntaxKey());
        self::assertSame('motd.help', $command->getHelpKey());
        self::assertSame(39, $command->getOrder());
        self::assertSame('motd.short', $command->getShortDescKey());
        self::assertSame(['ADD', 'DEL', 'LIST', 'CLEAN'], array_column($command->getSubCommandHelp(), 'name'));
        self::assertTrue($command->isOperOnly());
        self::assertSame('operserv.motd', $command->getRequiredPermission());
    }

    #[Test]
    public function mapsAllSubcommandsAndArguments(): void
    {
        $handler = new RecordingMotdCommandHandler();
        $command = new MotdCommand($handler);
        $command->execute($this->context(['ADD', ' NickServ ', ' notice ', ' 1h ', ' Welcome ', 'all']));
        $command->execute($this->context(['DEL', ' 42 ']));
        $command->execute($this->context(['LIST']));
        $command->execute($this->context(['CLEAN']));
        $command->execute($this->context(['unknown']));

        self::assertSame([MotdAction::Add, MotdAction::Delete, MotdAction::List, MotdAction::Clean, MotdAction::Unknown], array_map(static fn (ManageMotd $input): MotdAction => $input->action, $handler->inputs));
        self::assertSame('Oper', $handler->inputs[0]->actor);
        self::assertSame(42, $handler->inputs[0]->actorAccountId);
        self::assertSame('NickServ', $handler->inputs[0]->botNickname);
        self::assertSame('NOTICE', $handler->inputs[0]->messageType);
        self::assertSame('1h', $handler->inputs[0]->expiry);
        self::assertSame('Welcome  all', $handler->inputs[0]->text);
        self::assertSame('42', $handler->inputs[1]->id);
    }

    /** @param list<string> $arguments */
    #[Test]
    #[DataProvider('simplePresentations')]
    public function presentsSemanticOutcomes(ManageMotdResult $result, array $arguments, string $expectedKey): void
    {
        $notifier = new RecordingMotdCommandNotifier();

        new MotdCommand(new RecordingMotdCommandHandler($result))->execute($this->context($arguments, $notifier));

        self::assertSame($expectedKey, $notifier->messages[0]);
    }

    /** @return iterable<string, array{ManageMotdResult, list<string>, string}> */
    public static function simplePresentations(): iterable
    {
        yield 'unknown' => [new ManageMotdResult(ManageMotdOutcome::UnknownAction), ['what'], 'motd.unknown_sub'];
        yield 'invalid add' => [new ManageMotdResult(ManageMotdOutcome::InvalidAddRequest), ['ADD'], 'motd.add.syntax_hint'];
        yield 'invalid type' => [new ManageMotdResult(ManageMotdOutcome::InvalidMessageType), ['ADD'], 'motd.add.invalid_type'];
        yield 'invalid expiry' => [new ManageMotdResult(ManageMotdOutcome::InvalidExpiry), ['ADD'], 'motd.add.invalid_expiry'];
        yield 'empty list' => [new ManageMotdResult(ManageMotdOutcome::ListEmpty), ['LIST'], 'motd.list.empty'];
        yield 'empty clean' => [new ManageMotdResult(ManageMotdOutcome::CleanEmpty), ['CLEAN'], 'motd.clean.none'];
        yield 'added' => [new ManageMotdResult(ManageMotdOutcome::Added, 4), ['ADD'], 'motd.add.done'];
        yield 'deleted' => [new ManageMotdResult(ManageMotdOutcome::Deleted, 4), ['DEL', '4'], 'motd.del.done'];
        yield 'cleaned' => [new ManageMotdResult(ManageMotdOutcome::Cleaned, removedCount: 2), ['CLEAN'], 'motd.clean.done'];
    }

    #[Test]
    public function distinguishesMissingInvalidAndNumericDeleteFailures(): void
    {
        foreach ([
            [new ManageMotdResult(ManageMotdOutcome::InvalidId), ['DEL']],
            [new ManageMotdResult(ManageMotdOutcome::InvalidId), ['DEL', 'not-a-number']],
            [new ManageMotdResult(ManageMotdOutcome::NotFound), ['DEL', '42']],
        ] as [$result, $arguments]) {
            $notifier = new RecordingMotdCommandNotifier();
            new MotdCommand(new RecordingMotdCommandHandler($result))->execute($this->context($arguments, $notifier));
            self::assertSame('motd.del.' . ([] === array_slice($arguments, 1) ? 'syntax_hint' : 'not_found'), $notifier->messages[0]);
        }
    }

    #[Test]
    public function presentsListWithEveryStatusAndFallback(): void
    {
        $notifier = new RecordingMotdCommandNotifier();
        $result = new ManageMotdResult(ManageMotdOutcome::Listed, entries: [
            new MotdListEntry(1, 'Expired', '', 'NOTICE', true, true, null, 0),
            new MotdListEntry(2, 'Enabled', 'NickServ', 'PRIVMSG', true, false, new DateTimeImmutable('2026-09-08T10:00:00+00:00'), 2),
            new MotdListEntry(3, 'Disabled', 'OperServ', 'NOTICE', false, false, null, 3),
        ]);

        new MotdCommand(new RecordingMotdCommandHandler($result))->execute($this->context(['LIST'], $notifier));

        self::assertSame('motd.list.header', $notifier->messages[0]);
        self::assertStringContainsString('motd.list.expired', $notifier->messages[1]);
        self::assertStringContainsString('motd.list.no_bot', $notifier->messages[1]);
        self::assertStringContainsString('motd.list.never', $notifier->messages[1]);
        self::assertStringContainsString('motd.list.status_enabled', $notifier->messages[2]);
        self::assertStringContainsString('08/09/2026 10:00 UTC', $notifier->messages[2]);
        self::assertStringContainsString('motd.list.status_disabled', $notifier->messages[3]);
    }

    #[Test]
    public function ignoresMissingSender(): void
    {
        $handler = new RecordingMotdCommandHandler();
        $notifier = new RecordingMotdCommandNotifier();

        new MotdCommand($handler)->execute($this->context(['LIST'], $notifier, true));

        self::assertSame([], $handler->inputs);
        self::assertSame([], $notifier->messages);
    }

    /** @param list<string> $arguments */
    private function context(array $arguments, ?RecordingMotdCommandNotifier $notifier = null, bool $withoutSender = false): OperServContext
    {
        $notifier ??= new RecordingMotdCommandNotifier();

        return new OperServContext(
            $withoutSender ? null : new SenderView('001AAA', 'Oper', 'ident', 'host', 'cloak', 'ip', true, true),
            $withoutSender ? null : new NickAccountData(42, 'Oper', 'en'),
            'MOTD',
            $arguments,
            $notifier,
            $notifier->translations,
            'en',
            'UTC',
            'NOTICE',
            new OperServCommandRegistry([]),
            new ServiceNicknameRegistry([]),
            $this->createStub(OperatorAuthorizationQuery::class),
        );
    }
}

final class RecordingMotdCommandHandler implements ManageMotdHandlerInterface
{
    /** @var list<ManageMotd> */
    public array $inputs = [];

    public function __construct(private readonly ManageMotdResult $result = new ManageMotdResult(ManageMotdOutcome::UnknownAction)) {}

    public function handle(ManageMotd $command): ManageMotdResult
    {
        $this->inputs[] = $command;

        return $this->result;
    }
}

final class RecordingMotdCommandTranslation implements TranslationInterface
{
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $id;
    }
}

final class RecordingMotdCommandNotifier implements OperServNotifierInterface
{
    /** @var list<string> */
    public array $messages = [];

    public RecordingMotdCommandTranslation $translations;

    public function __construct()
    {
        $this->translations = new RecordingMotdCommandTranslation();
    }

    public function sendNotice(string $targetUidOrNick, string $message): void
    {
        $this->messages[] = $message;
    }

    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void
    {
        $this->messages[] = $message;
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
