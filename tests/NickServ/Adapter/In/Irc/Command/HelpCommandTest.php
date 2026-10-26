<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\HelpCommand;
use App\NickServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\In\Irc\TimezoneHelpProvider;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Application\Port\Out\NickServOperatorAccess;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stringable;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_slice;
use function is_scalar;

#[CoversClass(HelpCommand::class)]
final class HelpCommandTest extends TestCase
{
    private const array SERVICE_NICKNAME_PLACEHOLDERS = [
        '%bot%' => '',
        '%nickserv%' => 'NickServ',
        '%chanserv%' => 'ChanServ',
        '%memoserv%' => 'MemoServ',
        '%operserv%' => 'OperServ',
    ];

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
