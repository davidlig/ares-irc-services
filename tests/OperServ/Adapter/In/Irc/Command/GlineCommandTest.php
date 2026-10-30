<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
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
use App\Shared\Adapter\In\Irc\Presentation\Appearance;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_column;
use function array_map;

#[CoversClass(GlineCommand::class)]
#[CoversClass(ManageGline::class)]
#[CoversClass(ManageGlineResult::class)]
#[CoversClass(GlineListEntry::class)]
final class GlineCommandTest extends TestCase
{
    #[Test]
    public function presentsGlineSuccessAndFailureWithDistinctRoles(): void
    {
        $notifier = new RecordingGlineCommandNotifier();
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle(markers: ['success' => '+', 'error' => '!'])));
        new GlineCommand(new RecordingGlineCommandHandler(new ManageGlineResult(ManageGlineOutcome::Deleted, mask: '*@example.org')))->execute($this->context(['DEL', '*@example.org'], $notifier, renderer: $renderer));
        new GlineCommand(new RecordingGlineCommandHandler(new ManageGlineResult(ManageGlineOutcome::InvalidMask)))->execute($this->context(['DEL', '*@example.org'], $notifier, renderer: $renderer));
        self::assertSame(['+ gline.del.done', '! gline.invalid_mask'], $notifier->messages);
    }

    #[Test]
    public function listEntriesRenderSemanticFragmentsWithoutEmbeddingColorsInCommands(): void
    {
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => 'gline.list.entry' === $key ? '%index% %mask% %nickname%' : $key);
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle(components: [
            'danger' => new Appearance(prefix: '[danger]', suffix: '[/danger]'),
            'muted' => new Appearance(prefix: '[muted]', suffix: '[/muted]'),
        ])));
        $context = new OperServContext(
            new SenderView('UID', 'Oper', 'i', 'h', 'c', 'ip'),
            null,
            'GLINE',
            ['LIST'],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new OperServCommandRegistry([]),
            new ServiceNicknameRegistry([]),
            $this->createStub(OperatorAuthorizationQuery::class),
            $renderer
        );
        $result = new ManageGlineResult(ManageGlineOutcome::Listed, entries: [new GlineListEntry('*@host', 'reason', 'Nick', null)]);
        new GlineCommand(new RecordingGlineCommandHandler($result))->execute($context);
        self::assertSame('[muted]1[/muted] [danger]*@host[/danger] [muted]Nick[/muted]', $messages[1]);
    }

    #[Test]
    public function exposesCommandMetadata(): void
    {
        $command = new GlineCommand(new RecordingGlineCommandHandler());

        self::assertSame('GLINE', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('gline.syntax', $command->getSyntaxKey());
        self::assertSame('gline.help', $command->getHelpKey());
        self::assertSame('gline.example', $command->getExampleKey());
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

    #[Test]
    public function rejectsMissingAddOperandsBeforeCallingTheUseCase(): void
    {
        $handler = new RecordingGlineCommandHandler();
        $notifier = new RecordingGlineCommandNotifier();

        new GlineCommand($handler)->execute($this->context(['ADD', 'ident@host.test'], $notifier));

        self::assertSame([], $handler->inputs);
        self::assertSame(['error.syntax'], $notifier->messages);
        self::assertSame('gline.add.syntax', $notifier->translations->parameters['error.syntax']['%syntax%']);
    }

    #[Test]
    public function rejectsExtraDeleteOperandsBeforeCallingTheUseCase(): void
    {
        $handler = new RecordingGlineCommandHandler();
        $notifier = new RecordingGlineCommandNotifier();

        new GlineCommand($handler)->execute($this->context(['DEL', 'ident@host.test', 'ignored'], $notifier));

        self::assertSame([], $handler->inputs);
        self::assertSame(['error.syntax'], $notifier->messages);
        self::assertSame('gline.del.syntax', $notifier->translations->parameters['error.syntax']['%syntax%']);
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
        yield 'invalid add' => [new ManageGlineResult(ManageGlineOutcome::InvalidRequest), ['ADD', 'ident@host', '1h', 'reason'], 'error.syntax'];
        yield 'invalid delete' => [new ManageGlineResult(ManageGlineOutcome::InvalidRequest), ['DEL', 'ident@host'], 'error.syntax'];
        yield 'invalid mask' => [new ManageGlineResult(ManageGlineOutcome::InvalidMask), ['ADD', 'ident@host', '1h', 'reason'], 'gline.invalid_mask'];
        yield 'missing user' => [new ManageGlineResult(ManageGlineOutcome::UserNotFound, mask: 'Missing'), ['ADD', 'ident@host', '1h', 'reason'], 'error.nick_not_online'];
        yield 'global mask' => [new ManageGlineResult(ManageGlineOutcome::GlobalMask, mask: '*@*'), ['ADD', '*@*', '1h', 'reason'], 'gline.global_mask'];
        yield 'dangerous mask' => [new ManageGlineResult(ManageGlineOutcome::DangerousMask, mask: '*@a'), ['ADD', '*@a', '1h', 'reason'], 'gline.dangerous_mask'];
        yield 'protected user' => [new ManageGlineResult(ManageGlineOutcome::ProtectedUser, protectedNickname: 'Root'), ['ADD', 'ident@host', '1h', 'reason'], 'gline.protected_user'];
        yield 'invalid expiry' => [new ManageGlineResult(ManageGlineOutcome::InvalidExpiry), ['ADD', 'ident@host', 'invalid', 'reason'], 'gline.invalid_expiry'];
        yield 'duplicate' => [new ManageGlineResult(ManageGlineOutcome::AlreadyExists, mask: 'ident@host'), ['ADD', 'ident@host', '1h', 'reason'], 'gline.already_exists'];
        yield 'limit' => [new ManageGlineResult(ManageGlineOutcome::LimitReached, limit: 1000), ['ADD', 'ident@host', '1h', 'reason'], 'gline.max_entries'];
        yield 'not found' => [new ManageGlineResult(ManageGlineOutcome::NotFound, mask: 'missing'), ['DEL', 'missing'], 'gline.not_found'];
        yield 'empty list' => [new ManageGlineResult(ManageGlineOutcome::ListEmpty), ['LIST'], 'gline.list.empty'];
        yield 'deleted' => [new ManageGlineResult(ManageGlineOutcome::Deleted, mask: 'ident@host'), ['DEL', 'ident@host'], 'gline.del.done'];
    }

    #[Test]
    public function presentsPermanentAndTemporaryAdditions(): void
    {
        $permanent = new RecordingGlineCommandNotifier();
        new GlineCommand(new RecordingGlineCommandHandler(new ManageGlineResult(ManageGlineOutcome::Added, 'ident@host', expiry: '0', reason: 'reason')))
            ->execute($this->context(['ADD', 'ident@host', '0', 'reason'], $permanent));
        $temporary = new RecordingGlineCommandNotifier();
        new GlineCommand(new RecordingGlineCommandHandler(new ManageGlineResult(ManageGlineOutcome::Added, 'ident@host', expiry: '1h', reason: 'reason')))
            ->execute($this->context(['ADD', 'ident@host', '1h', 'reason'], $temporary));

        self::assertSame('label.permanent_inline', $permanent->translations->parameters['gline.add.done']['%duration%']);
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
        self::assertSame('label.no_reason', $notifier->translations->entryParameters[0]['%reason%']);
        self::assertSame('label.unknown_creator', $notifier->translations->entryParameters[0]['%nickname%']);
        self::assertSame('label.permanent', $notifier->translations->entryParameters[0]['%expiration%']);
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
    private function context(array $arguments, ?RecordingGlineCommandNotifier $notifier = null, bool $withoutSender = false, ?IrcRenderer $renderer = null): OperServContext
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
            $renderer,
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

final class RecordingGlineCommandTranslation implements TranslatorInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $parameters = [];

    /** @var list<array<string, mixed>> */
    public array $entryParameters = [];

    /** @param array<string, mixed> $parameters */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        if ('gline.list.entry' === $id) {
            $this->entryParameters[] = $parameters;
        } else {
            $this->parameters[$id] = $parameters;
        }

        return $id;
    }

    public function getLocale(): string
    {
        return 'en';
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
