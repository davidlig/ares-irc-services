<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\ListCommand;
use App\ChanServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use App\ChanServ\Adapter\In\Irc\HelpFormatterContextAdapter;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChanServOperatorAccess;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\UseCase\List\ListRegisteredChannelsHandlerInterface;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\Shared\Adapter\In\Irc\Presentation\Appearance;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_key_last;
use function dirname;
use function iterator_to_array;
use function str_repeat;

#[CoversClass(HelpFormatterContextAdapter::class)]
#[CoversClass(UnifiedHelpFormatter::class)]
#[CoversClass(ChanServContext::class)]
final class HelpFormatterContextAdapterTest extends TestCase
{
    #[Test]
    public function replyDelegatesToContext(): void
    {
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $context = $this->createContext($notifier, $translator, new ChanServCommandRegistry([]));
        $adapter = $this->createAdapter($context);

        $adapter->reply('test.key', ['%param%' => 'value']);

        self::assertSame(['test.key'], $messages);
    }

    #[Test]
    public function replyRawDelegatesToContext(): void
    {
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $context = $this->createContext($notifier, $translator, new ChanServCommandRegistry([]));
        $adapter = $this->createAdapter($context);

        $adapter->replyRaw('Raw message');

        self::assertSame(['Raw message'], $messages);
    }

    #[Test]
    public function transDelegatesToContext(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $context = $this->createContext($this->createStub(ChanServNotifierInterface::class), $translator, new ChanServCommandRegistry([]));
        $adapter = $this->createAdapter($context);

        self::assertSame('help.key', $adapter->trans('help.key'));
    }

    #[Test]
    public function spanishHelpUsesComponentsAndTheConfiguredBotPrefix(): void
    {
        $translator = new Translator('es');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', [
            'help.header' => '%title% %separator%',
            'help.group_header' => '%group%',
            'help.command_line' => '%command%%description%',
        ], 'es', 'chanserv');
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('Canales');
        $context = $this->createContext($notifier, $translator, new ChanServCommandRegistry([]), language: 'es');
        $adapter = $this->createAdapter($context);

        self::assertSame("  ◆ \x0310Registro\x03\x0F", $adapter->trans('help.group_header', ['group' => 'Registro']));
        self::assertSame("    \x0310›\x03\x0F \x02\x0303REGISTER    \x03\x0FDescripción", $adapter->trans('help.command_line', ['command' => 'REGISTER    ', 'description' => 'Descripción']));
        self::assertSame("  /msg Canales \x02\x0303HELP SET\x03\x0F", $adapter->renderExample('%command_prefix%HELP SET'));

        $alternate = new IrcRenderer(new FixedStyleProvider(new PresentationStyle([
            Component::Group->value => new Appearance(prefix: '> '),
            Component::Command->value => new Appearance('04'),
            Component::Example->value => new Appearance(prefix: 'EX '),
        ])));
        $alternateContext = $this->createContext($notifier, $translator, new ChanServCommandRegistry([]), language: 'es', renderer: $alternate);
        $alternateAdapter = $this->createAdapter($alternateContext);
        self::assertSame('> Registro', $alternateAdapter->trans('help.group_header', ['group' => 'Registro']));
        self::assertSame('Titulo ----', $alternateAdapter->trans('help.header', ['title' => 'Titulo', 'separator' => '────']));
        self::assertSame("EX /msg Canales \x0304HELP SET\x03\x0F", $alternateAdapter->renderExample('%command_prefix%HELP SET'));
    }

    #[Test]
    public function englishCatalogUsesAlternateStyleWithoutChangingLocalizedText(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('irc', new IrcYamlTranslationLoader());
        $translator->addResource('irc', dirname(__DIR__, 5) . '/translations/chanserv.en.yaml', 'en', 'chanserv');
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('Channels');
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle([
            Component::Group->value => new Appearance(prefix: '> '),
            Component::Command->value => new Appearance('04'),
            Component::Example->value => new Appearance(prefix: 'EX '),
        ])));
        $adapter = $this->createAdapter($this->createContext($notifier, $translator, new ChanServCommandRegistry([]), language: 'en', renderer: $renderer));
        self::assertSame('> Registration and information', $adapter->trans('help.group_header', ['group' => $adapter->trans('help.group.registration_info')]));
        self::assertSame("Syntax: \x0304HELP [command] [option]\x03\x0F", $adapter->trans('help.syntax_label', ['syntax' => $adapter->trans('help.syntax')]));
        self::assertSame("EX /msg Channels \x0304HELP SET EMAIL\x03\x0F", $adapter->renderExample($adapter->trans('help.examples.1')));
        self::assertSame("Use /msg Channels \x0304HELP <command>\x03\x0F for more information.", $adapter->trans('help.general_footer', ['bot' => 'Channels']));
    }

    #[Test]
    public function rejectsNonStringSyntaxWithoutSending(): void
    {
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['help.syntax_label' => 'Syntax: %syntax%'], 'en', 'chanserv');
        $adapter = $this->createAdapter($this->createContext($notifier, $translator, new ChanServCommandRegistry([])));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('HELP presentation parameter must be a string: syntax');
        $adapter->trans('help.syntax_label', ['syntax' => ['HELP']]);
    }

    #[Test]
    public function formatsRealCommandHelpWithNavigationAndExamples(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('irc', new IrcYamlTranslationLoader());
        $translator->addResource('irc', dirname(__DIR__, 5) . '/translations/chanserv.en.yaml', 'en', 'chanserv');
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('Channels');
        $adapter = $this->createAdapter($this->createContext($notifier, $translator, new ChanServCommandRegistry([])));
        $command = $this->createStub(ChanServCommandInterface::class);
        $command->method('getName')->willReturn('SET');
        $command->method('getHelpKey')->willReturn('set.help');
        $command->method('getSyntaxKey')->willReturn('set.syntax');
        $command->method('getExampleKey')->willReturn('set.example');
        $command->method('getSubCommandHelp')->willReturn([[
            'name' => 'EMAIL', 'desc_key' => 'set.email.short', 'help_key' => 'set.email.help',
            'syntax_key' => 'set.email.syntax', 'example_key' => 'set.email.example',
        ]]);
        $lines = new UnifiedHelpFormatter()->renderCommandHelp($adapter, $command);
        self::assertContains(" ■ \x02Options:\x03\x0F", $lines);
        self::assertContains("\x0310ℹ\x03\x0F Use /msg Channels \x02\x0303HELP SET <option>\x03\x0F for more information.", $lines);
        self::assertContains("  /msg Channels \x02\x0303SET #channel DESC A community channel\x03\x0F", $lines);
        self::assertSame("\x0314" . str_repeat('─', 29) . "\x03\x0F", $lines[array_key_last($lines)]);
        self::assertSame('en', $adapter->getLanguage());
        self::assertSame('/msg OtherBot HELP', $adapter->renderExample('/msg OtherBot HELP'));
    }

    #[Test]
    public function getCommandsForGeneralHelpReturnsRegistryAll(): void
    {
        $cmd = new class implements ChanServCommandInterface {
            use ChanServCommandDefinitionTestTrait;

            public function getName(): string
            {
                return 'REGISTER';
            }

            public function getAliases(): array
            {
                return [];
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
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $c): void {}
        };
        $registry = new ChanServCommandRegistry([$cmd]);
        $context = $this->createContext(
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            $registry,
        );
        $adapter = $this->createAdapter($context);

        $commands = iterator_to_array($adapter->getCommandsForGeneralHelp());

        self::assertCount(1, $commands);
        self::assertSame('REGISTER', $commands[0]->getName());
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpReturnsFalseForOperOnly(): void
    {
        $cmd = new class implements ChanServCommandInterface {
            use ChanServCommandDefinitionTestTrait;

            public function getName(): string
            {
                return 'DROP';
            }

            public function getAliases(): array
            {
                return [];
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
                return true;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $c): void {}
        };
        $registry = new ChanServCommandRegistry([$cmd]);
        $context = $this->createContext(
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            $registry,
        );
        $adapter = $this->createAdapter($context);

        self::assertFalse($adapter->shouldShowCommandInGeneralHelp($cmd));
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpReturnsTrueForNormalCommand(): void
    {
        $cmd = new class implements ChanServCommandInterface {
            use ChanServCommandDefinitionTestTrait;

            public function getName(): string
            {
                return 'INFO';
            }

            public function getAliases(): array
            {
                return [];
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
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $c): void {}
        };
        $registry = new ChanServCommandRegistry([$cmd]);
        $context = $this->createContext(
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            $registry,
        );
        $adapter = $this->createAdapter($context);

        self::assertTrue($adapter->shouldShowCommandInGeneralHelp($cmd));
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpRespectsModeDependentCommandWhenSupportHasAdmin(): void
    {
        $cmd = new class implements ChanServCommandInterface {
            use ChanServCommandDefinitionTestTrait;

            public function getName(): string
            {
                return 'ADMIN';
            }

            public function getAliases(): array
            {
                return [];
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
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $c): void {}
        };
        $registry = new ChanServCommandRegistry([$cmd]);
        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('hasAdmin')->willReturn(true);
        $context = $this->createContext(
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            $registry,
            $modeSupport,
        );
        $adapter = $this->createAdapter($context);

        self::assertTrue($adapter->shouldShowCommandInGeneralHelp($cmd));
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpHidesModeDependentCommandWhenSupportLacksMode(): void
    {
        $cmd = new class implements ChanServCommandInterface {
            use ChanServCommandDefinitionTestTrait;

            public function getName(): string
            {
                return 'ADMIN';
            }

            public function getAliases(): array
            {
                return [];
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
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $c): void {}
        };
        $registry = new ChanServCommandRegistry([$cmd]);
        $context = $this->createContext(
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            $registry,
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
        );
        $adapter = $this->createAdapter($context);

        self::assertFalse($adapter->shouldShowCommandInGeneralHelp($cmd));
    }

    #[Test]
    public function getIrcopCommandsReturnsEmpty(): void
    {
        $context = $this->createContext(
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            new ChanServCommandRegistry([]),
        );
        $adapter = $this->createAdapter($context);

        self::assertSame([], iterator_to_array($adapter->getIrcopCommands()));
    }

    #[Test]
    public function hasIrcopAccessReturnsFalse(): void
    {
        $context = $this->createContext(
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            new ChanServCommandRegistry([]),
        );
        $adapter = $this->createAdapter($context);

        self::assertFalse($adapter->hasIrcopAccess());
    }

    #[Test]
    public function canViewPublicAndAuthorizedIrcopCommandsAndExposesHelpGroups(): void
    {
        $public = $this->createStub(ChanServCommandInterface::class);
        $public->method('getName')->willReturn('INFO');
        $restricted = $this->createStub(ChanServCommandInterface::class);
        $restricted->method('getName')->willReturn('DROP');
        $restricted->method('getRequiredPermission')->willReturn(ChanServPermission::DROP);
        $registry = new ChanServCommandRegistry([$restricted]);
        $context = $this->createContext(
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            $registry,
            sender: new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true),
            account: new ChanAccountView(1, 'OperUser', 'en'),
        );
        $allowedAccess = $this->createStub(ChanServOperatorAccess::class);
        $allowedAccess->method('hasPermission')->willReturn(true);
        $allowed = new HelpFormatterContextAdapter($context, $allowedAccess);
        $deniedAccess = $this->createStub(ChanServOperatorAccess::class);
        $deniedAccess->method('hasPermission')->willReturn(false);
        $denied = new HelpFormatterContextAdapter($context, $deniedAccess);

        self::assertTrue($allowed->canViewCommandInHelp($public));
        self::assertTrue($allowed->canViewCommandInHelp($restricted));
        self::assertFalse($denied->canViewCommandInHelp($restricted));
        self::assertSame([
            ['group_key' => 'help.group.registration_info', 'commands' => ['REGISTER', 'INFO', 'STATUS', 'SET'], 'admin' => false, 'subgroup' => false],
            ['group_key' => 'help.group.access_levels', 'commands' => ['ACCESS', 'DELACCESS', 'AKICK', 'LEVELS'], 'admin' => false, 'subgroup' => false],
            ['group_key' => 'help.group.ranks_entry', 'commands' => ['OP', 'DEOP', 'VOICE', 'DEVOICE', 'ADMIN', 'DEADMIN', 'HALFOP', 'DEHALFOP', 'INVITE', 'UNBAN'], 'admin' => false, 'subgroup' => false],
            ['group_key' => 'help.ircop_group.channel_operations', 'commands' => ['CLEARUSERS', 'CLEARACCESS', 'IRCOPONLY'], 'admin' => true, 'subgroup' => true],
            ['group_key' => 'help.ircop_group.channel_management', 'commands' => ['NOEXPIRE'], 'admin' => true, 'subgroup' => true],
            ['group_key' => 'help.ircop_group.deletion', 'commands' => ['DROP', 'RESTORE'], 'admin' => true, 'subgroup' => true],
            ['group_key' => 'help.ircop_group.restrictions', 'commands' => ['SUSPEND', 'UNSUSPEND', 'FORBID', 'UNFORBID'], 'admin' => true, 'subgroup' => true],
            ['group_key' => 'help.ircop_group.lookup', 'commands' => ['HISTORY', 'LIST'], 'admin' => true, 'subgroup' => true],
            ['group_key' => 'help.ircop_group.registration_approval', 'commands' => ['REGMODE', 'CREG'], 'admin' => true, 'subgroup' => true],
        ], $allowed->getHelpGroups());
    }

    #[Test]
    public function hasIrcopAccessIsFalseForAnIdentifiedNonOper(): void
    {
        $context = $this->createContext(
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            new ChanServCommandRegistry([]),
            sender: new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip', true, false),
            account: new ChanAccountView(1, 'User', 'en'),
        );
        $adapter = $this->createAdapter($context);

        self::assertFalse($adapter->hasIrcopAccess());
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpReturnsFalseForIrcopPermission(): void
    {
        $cmd = new class implements ChanServCommandInterface {
            use ChanServCommandDefinitionTestTrait;

            public function getName(): string
            {
                return 'DROP';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 1;
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
                return false;
            }

            public function getRequiredPermission(): string
            {
                return ChanServPermission::DROP;
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $c): void {}
        };

        $context = $this->createContext(
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            new ChanServCommandRegistry([$cmd]),
        );
        $adapter = $this->createAdapter($context);

        self::assertFalse($adapter->shouldShowCommandInGeneralHelp($cmd));
    }

    #[Test]
    public function getIrcopCommandsReturnsEmptyForNullSender(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message): void {});
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $context = new ChanServContext(
            null,
            null,
            'HELP',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
        $adapter = $this->createAdapter($context);

        self::assertSame([], iterator_to_array($adapter->getIrcopCommands()));
    }

    #[Test]
    public function getIrcopCommandsReturnsAllForRoot(): void
    {
        $dropCmd = new class implements ChanServCommandInterface {
            use ChanServCommandDefinitionTestTrait;

            public function getName(): string
            {
                return 'DROP';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 1;
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
                return false;
            }

            public function getRequiredPermission(): string
            {
                return ChanServPermission::DROP;
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $c): void {}
        };
        $registry = new ChanServCommandRegistry([$dropCmd]);

        $sender = new SenderView('UID1', 'RootAdmin', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o');
        $account = new ChanAccountView(1, 'User', 'en');

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message): void {});
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $context = new ChanServContext(
            $sender,
            $account,
            'HELP',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        $operatorAccess = $this->createMock(ChanServOperatorAccess::class);
        $operatorAccess->expects(self::once())
            ->method('hasPermission')
            ->with('rootadmin', 1, true, true, ChanServPermission::DROP)
            ->willReturn(true);
        $adapter = new HelpFormatterContextAdapter($context, $operatorAccess);

        $ircopCommands = iterator_to_array($adapter->getIrcopCommands());
        self::assertCount(1, $ircopCommands);
        self::assertSame('DROP', $ircopCommands[0]->getName());
    }

    #[Test]
    public function getIrcopCommandsReturnsEmptyForNonOper(): void
    {
        $sender = new SenderView('UID1', 'NormalUser', 'i', 'h', 'c', 'ip', true, false, 'SID1', 'h', 'o');
        $account = new ChanAccountView(1, 'User', 'en');

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message): void {});
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $context = new ChanServContext(
            $sender,
            $account,
            'HELP',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
        $adapter = $this->createAdapter($context);

        self::assertSame([], iterator_to_array($adapter->getIrcopCommands()));
    }

    #[Test]
    public function getIrcopCommandsShowsListOnlyWhenTheActorHasItsPermission(): void
    {
        $command = new ListCommand($this->createStub(ListRegisteredChannelsHandlerInterface::class));
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o');
        $account = new ChanAccountView(1, 'OperUser', 'en');
        $context = $this->createContext(
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            new ChanServCommandRegistry([$command]),
            sender: $sender,
            account: $account,
        );
        $operatorAccess = $this->createMock(ChanServOperatorAccess::class);
        $operatorAccess->expects(self::once())
            ->method('hasPermission')
            ->with('operuser', 1, true, true, ChanServPermission::LIST)
            ->willReturn(true);

        $commands = iterator_to_array(new HelpFormatterContextAdapter($context, $operatorAccess)->getIrcopCommands());

        self::assertCount(1, $commands);
        self::assertSame('LIST', $commands[0]->getName());
    }

    #[Test]
    public function getIrcopCommandsHidesListFromAnActorWithoutItsPermission(): void
    {
        $command = new ListCommand($this->createStub(ListRegisteredChannelsHandlerInterface::class));
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o');
        $account = new ChanAccountView(1, 'OperUser', 'en');
        $context = $this->createContext(
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            new ChanServCommandRegistry([$command]),
            sender: $sender,
            account: $account,
        );
        $operatorAccess = $this->createMock(ChanServOperatorAccess::class);
        $operatorAccess->expects(self::once())
            ->method('hasPermission')
            ->with('operuser', 1, true, true, ChanServPermission::LIST)
            ->willReturn(false);

        self::assertSame([], iterator_to_array(new HelpFormatterContextAdapter($context, $operatorAccess)->getIrcopCommands()));
    }

    #[Test]
    public function getIrcopCommandsShowsListForIdentifiedRootBypass(): void
    {
        $command = new ListCommand($this->createStub(ListRegisteredChannelsHandlerInterface::class));
        $sender = new SenderView('UID1', 'RootAdmin', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o');
        $account = new ChanAccountView(1, 'RootAdmin', 'en');
        $context = $this->createContext(
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            new ChanServCommandRegistry([$command]),
            sender: $sender,
            account: $account,
        );
        $operatorAccess = $this->createMock(ChanServOperatorAccess::class);
        $operatorAccess->expects(self::once())
            ->method('hasPermission')
            ->with('rootadmin', 1, true, true, ChanServPermission::LIST)
            ->willReturn(true);

        $commands = iterator_to_array(new HelpFormatterContextAdapter($context, $operatorAccess)->getIrcopCommands());

        self::assertCount(1, $commands);
        self::assertSame('LIST', $commands[0]->getName());
    }

    #[Test]
    public function hasIrcopAccessReturnsTrueForRoot(): void
    {
        $sender = new SenderView('UID1', 'RootAdmin', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o');
        $account = new ChanAccountView(1, 'User', 'en');

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message): void {});
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $context = new ChanServContext(
            $sender,
            $account,
            'HELP',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        $operatorAccess = $this->createMock(ChanServOperatorAccess::class);
        $operatorAccess->expects(self::once())
            ->method('hasAnyPermission')
            ->with('rootadmin', 1, true, true, ChanServPermission::allIrcop())
            ->willReturn(true);
        $adapter = new HelpFormatterContextAdapter($context, $operatorAccess);

        self::assertTrue($adapter->hasIrcopAccess());
    }

    #[Test]
    public function hasIrcopAccessReturnsFalseForOperWithoutIrcopRole(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o');
        $account = new ChanAccountView(1, 'User', 'en');

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message): void {});
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $context = new ChanServContext(
            $sender,
            $account,
            'HELP',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        $operatorAccess = $this->createStub(ChanServOperatorAccess::class);
        $operatorAccess->method('hasAnyPermission')->willReturn(false);
        $adapter = new HelpFormatterContextAdapter($context, $operatorAccess);

        self::assertFalse($adapter->hasIrcopAccess());
    }

    #[Test]
    public function hasIrcopAccessReturnsTrueForOperWithChanServPermission(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o');
        $account = new ChanAccountView(1, 'User', 'en');

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message): void {});
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $context = new ChanServContext(
            $sender,
            $account,
            'HELP',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        $operatorAccess = $this->createStub(ChanServOperatorAccess::class);
        $operatorAccess->method('hasAnyPermission')->willReturn(true);
        $adapter = new HelpFormatterContextAdapter($context, $operatorAccess);

        self::assertTrue($adapter->hasIrcopAccess());
    }

    #[Test]
    public function filterByPermissionReturnsOnlyCommandsOperHasPermissionFor(): void
    {
        $dropCmd = new class implements ChanServCommandInterface {
            use ChanServCommandDefinitionTestTrait;

            public function getName(): string
            {
                return 'DROP';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getMinArgs(): int
            {
                return 1;
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
                return false;
            }

            public function getRequiredPermission(): string
            {
                return ChanServPermission::DROP;
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $c): void {}
        };
        $infoCmd = new class implements ChanServCommandInterface {
            use ChanServCommandDefinitionTestTrait;

            public function getName(): string
            {
                return 'INFO';
            }

            public function getAliases(): array
            {
                return [];
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
                return false;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function allowsForbiddenChannel(): bool
            {
                return true;
            }

            public function usesLevelFounder(): bool
            {
                return false;
            }

            public function execute(ChanServContext $c): void {}
        };
        $registry = new ChanServCommandRegistry([$dropCmd, $infoCmd]);

        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o');
        $account = new ChanAccountView(1, 'User', 'en');

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message): void {});
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $context = new ChanServContext(
            $sender,
            $account,
            'HELP',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );

        $operatorAccess = $this->createStub(ChanServOperatorAccess::class);
        $operatorAccess->method('hasPermission')->willReturn(true);
        $adapter = new HelpFormatterContextAdapter($context, $operatorAccess);

        $ircopCommands = iterator_to_array($adapter->getIrcopCommands());
        self::assertCount(1, $ircopCommands);
        self::assertSame('DROP', $ircopCommands[0]->getName());
    }

    #[Test]
    public function exposesTheConfiguredBotNickname(): void
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('CustomChan');
        $context = $this->createContext($notifier, $this->createStub(TranslatorInterface::class), new ChanServCommandRegistry([]));
        $adapter = new HelpFormatterContextAdapter($context, $this->createStub(ChanServOperatorAccess::class));

        self::assertSame('CustomChan', $adapter->getBotName());
    }

    private function createContext(
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
        ChanServCommandRegistry $registry,
        ?ChannelModeSupportInterface $channelModeSupport = null,
        ?NetworkUserLookupPort $userLookup = null,
        ?SenderView $sender = null,
        ?ChanAccountView $account = null,
        string $language = 'en',
        ?IrcRenderer $renderer = null,
    ): ChanServContext {
        return new ChanServContext(
            $sender ?? new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            $account,
            'HELP',
            [],
            $notifier,
            $translator,
            $language,
            'UTC',
            'NOTICE',
            $registry,
            $this->createStub(ChannelLookupPort::class),
            $channelModeSupport ?? new NullChannelModeSupport(),
            $userLookup ?? $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            renderer: $renderer,
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

    private function createAdapter(ChanServContext $context): HelpFormatterContextAdapter
    {
        return new HelpFormatterContextAdapter($context, $this->createStub(ChanServOperatorAccess::class));
    }
}
