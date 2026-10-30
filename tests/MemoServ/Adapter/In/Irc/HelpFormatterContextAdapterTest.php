<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\MemoServ\Adapter\In\Irc\HelpFormatterContextAdapter;
use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServCommandRegistry;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Appearance;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_fill;
use function iterator_to_array;
use function strtr;

#[CoversClass(HelpFormatterContextAdapter::class)]
final class HelpFormatterContextAdapterTest extends TestCase
{
    #[Test]
    public function rendersStructuredHelpAndExamplesWithTheSelectedStyle(): void
    {
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('CustomMemo');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params): string {
            $templates = [
                'help.general_header' => 'Commands', 'help.group_header' => '%group%',
                'help.command_line' => '%command%: %description%', 'help.syntax_label' => 'Syntax %syntax%',
                'help.general_footer' => 'Use %help_command%', 'help.set_sub_footer' => 'Use %help_command%',
                'help.general_help_command' => 'HELP <command>', 'help.option_help_command' => 'HELP %command% <option>',
            ];

            return strtr($templates[$id] ?? $id, $params);
        });
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle(components: [
            'section' => new Appearance(prefix: 'S:'), 'group' => new Appearance(prefix: 'G:'),
            'command_row' => new Appearance(prefix: 'R:'), 'command' => new Appearance(prefix: '[', suffix: ']'),
            'syntax' => new Appearance(prefix: 'Y:'), 'navigation' => new Appearance(prefix: 'N:'),
            'marked_example' => new Appearance(prefix: '> '),
        ])));
        $context = new MemoServContext(null, null, 'HELP', [], $notifier, $translator, 'en', 'UTC', 'NOTICE', new MemoServCommandRegistry([]), $this->createServiceNicks(), $renderer);
        $adapter = new HelpFormatterContextAdapter($context);
        self::assertSame('S:Commands', $adapter->trans('help.general_header'));
        self::assertSame('G:Messages', $adapter->trans('help.group_header', ['group' => 'Messages']));
        self::assertSame('R:[SEND]: Message', $adapter->trans('help.command_line', ['command' => 'SEND', 'description' => 'Message']));
        self::assertSame('Y:Syntax [SEND <nick>]', $adapter->trans('help.syntax_label', ['syntax' => 'SEND <nick>']));
        self::assertSame('N:Use [HELP <command>]', $adapter->trans('help.general_footer'));
        self::assertSame('N:Use [HELP IGNORE <option>]', $adapter->trans('help.set_sub_footer', ['command' => 'IGNORE']));
        self::assertSame('> /msg CustomMemo [SEND Bob hi]', $adapter->renderExample('%command_prefix%SEND Bob hi'));
        self::assertSame('legacy text', $adapter->renderExample('legacy text'));
        self::assertStringContainsString('Help', $adapter->trans('help.header', ['title' => 'Help']));
        self::assertNotSame('', $adapter->trans('help.footer'));
        try {
            $adapter->trans('help.command_line', ['command' => ['invalid']]);
            self::fail('A non-text HELP parameter must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('HELP parameters must be text.', $exception->getMessage());
        }
    }

    #[Test]
    public function replyDelegatesToContext(): void
    {
        $sent = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $msg) use (&$sent): void {
            $sent[] = ['uid' => $uid, 'msg' => $msg];
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Translated text');
        $registry = new MemoServCommandRegistry([]);
        $context = new MemoServContext(
            new SenderView('UID1', 'N', 'i', 'h', 'c', 'ip'),
            null,
            'HELP',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createServiceNicks(),
        );
        $adapter = new HelpFormatterContextAdapter($context);

        $adapter->reply('help.header_title');

        self::assertCount(1, $sent);
        self::assertSame('Translated text', $sent[0]['msg']);
    }

    #[Test]
    public function replyRawDelegatesToContext(): void
    {
        $sent = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $msg) use (&$sent): void {
            $sent[] = $msg;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $registry = new MemoServCommandRegistry([]);
        $context = new MemoServContext(
            new SenderView('UID1', 'N', 'i', 'h', 'c', 'ip'),
            null,
            'HELP',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createServiceNicks(),
        );
        $adapter = new HelpFormatterContextAdapter($context);

        $adapter->replyRaw('Raw line');

        self::assertCount(1, $sent);
        self::assertSame('Raw line', $sent[0]);
    }

    #[Test]
    public function transDelegatesToContextAndReturnsString(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $context = new MemoServContext(
            null,
            null,
            'HELP',
            [],
            $this->createStub(MemoServNotifierInterface::class),
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new MemoServCommandRegistry([]),
            $this->createServiceNicks(),
        );
        $adapter = new HelpFormatterContextAdapter($context);

        $result = $adapter->trans('help.syntax');

        self::assertSame('help.syntax', $result);
    }

    #[Test]
    public function getCommandsForGeneralHelpReturnsRegistryAll(): void
    {
        $handler = $this->createCommandStub('SEND');
        $registry = new MemoServCommandRegistry([$handler]);
        $context = new MemoServContext(
            null,
            null,
            'HELP',
            [],
            $this->createStub(MemoServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createServiceNicks(),
        );
        $adapter = new HelpFormatterContextAdapter($context);

        $commands = $adapter->getCommandsForGeneralHelp();

        self::assertCount(1, [...$commands]);
        $first = [...$commands][0];
        self::assertSame($handler, $first);
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpReturnsTrueWhenNotOperOnly(): void
    {
        $command = $this->createCommandStub('LIST', false);
        $adapter = new HelpFormatterContextAdapter($this->createMinimalContext());

        self::assertTrue($adapter->shouldShowCommandInGeneralHelp($command));
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpReturnsFalseWhenOperOnly(): void
    {
        $command = $this->createCommandStub('ADMIN', true);
        $adapter = new HelpFormatterContextAdapter($this->createMinimalContext());

        self::assertFalse($adapter->shouldShowCommandInGeneralHelp($command));
    }

    #[Test]
    public function getIrcopCommandsReturnsEmpty(): void
    {
        $adapter = new HelpFormatterContextAdapter($this->createMinimalContext());

        self::assertSame([], iterator_to_array($adapter->getIrcopCommands()));
    }

    #[Test]
    public function hasIrcopAccessReturnsFalse(): void
    {
        $adapter = new HelpFormatterContextAdapter($this->createMinimalContext());

        self::assertFalse($adapter->hasIrcopAccess());
    }

    #[Test]
    public function canViewPublicButNotOperOnlyCommandAndExposesHelpGroups(): void
    {
        $adapter = new HelpFormatterContextAdapter($this->createMinimalContext());

        self::assertTrue($adapter->canViewCommandInHelp($this->createCommandStub('PUBLIC')));
        self::assertFalse($adapter->canViewCommandInHelp($this->createCommandStub('OPER', true)));
        self::assertSame('help.group.messages', $adapter->getHelpGroups()[0]['group_key']);
    }

    #[Test]
    public function exposesTheConfiguredBotNickname(): void
    {
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('CustomMemo');
        $context = new MemoServContext(
            null,
            null,
            'HELP',
            [],
            $notifier,
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            new MemoServCommandRegistry([]),
            $this->createServiceNicks(),
        );

        self::assertSame('CustomMemo', new HelpFormatterContextAdapter($context)->getBotName());
    }

    private function createMinimalContext(): MemoServContext
    {
        return new MemoServContext(
            null,
            null,
            'HELP',
            [],
            $this->createStub(MemoServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            new MemoServCommandRegistry([]),
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider1 = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider2 = new class('chanserv', 'ChanServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider3 = new class('memoserv', 'MemoServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider4 = new class('operserv', 'OperServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };

        return new ServiceNicknameRegistry([$provider1, $provider2, $provider3, $provider4]);
    }

    private function createCommandStub(string $name, bool $operOnly = false): MemoServCommandInterface
    {
        return new class($name, $operOnly) implements MemoServCommandInterface {
            public function __construct(
                private readonly string $name,
                private readonly bool $operOnly,
            ) {}

            public function getName(): string
            {
                return $this->name;
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition($this->getSyntaxKey(), array_fill(0, $this->getMinArgs(), new Argument('argument')));
            }

            public function getMinArgs(): int
            {
                return 0;
            }

            public function getSyntaxKey(): string
            {
                return '';
            }

            public function getHelpKey(): string
            {
                return '';
            }

            public function getExampleKey(): string
            {
                return '';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return '';
            }

            public function getSubCommandHelp(): array
            {
                return [];
            }

            public function isOperOnly(): bool
            {
                return $this->operOnly;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function execute(MemoServContext $context): null
            {
                return null;
            }
        };
    }
}
