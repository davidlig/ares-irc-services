<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\HelpCommand;
use App\NickServ\Adapter\In\Irc\Command\RegisterCommand;
use App\NickServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use App\NickServ\Adapter\In\Irc\HelpFormatterContextAdapter;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\In\Irc\TimezoneHelpProvider;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Port\Out\NickServOperatorAccess;
use App\NickServ\Application\UseCase\Register\RegisterNickHandlerInterface;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Appearance;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stringable;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_search;
use function array_slice;
use function dirname;
use function explode;
use function fwrite;
use function getenv;
use function implode;
use function is_scalar;
use function str_contains;
use function str_repeat;
use function str_replace;
use function strtr;

#[CoversClass(HelpCommand::class)]
#[CoversClass(HelpFormatterContextAdapter::class)]
#[CoversClass(UnifiedHelpFormatter::class)]
final class HelpCommandTest extends TestCase
{
    private const array SERVICE_NICKNAME_PLACEHOLDERS = [
        '%bot%' => '',
        '%nickserv%' => 'NickServ',
        '%chanserv%' => 'ChanServ',
        '%memoserv%' => 'MemoServ',
        '%operserv%' => 'OperServ',
    ];

    #[Test]
    public function doesNothingWhenSenderNull(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');
        $translator = $this->createStub(TranslatorInterface::class);
        $registry = new NickServCommandRegistry([]);

        $cmd = $this->createHelpCommand(0);
        $cmd->execute($this->createContext(null, [], $notifier, $translator, $registry));
    }

    #[Test]
    public function unknownCommandRepliesHelpUnknown(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages = explode("\n", $m);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new class implements NickServCommandInterface {
            public function getName(): string
            {
                return 'REGISTER';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition('');
            }

            public function getMinArgs(): int
            {
                return $this->getArgumentDefinition()->minimumArgs();
            }

            public function getSyntaxKey(): string
            {
                return $this->getArgumentDefinition()->syntaxKey;
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

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $c): null
            {
                return null;
            }
        };
        $registry = new NickServCommandRegistry([$handler]);

        $cmd = $this->createHelpCommand(0);
        $cmd->execute($this->createContext($sender, ['UNKNOWNCMD'], $notifier, $translator, $registry));

        self::assertContains('unknown_command', $messages);
    }

    #[Test]
    public function emptyArgsShowsGeneralHelp(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages = explode("\n", $m);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new class implements NickServCommandInterface {
            public function getName(): string
            {
                return 'HELP';
            }

            public function getAliases(): array
            {
                return ['?'];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition('help.syntax');
            }

            public function getMinArgs(): int
            {
                return $this->getArgumentDefinition()->minimumArgs();
            }

            public function getSyntaxKey(): string
            {
                return $this->getArgumentDefinition()->syntaxKey;
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

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $c): null
            {
                return null;
            }
        };
        $registry = new NickServCommandRegistry([$handler]);

        $cmd = $this->createHelpCommand(0);
        $cmd->execute($this->createContext($sender, [], $notifier, $translator, $registry));

        self::assertContains('help.footer', $messages);
    }

    #[Test]
    public function emptyArgsShowsGeneralHelpWithInactivityDays(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages = explode("\n", $m);
        });
        $translations = [];
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = []) use (&$translations): string {
                $translations[] = ['id' => $id, 'parameters' => $parameters];

                $translation = match ($id) {
                    'help.intro_expiration_label' => 'NOTE',
                    'help.warning_marker' => "\x0304⚠\x03\x0F",
                    'help.intro_expiration_duration' => '%days% days',
                    'help.intro_expiration' => "%marker% %label%: Nicknames unused for more than \x1F\x0304%days% days\x03\x1F are automatically removed.",
                    default => $id,
                };

                return self::replaceTranslationParameters($translation, self::requireStringKeyedParameters($parameters));
            },
        );

        $handler = new class implements NickServCommandInterface {
            public function getName(): string
            {
                return 'HELP';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition('help.syntax');
            }

            public function getMinArgs(): int
            {
                return $this->getArgumentDefinition()->minimumArgs();
            }

            public function getSyntaxKey(): string
            {
                return $this->getArgumentDefinition()->syntaxKey;
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

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $c): null
            {
                return null;
            }
        };
        $registry = new NickServCommandRegistry([$handler]);

        $cmd = $this->createHelpCommand(30);
        $cmd->execute($this->createContext($sender, [], $notifier, $translator, $registry));

        self::assertContains(
            "\x0304⚠\x03\x0F NOTE: Nicknames unused for more than \x1F\x030430 days\x03\x1F are automatically removed.",
            $messages,
        );
        $tail = array_slice($messages, -4);
        self::assertSame('help.general_footer', $tail[0]);
        self::assertSame(' ', $tail[1]);
        self::assertStringContainsString('Nicknames unused for more than', $tail[2]);
        self::assertSame('help.footer', $tail[3]);
        self::assertContains([
            'id' => 'help.intro_expiration',
            'parameters' => [
                ...self::SERVICE_NICKNAME_PLACEHOLDERS,
                '%marker%' => "\x0304⚠\x03\x0F",
                '%label%' => 'NOTE',
                '%days%' => 30,
                '%duration%' => '30 days',
            ],
        ], $translations);
        $output = implode("\n", $messages);
        self::assertStringNotContainsString('help.intro_expiration_label', $output);
        self::assertStringNotContainsString('%label%', $output);
        self::assertStringNotContainsString('%days%', $output);
    }

    #[Test]
    public function permissionRestrictedCommandHelpHiddenWithoutPermission(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip', false, false, '', '');
        $messages = [];
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages = explode("\n", $m);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $restrictedHandler = new class implements NickServCommandInterface {
            public function getName(): string
            {
                return 'OPERCMD';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition('opercmd.syntax');
            }

            public function getMinArgs(): int
            {
                return $this->getArgumentDefinition()->minimumArgs();
            }

            public function getSyntaxKey(): string
            {
                return $this->getArgumentDefinition()->syntaxKey;
            }

            public function getHelpKey(): string
            {
                return 'opercmd.help';
            }

            public function getExampleKey(): string
            {
                return 'opercmd.example';
            }

            public function getOrder(): int
            {
                return 1;
            }

            public function getShortDescKey(): string
            {
                return 'opercmd.short';
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
                return 'nickserv.forbid';
            }

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $c): null
            {
                return null;
            }
        };
        $registry = new NickServCommandRegistry([$restrictedHandler]);

        $cmd = $this->createHelpCommand(0);
        $cmd->execute($this->createContext($sender, ['OPERCMD'], $notifier, $translator, $registry));

        self::assertContains('unknown_command', $messages);
    }

    #[Test]
    public function knownCommandShowsCommandHelp(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages = explode("\n", $m);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new class implements NickServCommandInterface {
            public function getName(): string
            {
                return 'REGISTER';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition('register.syntax', [new Argument('argument1'), new Argument('argument2')]);
            }

            public function getMinArgs(): int
            {
                return $this->getArgumentDefinition()->minimumArgs();
            }

            public function getSyntaxKey(): string
            {
                return $this->getArgumentDefinition()->syntaxKey;
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
                return 1;
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

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $c): null
            {
                return null;
            }
        };
        $registry = new NickServCommandRegistry([$handler]);

        $cmd = $this->createHelpCommand(0);
        $cmd->execute($this->createContext($sender, ['REGISTER'], $notifier, $translator, $registry));

        self::assertNotEmpty($messages);
    }

    #[Test]
    public function commandWithSubCommandShowsSubCommandHelp(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages = explode("\n", $m);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handlerWithSub = new class implements NickServCommandInterface {
            public function getName(): string
            {
                return 'SET';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition('set.syntax', [new Argument('option')]);
            }

            public function getMinArgs(): int
            {
                return $this->getArgumentDefinition()->minimumArgs();
            }

            public function getSyntaxKey(): string
            {
                return $this->getArgumentDefinition()->syntaxKey;
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
                return 10;
            }

            public function getShortDescKey(): string
            {
                return 'set.short';
            }

            public function getSubCommandHelp(): array
            {
                return [
                    [
                        'name' => 'PASSWORD',
                        'desc_key' => 'set.password.desc',
                        'help_key' => 'set.password.help',
                        'syntax_key' => 'set.password.syntax',
                        'example_key' => 'set.password.example',
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

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $c): null
            {
                return null;
            }
        };
        $registry = new NickServCommandRegistry([$handlerWithSub]);

        $cmd = $this->createHelpCommand(0);
        $cmd->execute($this->createContext($sender, ['SET', 'PASSWORD'], $notifier, $translator, $registry));

        self::assertNotEmpty($messages);
        self::assertSame(' ', $messages[1]);
    }

    #[Test]
    public function setTimezoneCommandWithRegionShowsTimezones(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages = explode("\n", $m);
        });
        $notifier->method('getNick')->willReturn('CustomNickServ');
        $translations = [];
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = []) use (&$translations): string {
                $translations[] = ['id' => $id, 'parameters' => $parameters];

                $translation = match ($id) {
                    'help.set_timezone.region_header' => 'Timezones for %region%:',
                    'help.examples_header' => 'Examples:',
                    'set.timezone.example' => '%command_prefix%SET TIMEZONE Europe/Madrid',
                    'set.timezone.examples.0' => '%command_prefix%SET TIMEZONE Europe/Paris',
                    default => $id,
                };

                return self::replaceTranslationParameters($translation, self::requireStringKeyedParameters($parameters));
            },
        );

        $handlerWithSub = new class implements NickServCommandInterface {
            public function getName(): string
            {
                return 'SET';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition('set.syntax', [new Argument('option')]);
            }

            public function getMinArgs(): int
            {
                return $this->getArgumentDefinition()->minimumArgs();
            }

            public function getSyntaxKey(): string
            {
                return $this->getArgumentDefinition()->syntaxKey;
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
                return 10;
            }

            public function getShortDescKey(): string
            {
                return 'set.short';
            }

            public function getSubCommandHelp(): array
            {
                return [
                    [
                        'name' => 'TIMEZONE',
                        'desc_key' => 'set.timezone.desc',
                        'help_key' => 'set.timezone.help',
                        'syntax_key' => 'set.timezone.syntax',
                        'example_key' => 'set.timezone.example',
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

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $c): null
            {
                return null;
            }
        };
        $registry = new NickServCommandRegistry([$handlerWithSub]);

        $cmd = $this->createHelpCommand(0);
        $cmd->execute($this->createContext($sender, ['SET', 'TIMEZONE', 'Europe'], $notifier, $translator, $registry));

        self::assertNotEmpty($messages);
        self::assertSame(' ', $messages[1]);
        self::assertContains('Timezones for Europe:', $messages);
        self::assertContains([
            'id' => 'help.set_timezone.region_header',
            'parameters' => [
                ...self::SERVICE_NICKNAME_PLACEHOLDERS,
                '%bot%' => 'CustomNickServ',
                '%region%' => 'Europe',
            ],
        ], $translations);
        $output = implode("\n", $messages);
        self::assertStringNotContainsString('help.set_timezone.region_header', $output);
        self::assertStringNotContainsString('%region%', $output);
        self::assertContains('Examples:', $messages);
        self::assertContains('  /msg CustomNickServ SET TIMEZONE Europe/Madrid', $messages);
        self::assertContains('  /msg CustomNickServ SET TIMEZONE Europe/Paris', $messages);
        self::assertStringNotContainsString('%command_prefix%', $output);
        $purposePosition = array_search('Timezones for Europe:', $messages, true);
        $syntaxPosition = array_search('help.syntax_label', $messages, true);
        $examplesHeaderPosition = array_search('Examples:', $messages, true);
        $examplePosition = array_search('  /msg CustomNickServ SET TIMEZONE Europe/Madrid', $messages, true);
        self::assertIsInt($purposePosition);
        self::assertIsInt($syntaxPosition);
        self::assertIsInt($examplesHeaderPosition);
        self::assertIsInt($examplePosition);
        self::assertSame(' ', $messages[$examplesHeaderPosition - 1]);
        self::assertLessThan($syntaxPosition, $purposePosition);
        self::assertLessThan($examplesHeaderPosition, $syntaxPosition);
        self::assertLessThan($examplePosition, $examplesHeaderPosition);
    }

    #[Test]
    public function setTimezoneCommandWithUnknownRegionShowsError(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages = explode("\n", $m);
        });
        $translations = [];
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = []) use (&$translations): string {
                $translations[] = ['id' => $id, 'parameters' => $parameters];

                $translation = match ($id) {
                    'help.set_timezone.region_syntax' => 'HELP SET TIMEZONE',
                    'help.error_marker' => 'translated:help.error_marker',
                    'help.set_timezone.region_unknown' => '%marker% Unknown region. Use %syntax% for the index.',
                    default => $id,
                };

                return self::replaceTranslationParameters($translation, self::requireStringKeyedParameters($parameters));
            },
        );

        $handlerWithSub = new class implements NickServCommandInterface {
            public function getName(): string
            {
                return 'SET';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition('set.syntax', [new Argument('option')]);
            }

            public function getMinArgs(): int
            {
                return $this->getArgumentDefinition()->minimumArgs();
            }

            public function getSyntaxKey(): string
            {
                return $this->getArgumentDefinition()->syntaxKey;
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
                return 10;
            }

            public function getShortDescKey(): string
            {
                return 'set.short';
            }

            public function getSubCommandHelp(): array
            {
                return [
                    [
                        'name' => 'TIMEZONE',
                        'desc_key' => 'set.timezone.desc',
                        'help_key' => 'set.timezone.help',
                        'syntax_key' => 'set.timezone.syntax',
                        'example_key' => 'set.timezone.example',
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

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $c): null
            {
                return null;
            }
        };
        $registry = new NickServCommandRegistry([$handlerWithSub]);

        $cmd = $this->createHelpCommand(0);
        $cmd->execute($this->createContext($sender, ['SET', 'TIMEZONE', 'UnknownRegion'], $notifier, $translator, $registry));

        self::assertSame(' ', $messages[1]);
        self::assertContains(
            'translated:help.error_marker Unknown region. Use HELP SET TIMEZONE for the index.',
            $messages,
        );
        self::assertContains([
            'id' => 'help.set_timezone.region_unknown',
            'parameters' => [
                ...self::SERVICE_NICKNAME_PLACEHOLDERS,
                '%marker%' => 'translated:help.error_marker',
                '%syntax%' => 'HELP SET TIMEZONE',
            ],
        ], $translations);
        $output = implode("\n", $messages);
        self::assertStringNotContainsString('help.set_timezone.region_syntax', $output);
        self::assertStringNotContainsString('%syntax%', $output);
    }

    #[Test]
    public function setTimezoneCommandWithoutRegionShowsIndex(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages = explode("\n", $m);
        });
        $translations = [];
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = []) use (&$translations): string {
                $translations[] = ['id' => $id, 'parameters' => $parameters];

                $translation = match ($id) {
                    'help.set_timezone.index_syntax' => 'HELP SET TIMEZONE <region>',
                    'help.set_timezone.index_label' => 'Regions (use %syntax% for list):',
                    'help.examples_header' => 'Examples:',
                    'set.timezone.example' => '/msg NickServ SET TIMEZONE Europe/Madrid',
                    default => $id,
                };

                return self::replaceTranslationParameters($translation, self::requireStringKeyedParameters($parameters));
            },
        );

        $handlerWithSub = new class implements NickServCommandInterface {
            public function getName(): string
            {
                return 'SET';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition('set.syntax', [new Argument('option')]);
            }

            public function getMinArgs(): int
            {
                return $this->getArgumentDefinition()->minimumArgs();
            }

            public function getSyntaxKey(): string
            {
                return $this->getArgumentDefinition()->syntaxKey;
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
                return 10;
            }

            public function getShortDescKey(): string
            {
                return 'set.short';
            }

            public function getSubCommandHelp(): array
            {
                return [
                    [
                        'name' => 'TIMEZONE',
                        'desc_key' => 'set.timezone.desc',
                        'help_key' => 'set.timezone.help',
                        'syntax_key' => 'set.timezone.syntax',
                        'example_key' => 'set.timezone.example',
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

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $c): null
            {
                return null;
            }
        };
        $registry = new NickServCommandRegistry([$handlerWithSub]);

        $cmd = $this->createHelpCommand(0);
        $cmd->execute($this->createContext($sender, ['SET', 'TIMEZONE'], $notifier, $translator, $registry));

        self::assertContains(
            'Regions (use HELP SET TIMEZONE <region> for list):',
            $messages,
        );
        self::assertContains([
            'id' => 'help.set_timezone.index_label',
            'parameters' => [
                ...self::SERVICE_NICKNAME_PLACEHOLDERS,
                '%syntax%' => 'HELP SET TIMEZONE <region>',
            ],
        ], $translations);
        $output = implode("\n", $messages);
        self::assertStringNotContainsString('help.set_timezone.index_syntax', $output);
        self::assertStringNotContainsString('%syntax%', $output);
        self::assertContains('Examples:', $messages);
        self::assertContains('/msg NickServ SET TIMEZONE Europe/Madrid', $messages);
        $syntaxPosition = array_search('help.syntax_label', $messages, true);
        $examplesHeaderPosition = array_search('Examples:', $messages, true);
        $examplePosition = array_search('/msg NickServ SET TIMEZONE Europe/Madrid', $messages, true);
        $indexPosition = array_search('Regions (use HELP SET TIMEZONE <region> for list):', $messages, true);
        self::assertIsInt($syntaxPosition);
        self::assertIsInt($examplesHeaderPosition);
        self::assertIsInt($examplePosition);
        self::assertIsInt($indexPosition);
        self::assertSame(' ', $messages[1]);
        self::assertLessThan($examplesHeaderPosition, $syntaxPosition);
        self::assertLessThan($examplePosition, $examplesHeaderPosition);
        self::assertLessThan($indexPosition, $examplePosition);
    }

    #[Test]
    public function commandWithUnknownSubCommandShowsInvalidOption(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $messages = [];
        $translationCalls = [];
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages = explode("\n", $m);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $parameters = []) use (&$translationCalls): string {
            $translationCalls[] = ['id' => $id, 'parameters' => $parameters];

            return $id;
        });

        $handlerWithSub = new class implements NickServCommandInterface {
            public function getName(): string
            {
                return 'SET';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getArgumentDefinition(): CommandArgumentDefinition
            {
                return new CommandArgumentDefinition('set.syntax', [new Argument('option')]);
            }

            public function getMinArgs(): int
            {
                return $this->getArgumentDefinition()->minimumArgs();
            }

            public function getSyntaxKey(): string
            {
                return $this->getArgumentDefinition()->syntaxKey;
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
                return 10;
            }

            public function getShortDescKey(): string
            {
                return 'set.short';
            }

            public function getSubCommandHelp(): array
            {
                return [
                    [
                        'name' => 'PASSWORD',
                        'desc_key' => 'set.password.desc',
                        'help_key' => 'set.password.help',
                        'syntax_key' => 'set.password.syntax',
                        'example_key' => 'set.password.example',
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

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $c): null
            {
                return null;
            }
        };
        $registry = new NickServCommandRegistry([$handlerWithSub]);

        $cmd = $this->createHelpCommand(0);
        $cmd->execute($this->createContext($sender, ['SET', 'ascsas'], $notifier, $translator, $registry));

        self::assertSame(['unknown_help_option'], $messages);
        self::assertSame('unknown_help_option', $translationCalls[0]['id']);
        self::assertSame('SET', $translationCalls[0]['parameters']['%command%']);
        self::assertSame('ASCSAS', $translationCalls[0]['parameters']['%option%']);
    }

    #[Test]
    public function getAliasesReturnsArray(): void
    {
        $cmd = $this->createHelpCommand(0);
        self::assertSame(['?'], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsZero(): void
    {
        $cmd = $this->createHelpCommand(0);
        self::assertSame(0, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsString(): void
    {
        $cmd = $this->createHelpCommand(0);
        self::assertSame('help.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsString(): void
    {
        $cmd = $this->createHelpCommand(0);
        self::assertSame('help.help', $cmd->getHelpKey());
        self::assertSame('help.example', $cmd->getExampleKey());
    }

    #[Test]
    public function getOrderReturnsInt(): void
    {
        $cmd = $this->createHelpCommand(0);
        self::assertSame(99, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsString(): void
    {
        $cmd = $this->createHelpCommand(0);
        self::assertSame('help.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = $this->createHelpCommand(0);
        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = $this->createHelpCommand(0);
        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsNull(): void
    {
        $cmd = $this->createHelpCommand(0);
        self::assertNull($cmd->getRequiredPermission());
    }

    #[Test]
    public function getNameReturnsHelp(): void
    {
        $cmd = $this->createHelpCommand(0);
        self::assertSame('HELP', $cmd->getName());
    }

    #[Test]
    public function getHelpParamsReturnsEmptyArray(): void
    {
        $cmd = $this->createHelpCommand(0);

        self::assertSame([], $cmd->getHelpParams());
    }

    /** @param list<string> $args */
    #[Test]
    #[DataProvider('renderFailureScenarios')]
    public function renderingFailureDoesNotSendPartialHelp(array $args): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $key): string {
            if ('help.footer' === $key) {
                throw new RuntimeException('Cannot render footer');
            }

            return $key;
        });
        $handler = $this->createStub(NickServCommandInterface::class);
        $handler->method('getName')->willReturn($args[0] ?? 'INFO');
        $handler->method('getHelpKey')->willReturn('command.help');
        $handler->method('getSyntaxKey')->willReturn('command.syntax');
        $handler->method('getExampleKey')->willReturn('command.example');
        $handler->method('getSubCommandHelp')->willReturn([
            ['name' => 'EMAIL', 'desc_key' => 'set.email.short', 'help_key' => 'set.email.help', 'syntax_key' => 'set.email.syntax', 'example_key' => 'set.email.example'],
            ['name' => 'TIMEZONE', 'desc_key' => 'set.timezone.short', 'help_key' => 'set.timezone.help', 'syntax_key' => 'set.timezone.syntax', 'example_key' => 'set.timezone.example'],
        ]);
        $registry = new NickServCommandRegistry([$handler]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot render footer');

        $this->createHelpCommand()->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $args, $notifier, $translator, $registry));
    }

    /** @return array<string, array{list<string>}> */
    public static function renderFailureScenarios(): array
    {
        return [
            'general' => [[]],
            'command' => [['INFO']],
            'subcommand' => [['SET', 'EMAIL']],
            'timezone index' => [['SET', 'TIMEZONE']],
            'timezone region' => [['SET', 'TIMEZONE', 'Europe']],
            'timezone alias' => [['SET', 'TIMEZONE', 'Europe/Madrid']],
            'timezone unknown' => [['SET', 'TIMEZONE', 'InvalidRegion']],
        ];
    }

    #[Test]
    public function completeHelpPropagatesSynchronousTransportFailure(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->willThrowException(new RuntimeException('Connection write failed'));
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection write failed');

        $this->createHelpCommand()->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), [], $notifier, $translator, new NickServCommandRegistry([])));
    }

    #[Test]
    public function unknownHelpCommandUsesStyledFragmentsAndConfiguredBot(): void
    {
        $translator = new Translator('es');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 6) . '/translations/common.es.yaml', 'es', 'common');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('CustomNick');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        $context = new NickServContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, 'HELP', ['UNKNOWN'], $notifier, $translator, 'es', 'UTC', 'NOTICE', new NickServCommandRegistry([]), new PendingVerificationRegistry(), new RecoveryTokenRegistry(), $this->createServiceNicks(), $renderer);
        $this->createHelpCommand()->execute($context);
        self::assertSame(["\x0304✗\x03\x0F Comando desconocido \x02\x0303UNKNOWN\x03\x0F. Usa /msg CustomNick \x02\x0303HELP\x03\x0F"], $messages);
    }

    #[Test]
    public function spanishGeneralHelpUsesStructuredComponents(): void
    {
        $translator = new Translator('es');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 6) . '/translations/nickserv.es.yaml', 'es', 'nickserv');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('CustomNick');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        $context = new NickServContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, 'HELP', [], $notifier, $translator, 'es', 'UTC', 'NOTICE', new NickServCommandRegistry([]), new PendingVerificationRegistry(), new RecoveryTokenRegistry(), $this->createServiceNicks(), $renderer);
        $this->createHelpCommand(30)->execute($context);
        self::assertStringContainsString("🤖 \x02\x0307CustomNick\x03\x0F \x0314" . str_repeat('─', 29), $messages[0]);
        self::assertStringContainsString(" ■ \x02Comandos disponibles:\x03\x0F", $messages[0]);
        self::assertStringContainsString("\x0310ℹ\x03\x0F Usa /msg CustomNick \x02\x0303HELP <comando>\x03\x0F para más información.", $messages[0]);
        self::assertStringContainsString("\x0304⚠\x03\x0F NOTA: Los nicks que no se utilicen durante más de \x1F\x030430 días\x03\x0F se eliminarán automáticamente.", $messages[0]);
        if ('1' === getenv('NICKSERV_HELP_PREVIEW')) {
            fwrite(STDERR, "\n--- Spanish NickServ general HELP ---\n" . $messages[0] . "\n");
        }
    }

    #[Test]
    public function spanishHelpRendersExamplesAndInlineEmphasisWithSelectedStyle(): void
    {
        $translator = new Translator('es');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 6) . '/translations/nickserv.es.yaml', 'es', 'nickserv');
        $translator->addResource('yaml', dirname(__DIR__, 6) . '/translations/common.es.yaml', 'es', 'common');
        $handler = new RegisterCommand($this->createStub(RegisterNickHandlerInterface::class), 3600);
        $registry = new NickServCommandRegistry([$handler]);
        $styles = [
            PresentationStyle::default(),
            new PresentationStyle([
                Component::Command->value => new Appearance(prefix: '[', suffix: ']'),
                Component::Example->value => new Appearance(prefix: '>> '),
            ]),
        ];
        $rendered = [];
        foreach ($styles as $style) {
            $messages = [];
            $notifier = $this->createStub(NickServNotifierInterface::class);
            $notifier->method('getNick')->willReturn('CustomNick');
            $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
            $renderer = new IrcRenderer(new FixedStyleProvider($style));
            $context = new NickServContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, 'HELP', ['REGISTER'], $notifier, $translator, 'es', 'UTC', 'NOTICE', $registry, new PendingVerificationRegistry(), new RecoveryTokenRegistry(), $this->createServiceNicks(), $renderer);
            $this->createHelpCommand()->execute($context);
            $adapter = new HelpFormatterContextAdapter($context, $this->createStub(NickServOperatorAccess::class));
            $general = new NickServContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, 'HELP', [], $notifier, $translator, 'es', 'UTC', 'NOTICE', $registry, new PendingVerificationRegistry(), new RecoveryTokenRegistry(), $this->createServiceNicks(), $renderer);
            $this->createHelpCommand()->execute($general);
            $rendered[] = [$messages[0], $adapter->trans('set.msg.help'), $messages[1], $adapter->trans('set.language.help'), $adapter->trans('regmode.help')];
        }
        self::assertStringContainsString("  /msg CustomNick \x02\x0303REGISTER Clave\$egura_123\x03\x0F", $rendered[0][0]);
        self::assertStringContainsString('>> /msg CustomNick [REGISTER Clave$egura_123]', $rendered[1][0]);
        self::assertStringContainsString("\x02MSG ON\x03\x0F", $rendered[0][1]);
        self::assertStringContainsString('MSG ON', $rendered[1][1]);
        self::assertStringNotContainsString('%emphasis_', $rendered[0][1]);
        self::assertStringContainsString("  \x02es\x03\x0F (español)", $rendered[0][3]);
        self::assertStringContainsString("• \x02EMAIL:\x03\x0F Envía", $rendered[0][4]);
        self::assertStringNotContainsString('%emphasis_', $rendered[0][3] . $rendered[0][4]);
        self::assertStringContainsString("    \x0310›\x03\x0F \x02\x0303REGISTER    \x03\x0FRegistra tu apodo en los servicios.", $rendered[0][2]);
        if ('1' === getenv('NICKSERV_HELP_PREVIEW')) {
            fwrite(STDERR, "\n--- Spanish NickServ general HELP with commands ---\n" . $rendered[0][2] . "\n--- Spanish NickServ HELP REGISTER ---\n" . $rendered[0][0] . "\n--- Spanish SET MSG text ---\n" . $rendered[0][1] . "\n");
        }
    }

    #[Test]
    public function englishHelpRendersExamplesAndInlineEmphasisWithSelectedStyle(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 6) . '/translations/nickserv.en.yaml', 'en', 'nickserv');
        $translator->addResource('yaml', dirname(__DIR__, 6) . '/translations/common.en.yaml', 'en', 'common');
        $handler = new RegisterCommand($this->createStub(RegisterNickHandlerInterface::class), 3600);
        $registry = new NickServCommandRegistry([$handler]);
        $styles = [
            PresentationStyle::default(),
            new PresentationStyle([
                Component::Command->value => new Appearance(prefix: '[', suffix: ']'),
                Component::Example->value => new Appearance(prefix: '>> '),
            ]),
        ];
        $rendered = [];
        foreach ($styles as $style) {
            $messages = [];
            $notifier = $this->createStub(NickServNotifierInterface::class);
            $notifier->method('getNick')->willReturn('CustomNick');
            $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
            $renderer = new IrcRenderer(new FixedStyleProvider($style));
            $context = new NickServContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, 'HELP', ['REGISTER'], $notifier, $translator, 'en', 'UTC', 'NOTICE', $registry, new PendingVerificationRegistry(), new RecoveryTokenRegistry(), $this->createServiceNicks(), $renderer);
            $this->createHelpCommand()->execute($context);
            $adapter = new HelpFormatterContextAdapter($context, $this->createStub(NickServOperatorAccess::class));
            $general = new NickServContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, 'HELP', [], $notifier, $translator, 'en', 'UTC', 'NOTICE', $registry, new PendingVerificationRegistry(), new RecoveryTokenRegistry(), $this->createServiceNicks(), $renderer);
            $this->createHelpCommand(30)->execute($general);
            $rendered[] = [$messages[0], $adapter->trans('set.msg.help'), $messages[1], $adapter->trans('set.language.help'), $adapter->trans('regmode.help')];
        }
        self::assertStringContainsString("  /msg CustomNick \x02\x0303REGISTER SafePa\$\$word_123\x03\x0F", $rendered[0][0]);
        self::assertStringContainsString('>> /msg CustomNick [REGISTER SafePa$$word_123]', $rendered[1][0]);
        self::assertStringContainsString("\x02\x0303REGISTER    \x03\x0F", $rendered[0][2]);
        self::assertStringContainsString('HELP <command>', $rendered[0][2]);
        self::assertStringNotContainsString('<comando>', $rendered[0][2]);
        self::assertStringContainsString('user@example.com', $rendered[0][0]);
        self::assertStringContainsString("\x1F\x030430 days\x03\x0F", $rendered[0][2]);
        self::assertStringNotContainsString('%marker%', $rendered[0][2]);
    }

    #[Test]
    public function eachLocaleRendersHelpFragmentsAndExpirationWithoutTemplateSlots(): void
    {
        foreach (['ca', 'de', 'el', 'en', 'es', 'eu', 'fr', 'gl', 'it', 'nl', 'pl', 'pt', 'ro', 'tr'] as $language) {
            $translator = new Translator($language);
            $translator->addLoader('yaml', new IrcYamlTranslationLoader());
            $translator->addResource('yaml', dirname(__DIR__, 6) . '/translations/nickserv.' . $language . '.yaml', $language, 'nickserv');
            $messages = [];
            $notifier = $this->createStub(NickServNotifierInterface::class);
            $notifier->method('getNick')->willReturn('CustomNick');
            $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
            $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
            $context = new NickServContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, 'HELP', [], $notifier, $translator, $language, 'UTC', 'NOTICE', new NickServCommandRegistry([]), new PendingVerificationRegistry(), new RecoveryTokenRegistry(), $this->createServiceNicks(), $renderer);
            $this->createHelpCommand(30)->execute($context);
            self::assertStringContainsString('CustomNick', $messages[0], $language);
            self::assertStringNotContainsString('%marker%', $messages[0], $language);
            self::assertStringNotContainsString('%duration%', $messages[0], $language);
            self::assertStringContainsString("\x1F\x030430", $messages[0], $language);
            $adapter = new HelpFormatterContextAdapter($context, $this->createStub(NickServOperatorAccess::class));
            foreach (['identify.help', 'identify.native_help', 'set.language.help', 'set.private.help', 'set.msg.help', 'set.vhost.help', 'regmode.help', 'suspend.help'] as $key) {
                $text = $adapter->trans($key);
                self::assertStringNotContainsString('%emphasis_', $text, $language . ': ' . $key);
                self::assertStringNotContainsString('help_fragments.', $text, $language . ': ' . $key);
            }
            $native = new UnifiedHelpFormatter()->renderExamples($adapter, 'identify.native_example');
            self::assertStringContainsString('/NICK', implode("\n", $native), $language);
            $unknown = $adapter->trans('help.set_timezone.region_unknown', ['syntax' => 'HELP SET TIMEZONE']);
            self::assertStringContainsString('HELP SET TIMEZONE', $unknown, $language);
            if ('el' === $language) {
                self::assertStringContainsString("\x02\x0303%d\x03\x0F", $adapter->trans('suspend.help'));
            }
        }
    }

    #[Test]
    #[DataProvider('unstyledHelpCases')]
    public function unstyledHelpResolvesLocalizedContent(string $language, ?string $key, int $fragments): void
    {
        $translator = new Translator($language);
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 6) . '/translations/nickserv.' . $language . '.yaml', $language, 'nickserv');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('CustomNick');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $context = new NickServContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, 'HELP', [], $notifier, $translator, $language, 'UTC', 'NOTICE', new NickServCommandRegistry([]), new PendingVerificationRegistry(), new RecoveryTokenRegistry(), $this->createServiceNicks());
        if (null === $key) {
            $this->createHelpCommand(30)->execute($context);
            $text = implode("\n", $messages);
            self::assertStringContainsString($translator->trans('help.intro_expiration_duration', ['%days%' => 30], 'nickserv'), $text);
            self::assertStringContainsString('CustomNick', $text);
            self::assertStringNotContainsString('%duration%', $text);
        } else {
            $adapter = new HelpFormatterContextAdapter($context, $this->createStub(NickServOperatorAccess::class));
            $text = $adapter->trans($key);
            for ($i = 0; $i < $fragments; ++$i) {
                if (str_contains($translator->trans($key, [], 'nickserv'), '%emphasis_' . $i . '%')) {
                    self::assertStringContainsString($translator->trans('help_fragments.' . str_replace('.', '_', $key) . '_' . $i, [], 'nickserv'), $text);
                }
            }
            self::assertStringNotContainsString('%emphasis_', $text);
            self::assertStringNotContainsString('help_fragments.', $text);
        }
        self::assertStringNotContainsString("\x03", $text);
        self::assertStringNotContainsString("\x02", $text);
    }

    /** @return iterable<string, array{string, ?string, int}> */
    public static function unstyledHelpCases(): iterable
    {
        foreach (['en', 'es'] as $language) {
            yield $language . ' expiration' => [$language, null, 0];
            foreach (['identify.help' => 1, 'identify.native_help' => 2, 'set.language.help' => 14, 'set.private.help' => 2, 'set.msg.help' => 4, 'set.vhost.help' => 1, 'regmode.help' => 3, 'suspend.help' => 4] as $key => $fragments) {
                yield $language . ' ' . $key => [$language, $key, $fragments];
            }
        }
    }

    private function createHelpCommand(int $inactivityExpiryDays = 0): HelpCommand
    {
        return new HelpCommand(
            new UnifiedHelpFormatter(),
            new TimezoneHelpProvider(),
            $this->createStub(NickServOperatorAccess::class),
            $inactivityExpiryDays,
        );
    }

    /**
     * @param list<string> $args
     */
    private function createContext(
        ?SenderView $sender,
        array $args,
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
        NickServCommandRegistry $registry,
    ): NickServContext {
        return new NickServContext(
            $sender,
            null,
            'HELP',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            $registry,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
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

    /**
     * @param array<array-key, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private static function requireStringKeyedParameters(array $parameters): array
    {
        $stringKeyedParameters = [];
        foreach ($parameters as $key => $value) {
            self::assertIsString($key);
            $stringKeyedParameters[$key] = $value;
        }

        return $stringKeyedParameters;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private static function replaceTranslationParameters(string $translation, array $parameters): string
    {
        $stringParameters = [];
        foreach ($parameters as $key => $value) {
            $stringParameters[$key] = match (true) {
                is_scalar($value) => (string) $value,
                $value instanceof Stringable => (string) $value,
                default => '',
            };
        }

        return strtr($translation, $stringParameters);
    }
}
