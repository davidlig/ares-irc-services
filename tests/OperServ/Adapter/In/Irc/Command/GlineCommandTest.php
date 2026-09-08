<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Application\Port\In\NickAccountData;
use App\OperServ\Adapter\In\Irc\Command\GlineCommand;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\OperServ\Application\UseCase\ManageGline\GlineAction;
use App\OperServ\Application\UseCase\ManageGline\GlineListEntry;
use App\OperServ\Application\UseCase\ManageGline\ManageGline;
use App\OperServ\Application\UseCase\ManageGline\ManageGlineHandlerInterface;
use App\OperServ\Application\UseCase\ManageGline\ManageGlineOutcome;
use App\OperServ\Application\UseCase\ManageGline\ManageGlineResult;
use App\Shared\Application\Port\TranslationInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(GlineCommand::class)]
#[CoversClass(ManageGline::class)]
#[CoversClass(ManageGlineResult::class)]
#[CoversClass(GlineListEntry::class)]
final class GlineCommandTest extends TestCase
{
    #[Test]
    public function exposesCommandMetadata(): void
    {
        $command = new GlineCommand(new RecordingGlineCommandHandler());

        self::assertSame('GLINE', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('gline.syntax', $command->getSyntaxKey());
        self::assertSame('gline.help', $command->getHelpKey());
        self::assertSame(20, $command->getOrder());
        self::assertSame('gline.short', $command->getShortDescKey());
        self::assertSame(['ADD', 'DEL', 'LIST'], array_column($command->getSubCommandHelp(), 'name'));
        self::assertTrue($command->isOperOnly());
        self::assertSame('operserv.gline', $command->getRequiredPermission());
    }

    #[Test]
    public function mapsAddDeleteListAndUnknownArguments(): void
    {
        $handler = new RecordingGlineCommandHandler();
        $command = new GlineCommand($handler);
        $command->execute($this->context(['ADD', ' Nick ', ' 1d ', ' abuse ', 'reason']));
        $command->execute($this->context(['DEL', ' ident@host.test ']));
        $command->execute($this->context(['LIST', ' *@host.* ']));
        $command->execute($this->context(['unexpected']));

        self::assertSame([GlineAction::Add, GlineAction::Delete, GlineAction::List, GlineAction::Unknown], array_map(static fn (ManageGline $input): GlineAction => $input->action, $handler->inputs));
        self::assertSame('Oper', $handler->inputs[0]->actor);
        self::assertSame(42, $handler->inputs[0]->actorAccountId);
        self::assertSame('Nick', $handler->inputs[0]->mask);
        self::assertSame('1d', $handler->inputs[0]->expiry);
        self::assertSame('abuse  reason', $handler->inputs[0]->reason);
        self::assertSame('ident@host.test', $handler->inputs[1]->mask);
        self::assertSame('*@host.*', $handler->inputs[2]->listPattern);
    }

    /** @param list<string> $arguments */
    #[Test]
    #[DataProvider('simplePresentations')]
    public function presentsSemanticOutcomes(ManageGlineResult $result, array $arguments, string $expectedKey): void
    {
        $notifier = new RecordingGlineCommandNotifier();
        $command = new GlineCommand(new RecordingGlineCommandHandler($result));

        $command->execute($this->context($arguments, $notifier));

        self::assertSame($expectedKey, $notifier->messages[0]);
    }

    /** @return iterable<string, array{ManageGlineResult, list<string>, string}> */
    public static function simplePresentations(): iterable
    {
        yield 'unknown action' => [new ManageGlineResult(ManageGlineOutcome::UnknownAction), ['what'], 'gline.unknown_sub'];
        yield 'invalid add' => [new ManageGlineResult(ManageGlineOutcome::InvalidRequest), ['ADD'], 'error.syntax'];
        yield 'invalid delete' => [new ManageGlineResult(ManageGlineOutcome::InvalidRequest), ['DEL'], 'error.syntax'];
        yield 'invalid mask' => [new ManageGlineResult(ManageGlineOutcome::InvalidMask), ['ADD'], 'gline.invalid_mask'];
        yield 'missing user' => [new ManageGlineResult(ManageGlineOutcome::UserNotFound, mask: 'Missing'), ['ADD'], 'gline.user_not_found'];
        yield 'global mask' => [new ManageGlineResult(ManageGlineOutcome::GlobalMask, mask: '*@*'), ['ADD'], 'gline.global_mask'];
        yield 'dangerous mask' => [new ManageGlineResult(ManageGlineOutcome::DangerousMask, mask: '*@a'), ['ADD'], 'gline.dangerous_mask'];
        yield 'protected user' => [new ManageGlineResult(ManageGlineOutcome::ProtectedUser, protectedNickname: 'Root'), ['ADD'], 'gline.protected_user'];
        yield 'invalid expiry' => [new ManageGlineResult(ManageGlineOutcome::InvalidExpiry), ['ADD'], 'gline.invalid_expiry'];
        yield 'duplicate' => [new ManageGlineResult(ManageGlineOutcome::AlreadyExists, mask: 'ident@host'), ['ADD'], 'gline.already_exists'];
        yield 'limit' => [new ManageGlineResult(ManageGlineOutcome::LimitReached, limit: 1000), ['ADD'], 'gline.max_entries'];
        yield 'not found' => [new ManageGlineResult(ManageGlineOutcome::NotFound, mask: 'missing'), ['DEL'], 'gline.not_found'];
        yield 'empty list' => [new ManageGlineResult(ManageGlineOutcome::ListEmpty), ['LIST'], 'gline.list.empty'];
        yield 'deleted' => [new ManageGlineResult(ManageGlineOutcome::Deleted, mask: 'ident@host'), ['DEL'], 'gline.del.done'];
    }

    #[Test]
    public function presentsPermanentAndTemporaryAdditions(): void
    {
        $permanent = new RecordingGlineCommandNotifier();
        new GlineCommand(new RecordingGlineCommandHandler(new ManageGlineResult(ManageGlineOutcome::Added, 'ident@host', expiry: '0', reason: 'reason')))
            ->execute($this->context(['ADD'], $permanent));
        $temporary = new RecordingGlineCommandNotifier();
        new GlineCommand(new RecordingGlineCommandHandler(new ManageGlineResult(ManageGlineOutcome::Added, 'ident@host', expiry: '1h', reason: 'reason')))
            ->execute($this->context(['ADD'], $temporary));

        self::assertSame('gline.permanent', $permanent->translations->parameters['gline.add.done']['%duration%']);
        self::assertSame('1h', $temporary->translations->parameters['gline.add.done']['%duration%']);
    }

    #[Test]
    public function presentsListFallbacksAndFormattedValues(): void
    {
        $notifier = new RecordingGlineCommandNotifier();
        $result = new ManageGlineResult(ManageGlineOutcome::Listed, entries: [
            new GlineListEntry('*@one.test', null, null, null),
            new GlineListEntry('ident@two.test', 'reason', 'Creator', new DateTimeImmutable('2026-09-08T10:00:00+00:00')),
        ]);

        new GlineCommand(new RecordingGlineCommandHandler($result))->execute($this->context(['LIST'], $notifier));

        self::assertSame(['gline.list.header', 'gline.list.entry', 'gline.list.entry'], $notifier->messages);
        self::assertSame('gline.list.no_reason', $notifier->translations->entryParameters[0]['%reason%']);
        self::assertSame('gline.list.unknown_creator', $notifier->translations->entryParameters[0]['%nickname%']);
        self::assertSame('gline.list.never_expires', $notifier->translations->entryParameters[0]['%expiration%']);
        self::assertSame('08/09/2026 10:00 UTC', $notifier->translations->entryParameters[1]['%expiration%']);
    }

    #[Test]
    public function ignoresMissingSender(): void
    {
        $handler = new RecordingGlineCommandHandler();
        $notifier = new RecordingGlineCommandNotifier();

        new GlineCommand($handler)->execute($this->context(['LIST'], $notifier, true));

        self::assertSame([], $handler->inputs);
        self::assertSame([], $notifier->messages);
    }

    /** @param list<string> $arguments */
    private function context(array $arguments, ?RecordingGlineCommandNotifier $notifier = null, bool $withoutSender = false): OperServContext
    {
        $notifier ??= new RecordingGlineCommandNotifier();

        return new OperServContext(
            $withoutSender ? null : new SenderView('001AAA', 'Oper', 'ident', 'host', 'cloak', 'ip', true, true),
            $withoutSender ? null : new NickAccountData(42, 'Oper', 'en'),
            'GLINE',
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

final class RecordingGlineCommandHandler implements ManageGlineHandlerInterface
{
    /** @var list<ManageGline> */
    public array $inputs = [];

    public function __construct(private readonly ManageGlineResult $result = new ManageGlineResult(ManageGlineOutcome::UnknownAction)) {}

    public function handle(ManageGline $command): ManageGlineResult
    {
        $this->inputs[] = $command;

        return $this->result;
    }
}

final class RecordingGlineCommandTranslation implements TranslationInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $parameters = [];

    /** @var list<array<string, mixed>> */
    public array $entryParameters = [];

    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        if ('gline.list.entry' === $id) {
            $this->entryParameters[] = $parameters;
        } else {
            $this->parameters[$id] = $parameters;
        }

        return $id;
    }
}

final class RecordingGlineCommandNotifier implements OperServNotifierInterface
{
    /** @var list<string> */
    public array $messages = [];

    public RecordingGlineCommandTranslation $translations;

    public function __construct()
    {
        $this->translations = new RecordingGlineCommandTranslation();
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
