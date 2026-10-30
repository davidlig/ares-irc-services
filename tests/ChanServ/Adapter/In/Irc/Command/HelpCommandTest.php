<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\CregCommand;
use App\ChanServ\Adapter\In\Irc\Command\HelpCommand;
use App\ChanServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChanServOperatorAccess;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\UseCase\ListPendingRegistrations\ListPendingRegistrationsHandler;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistrationHandlerInterface;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\Tests\ChanServ\Adapter\In\Irc\ChanServCommandDefinitionTestTrait;
use ArrayObject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_slice;
use function dirname;
use function explode;
use function implode;
use function json_encode;
use function str_repeat;

#[CoversClass(HelpCommand::class)]
final class HelpCommandTest extends TestCase
{
    #[Test]
    public function spanishHelpUsesStructuredTemplatesInRealCatalog(): void
    {
        $translator = new Translator('es');
        $translator->addLoader('irc', new IrcYamlTranslationLoader());
        $translator->addResource('irc', dirname(__DIR__, 6) . '/translations/chanserv.es.yaml', 'es', 'chanserv');
        /** @var ArrayObject<int, string> $messages */
        $messages = new ArrayObject();
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('Canales');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $command = $this->createCommand();
        $context = $this->createContext(['HELP'], $notifier, $translator, new ChanServCommandRegistry([$command]), 'es');

        $command->execute($context);

        self::assertCount(1, $messages);
        $detail = $messages[0];
        self::assertIsString($detail);
        self::assertStringContainsString("Sintaxis: \x02\x0303HELP [comando] [opción]\x03\x0F", $detail);
        self::assertStringContainsString("  /msg Canales \x02\x0303HELP SET EMAIL\x03\x0F", $detail);
        self::assertStringNotContainsString('\\x03', $detail);

        $messages->exchangeArray([]);
        $general = $this->createCommand(30);
        $general->execute($this->createContext([], $notifier, $translator, new ChanServCommandRegistry([$general]), 'es'));
        $generalMessage = $messages[0];
        self::assertStringContainsString(" ■ \x02Comandos disponibles:\x03\x0F", $generalMessage);
        self::assertStringContainsString("Usa /msg Canales \x02\x0303HELP <comando>\x03\x0F para más información.", $generalMessage);
        self::assertStringContainsString("\x1F\x030430 días\x03\x0F", $generalMessage);
    }

    #[Test]
    public function englishHelpUsesStructuredComponentsWithRealCatalog(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('irc', new IrcYamlTranslationLoader());
        $translator->addResource('irc', dirname(__DIR__, 6) . '/translations/chanserv.en.yaml', 'en', 'chanserv');
        /** @var ArrayObject<int, string> $messages */
        $messages = new ArrayObject();
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('Channels');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use ($messages): void { $messages[] = $message; });
        $command = $this->createCommand(30);
        $command->execute($this->createContext([], $notifier, $translator, new ChanServCommandRegistry([$command]), 'en'));
        self::assertIsString($messages[0]);
        self::assertStringContainsString("\x1F\x030430 days\x03\x0F", $messages[0]);
        self::assertStringContainsString("Use /msg Channels \x02\x0303HELP <command>\x03\x0F for more information.", $messages[0]);
        $messages->exchangeArray([]);
        $command->execute($this->createContext(['HELP'], $notifier, $translator, new ChanServCommandRegistry([$command]), 'en'));
        self::assertStringContainsString("  /msg Channels \x02\x0303HELP SET EMAIL\x03\x0F", $messages[0]);
    }

    #[Test]
    public function helpCommandHasNoHelpParameters(): void
    {
        self::assertSame([], $this->createCommand()->getHelpParams());
    }

    #[Test]
    public function cregSubcommandHelpRoutesThroughPermissionFilteredHelp(): void
    {
        $messages = [];
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with('UID1', self::anything(), 'NOTICE')
            ->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $key, array $parameters = []): string {
            if ('help.syntax_label' === $key) {
                $syntax = $parameters['%syntax%'] ?? '';

                return 'Syntax: %syntax%';
            }
            if ('creg.accept.example' === $key) {
                return '/msg ChanServ CREG ACCEPT #channel';
            }
            if ('help.examples_header' === $key) {
                return 'Examples';
            }

            return $key;
        });
        $accountPort = $this->createStub(ChanUserAccountPort::class);
        $pending = new ListPendingRegistrationsHandler(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $accountPort,
        );
        $creg = new CregCommand($pending, $this->createStub(ReviewRegistrationHandlerInterface::class));
        $registry = new ChanServCommandRegistry([$creg]);
        $access = $this->createStub(ChanServOperatorAccess::class);
        $access->method('hasPermission')->willReturnCallback(static fn (string $nick, int $accountId, bool $identified, bool $oper, string $permission): bool => ChanServPermission::REGISTRATION_REVIEW === $permission);
        $context = new ChanServContext(
            new SenderView('UID1', 'Oper', 'i', 'h', 'c', 'ip', isIdentified: true, isOper: true),
            new ChanAccountView(9, 'Oper', 'en'),
            'HELP',
            ['CREG', 'ACCEPT'],
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

        $this->createCommand(operatorAccess: $access)->execute($context);

        self::assertCount(1, $messages);
        self::assertStringContainsString('creg.accept.help', $messages[0]);
        self::assertStringContainsString('creg.accept.syntax', $messages[0]);
        self::assertStringContainsString('/msg ChanServ CREG ACCEPT #channel', $messages[0]);
    }

    #[Test]
    public function emptyArgsShowsGeneralHelpAndFooter(): void
    {
        $messages = [];
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages = explode("\n", $m);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new class implements ChanServCommandInterface {
            use ChanServCommandDefinitionTestTrait;

            public function getName(): string
            {
                return 'HELP';
            }

            public function getAliases(): array
            {
                return ['?'];
            }

            public function getMinArgs(): int
            {
                return 0;
            }

            public function getSyntaxKey(): string
            {
                return 'help.syntax';
            }

            public function getHelpKey(): string
            {
                return 'help.help';
            }

            public function getExampleKey(): string
            {
                return 'help.example';
            }

            public function getOrder(): int
            {
                return 99;
            }

            public function getShortDescKey(): string
            {
                return 'help.short';
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
        $registry = new ChanServCommandRegistry([$handler]);

        $cmd = $this->createCommand();
        $cmd->execute($this->createContext([], $notifier, $translator, $registry));

        self::assertContains("\x0314" . str_repeat('─', 29) . "\x03\x0F", $messages);
    }

    #[Test]
    public function hidesPermissionRestrictedCommandHelpWithoutPermission(): void
    {
        $messages = [];
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages = explode("\n", $m);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new class implements ChanServCommandInterface {
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
                return 'register.help';
            }

            public function getExampleKey(): string
            {
                return 'register.example';
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
                return 'chanserv.forbid';
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
        $registry = new ChanServCommandRegistry([$handler]);

        $cmd = $this->createCommand();
        $cmd->execute($this->createContext(['REGISTER'], $notifier, $translator, $registry));

        self::assertContains("\x0304✗\x03\x0F presentation.unknown_command", $messages);
    }

    #[Test]
    public function unknownCommandShowsUnknownCommandReply(): void
    {
        $messages = [];
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages = explode("\n", $message);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $this->createCommand()->execute($this->createContext(
            ['MISSING'],
            $notifier,
            $translator,
            new ChanServCommandRegistry([]),
        ));

        self::assertSame(["\x0304✗\x03\x0F presentation.unknown_command"], $messages);
    }

    #[Test]
    public function oneArgShowsCommandHelpForKnownCommand(): void
    {
        $messages = [];
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages = explode("\n", $m);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $registerHandler = new class implements ChanServCommandInterface {
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
                return 'register.syntax';
            }

            public function getHelpKey(): string
            {
                return 'register.help';
            }

            public function getExampleKey(): string
            {
                return 'register.example';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'register.short';
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
        $registry = new ChanServCommandRegistry([$registerHandler]);

        $cmd = $this->createCommand();
        $cmd->execute($this->createContext(['REGISTER'], $notifier, $translator, $registry));

        self::assertContains('register.help', $messages);
        self::assertContains("\x0314" . str_repeat('─', 29) . "\x03\x0F", $messages);
    }

    #[Test]
    public function generalHelpWithInactivityExpirySendsIntroExpirationAndFooter(): void
    {
        $messages = [];
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages = explode("\n", $m);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $handler = new class implements ChanServCommandInterface {
            use ChanServCommandDefinitionTestTrait;

            public function getName(): string
            {
                return 'HELP';
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
                return 'help.syntax';
            }

            public function getHelpKey(): string
            {
                return 'help.help';
            }

            public function getExampleKey(): string
            {
                return 'help.example';
            }

            public function getOrder(): int
            {
                return 99;
            }

            public function getShortDescKey(): string
            {
                return 'help.short';
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
        $registry = new ChanServCommandRegistry([$handler]);

        $cmd = $this->createCommand(30);
        $cmd->execute($this->createContext([], $notifier, $translator, $registry));

        $all = implode(' ', $messages);
        self::assertStringContainsString('help.intro_expiration', $all);
        self::assertStringContainsString("\x0314" . str_repeat('─', 29) . "\x03\x0F", $all);
        $tail = array_slice($messages, -4);
        self::assertStringContainsString('help.general_footer', $tail[0]);
        self::assertSame(' ', $tail[1]);
        self::assertStringContainsString('help.intro_expiration', $tail[2]);
        self::assertSame("\x0314" . str_repeat('─', 29) . "\x03\x0F", $tail[3]);
    }

    #[Test]
    public function twoArgsWithValidSubCommandShowsSubCommandHelp(): void
    {
        $messages = [];
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages = explode("\n", $m);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $setHandler = new class implements ChanServCommandInterface {
            use ChanServCommandDefinitionTestTrait;

            public function getName(): string
            {
                return 'SET';
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
                return 'set.syntax';
            }

            public function getHelpKey(): string
            {
                return 'set.help';
            }

            public function getExampleKey(): string
            {
                return 'set.example';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'set.short';
            }

            public function getSubCommandHelp(): array
            {
                return [
                    [
                        'name' => 'FOUNDER',
                        'desc_key' => 'set.founder.desc',
                        'help_key' => 'set.founder.help', 'example_key' => 'set.founder.example',
                        'syntax_key' => 'set.founder.syntax',
                    ],
                ];
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
        $registry = new ChanServCommandRegistry([$setHandler]);

        $cmd = $this->createCommand();
        $cmd->execute($this->createContext(['SET', 'FOUNDER'], $notifier, $translator, $registry));

        self::assertContains('set.founder.help', $messages);
        self::assertContains("\x0314" . str_repeat('─', 29) . "\x03\x0F", $messages);
    }

    #[Test]
    public function twoArgsWithUnknownSubCommandShowsExplicitUnknownOption(): void
    {
        $messages = [];
        $translationCalls = [];
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages = explode("\n", $m);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $parameters) use (&$translationCalls): string {
            $translationCalls[] = ['id' => $id, 'parameters' => $parameters];

            return 'presentation.unknown_help_option' === $id ? 'Unknown %option% for %command%. /msg %bot% %help% %command%' : $id;
        });

        $setHandler = new class implements ChanServCommandInterface {
            use ChanServCommandDefinitionTestTrait;

            public function getName(): string
            {
                return 'SET';
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
                return 'set.syntax';
            }

            public function getHelpKey(): string
            {
                return 'set.help';
            }

            public function getExampleKey(): string
            {
                return 'set.example';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'set.short';
            }

            public function getSubCommandHelp(): array
            {
                return [
                    [
                        'name' => 'FOUNDER',
                        'desc_key' => 'set.founder.desc',
                        'help_key' => 'set.founder.help', 'example_key' => 'set.founder.example',
                        'syntax_key' => 'set.founder.syntax',
                    ],
                ];
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
        $registry = new ChanServCommandRegistry([$setHandler]);

        $cmd = $this->createCommand();
        $cmd->execute($this->createContext(['SET', 'ascsas'], $notifier, $translator, $registry));

        self::assertSame(["\x0304✗\x03\x0F Unknown \x02\x0303ASCSAS\x03\x0F for \x02\x0303SET\x03\x0F. /msg ChanServ \x02\x0303HELP\x03\x0F \x02\x0303SET\x03\x0F"], $messages);
        self::assertSame('presentation.unknown_help_option', $translationCalls[0]['id']);
        self::assertSame([], $translationCalls[0]['parameters']);
    }

    #[Test]
    public function twoArgsWithSubCommandCaseInsensitiveShowsSubCommandHelp(): void
    {
        $messages = [];
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages = explode("\n", $m);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $setHandler = new class implements ChanServCommandInterface {
            use ChanServCommandDefinitionTestTrait;

            public function getName(): string
            {
                return 'SET';
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
                return 'set.syntax';
            }

            public function getHelpKey(): string
            {
                return 'set.help';
            }

            public function getExampleKey(): string
            {
                return 'set.example';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'set.short';
            }

            public function getSubCommandHelp(): array
            {
                return [
                    [
                        'name' => 'FOUNDER',
                        'desc_key' => 'set.founder.desc',
                        'help_key' => 'set.founder.help', 'example_key' => 'set.founder.example',
                        'syntax_key' => 'set.founder.syntax',
                    ],
                ];
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
        $registry = new ChanServCommandRegistry([$setHandler]);

        $cmd = $this->createCommand();
        $cmd->execute($this->createContext(['SET', 'founder'], $notifier, $translator, $registry));

        self::assertContains('set.founder.help', $messages);
    }

    #[Test]
    public function helpWithAliasResolvesToCommandHelp(): void
    {
        $messages = [];
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages = explode("\n", $m);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $registerHandler = new class implements ChanServCommandInterface {
            use ChanServCommandDefinitionTestTrait;

            public function getName(): string
            {
                return 'REGISTER';
            }

            public function getAliases(): array
            {
                return ['REG', 'R'];
            }

            public function getMinArgs(): int
            {
                return 0;
            }

            public function getSyntaxKey(): string
            {
                return 'register.syntax';
            }

            public function getHelpKey(): string
            {
                return 'register.help';
            }

            public function getExampleKey(): string
            {
                return 'register.example';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'register.short';
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
        $registry = new ChanServCommandRegistry([$registerHandler]);

        $cmd = $this->createCommand();
        $cmd->execute($this->createContext(['REG'], $notifier, $translator, $registry));

        self::assertContains('register.help', $messages);
    }

    #[Test]
    public function helpWithMultipleSubcommandsShowsAllSubCommands(): void
    {
        $messages = [];
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages = explode("\n", $m);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $accessHandler = new class implements ChanServCommandInterface {
            use ChanServCommandDefinitionTestTrait;

            public function getName(): string
            {
                return 'ACCESS';
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
                return 'access.syntax';
            }

            public function getHelpKey(): string
            {
                return 'access.help';
            }

            public function getExampleKey(): string
            {
                return 'access.example';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'access.short';
            }

            public function getSubCommandHelp(): array
            {
                return [
                    [
                        'name' => 'ADD',
                        'desc_key' => 'access.add.desc',
                        'help_key' => 'access.add.help', 'example_key' => 'access.add.example',
                        'syntax_key' => 'access.add.syntax',
                    ],
                    [
                        'name' => 'DEL',
                        'desc_key' => 'access.del.desc',
                        'help_key' => 'access.del.help', 'example_key' => 'access.del.example',
                        'syntax_key' => 'access.del.syntax',
                    ],
                    [
                        'name' => 'LIST',
                        'desc_key' => 'access.list.desc',
                        'help_key' => 'access.list.help', 'example_key' => 'access.list.example',
                        'syntax_key' => 'access.list.syntax',
                    ],
                ];
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
        $registry = new ChanServCommandRegistry([$accessHandler]);

        $cmd = $this->createCommand();
        $cmd->execute($this->createContext(['ACCESS'], $notifier, $translator, $registry));

        self::assertContains('access.help', $messages);
        self::assertContains(" ■ \x02help.options_header\x03\x0F", $messages);
        self::assertStringContainsString('help.subcommand_line', implode(' ', $messages));
    }

    #[Test]
    public function helpWithUnknownSubcommandShowsExplicitUnknownOption(): void
    {
        $messages = [];
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages = explode("\n", $m);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $akickHandler = new class implements ChanServCommandInterface {
            use ChanServCommandDefinitionTestTrait;

            public function getName(): string
            {
                return 'AKICK';
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
                return 'akick.syntax';
            }

            public function getHelpKey(): string
            {
                return 'akick.help';
            }

            public function getExampleKey(): string
            {
                return 'akick.example';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'akick.short';
            }

            public function getSubCommandHelp(): array
            {
                return [
                    [
                        'name' => 'ADD',
                        'desc_key' => 'akick.add.desc',
                        'help_key' => 'akick.add.help', 'example_key' => 'akick.add.example',
                        'syntax_key' => 'akick.add.syntax',
                    ],
                ];
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
        $registry = new ChanServCommandRegistry([$akickHandler]);

        $cmd = $this->createCommand();
        $cmd->execute($this->createContext(['AKICK', 'INVALID'], $notifier, $translator, $registry));

        self::assertSame(["\x0304✗\x03\x0F presentation.unknown_help_option"], $messages);
    }

    #[Test]
    public function getNameReturnsHelp(): void
    {
        $cmd = $this->createCommand();
        self::assertSame('HELP', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsQuestionMark(): void
    {
        $cmd = $this->createCommand();
        self::assertSame(['?'], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsZero(): void
    {
        $cmd = $this->createCommand();
        self::assertSame(0, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsHelpSyntax(): void
    {
        $cmd = $this->createCommand();
        self::assertSame('help.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsHelpHelp(): void
    {
        $cmd = $this->createCommand();
        self::assertSame('help.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getExampleKeyReturnsHelpExample(): void
    {
        $cmd = $this->createCommand();
        self::assertSame('help.example', $cmd->getExampleKey());
    }

    #[Test]
    public function getOrderReturns99(): void
    {
        $cmd = $this->createCommand();
        self::assertSame(99, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsHelpShort(): void
    {
        $cmd = $this->createCommand();
        self::assertSame('help.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = $this->createCommand();
        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = $this->createCommand();
        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsNull(): void
    {
        $cmd = $this->createCommand();
        self::assertNull($cmd->getRequiredPermission());
    }

    #[Test]
    public function allowsSuspendedChannelReturnsTrue(): void
    {
        $cmd = $this->createCommand();

        self::assertTrue($cmd->allowsSuspendedChannel());
    }

    #[Test]
    public function allowsForbiddenChannelReturnsFalse(): void
    {
        $cmd = $this->createCommand();

        self::assertFalse($cmd->allowsForbiddenChannel());
    }

    /** @param list<string> $args */
    #[Test]
    #[DataProvider('renderFailureScenarios')]
    public function renderingFailureDoesNotSendPartialHelp(array $args): void
    {
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $key): string {
            if ('help.header' === $key) {
                throw new RuntimeException('Cannot render header');
            }

            return $key;
        });
        $handler = $this->createStub(ChanServCommandInterface::class);
        $handler->method('getName')->willReturn($args[0] ?? 'INFO');
        $handler->method('getHelpKey')->willReturn('command.help');
        $handler->method('getSyntaxKey')->willReturn('command.syntax');
        $handler->method('getSubCommandHelp')->willReturn([
            ['name' => 'FOUNDER', 'desc_key' => 'set.founder.short', 'help_key' => 'set.founder.help', 'example_key' => 'set.founder.example', 'syntax_key' => 'set.founder.syntax'],
        ]);
        $registry = new ChanServCommandRegistry([$handler]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot render header');

        $this->createCommand()->execute($this->createContext($args, $notifier, $translator, $registry));
    }

    /** @return array<string, array{list<string>}> */
    public static function renderFailureScenarios(): array
    {
        return [
            'general' => [[]],
            'command' => [['INFO']],
            'subcommand' => [['SET', 'FOUNDER']],
        ];
    }

    #[Test]
    public function completeHelpPropagatesSynchronousTransportFailure(): void
    {
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willThrowException(new RuntimeException('Connection write failed'));
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection write failed');

        $this->createCommand()->execute($this->createContext([], $notifier, $translator, new ChanServCommandRegistry([])));
    }

    /**
     * @param string[] $args
     */
    private function createContext(
        array $args,
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
        ChanServCommandRegistry $registry,
        string $language = 'en',
    ): ChanServContext {
        return new ChanServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            null,
            'HELP',
            $args,
            $notifier,
            $translator,
            $language,
            'UTC',
            'NOTICE',
            $registry,
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
    }

    private function createCommand(int $inactivityExpiryDays = 0, ?ChanServOperatorAccess $operatorAccess = null): HelpCommand
    {
        return new HelpCommand(
            new UnifiedHelpFormatter(),
            $operatorAccess ?? $this->createStub(ChanServOperatorAccess::class),
            $inactivityExpiryDays,
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
}
