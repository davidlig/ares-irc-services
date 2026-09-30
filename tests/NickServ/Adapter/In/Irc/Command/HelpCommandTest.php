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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stringable;
use Symfony\Contracts\Translation\TranslatorInterface;

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
     * @param string[] $args
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
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
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

        self::assertContains('help.unknown_command', $messages);
    }

    #[Test]
    public function emptyArgsShowsGeneralHelp(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
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
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translations = [];
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = []) use (&$translations): string {
                $translations[] = ['id' => $id, 'parameters' => $parameters];

                $translation = match ($id) {
                    'help.intro_expiration_label' => 'NOTE',
                    'help.warning_marker' => 'translated:help.warning_marker',
                    'help.intro_expiration' => '%marker% %label%: Nicknames unused for more than %days% days are automatically removed.',
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
            'translated:help.warning_marker NOTE: Nicknames unused for more than 30 days are automatically removed.',
            $messages,
        );
        self::assertContains([
            'id' => 'help.intro_expiration',
            'parameters' => [
                ...self::SERVICE_NICKNAME_PLACEHOLDERS,
                '%marker%' => 'translated:help.warning_marker',
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
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
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

            public function getMinArgs(): int
            {
                return 0;
            }

            public function getSyntaxKey(): string
            {
                return 'opercmd.syntax';
            }

            public function getHelpKey(): string
            {
                return 'opercmd.help';
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

        self::assertContains('help.unknown_command', $messages);
    }

    #[Test]
    public function knownCommandShowsCommandHelp(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
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

            public function getMinArgs(): int
            {
                return 2;
            }

            public function getSyntaxKey(): string
            {
                return 'register.syntax';
            }

            public function getHelpKey(): string
            {
                return 'register.help';
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
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
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

            public function getMinArgs(): int
            {
                return 1;
            }

            public function getSyntaxKey(): string
            {
                return 'set.syntax';
            }

            public function getHelpKey(): string
            {
                return 'set.help';
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
    }

    #[Test]
    public function setTimezoneCommandWithRegionShowsTimezones(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translations = [];
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = []) use (&$translations): string {
                $translations[] = ['id' => $id, 'parameters' => $parameters];

                $translation = 'help.set_timezone.region_header' === $id
                    ? 'Timezones for %region%:'
                    : $id;

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

            public function getMinArgs(): int
            {
                return 1;
            }

            public function getSyntaxKey(): string
            {
                return 'set.syntax';
            }

            public function getHelpKey(): string
            {
                return 'set.help';
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
        self::assertContains('Timezones for Europe:', $messages);
        self::assertContains([
            'id' => 'help.set_timezone.region_header',
            'parameters' => [
                ...self::SERVICE_NICKNAME_PLACEHOLDERS,
                '%region%' => 'Europe',
            ],
        ], $translations);
        $output = implode("\n", $messages);
        self::assertStringNotContainsString('help.set_timezone.region_header', $output);
        self::assertStringNotContainsString('%region%', $output);
    }

    #[Test]
    public function setTimezoneCommandWithUnknownRegionShowsError(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
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

            public function getMinArgs(): int
            {
                return 1;
            }

            public function getSyntaxKey(): string
            {
                return 'set.syntax';
            }

            public function getHelpKey(): string
            {
                return 'set.help';
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
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translations = [];
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $parameters = []) use (&$translations): string {
                $translations[] = ['id' => $id, 'parameters' => $parameters];

                $translation = match ($id) {
                    'help.set_timezone.index_syntax' => 'HELP SET TIMEZONE <region>',
                    'help.set_timezone.index_label' => 'Regions (use %syntax% for list):',
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

            public function getMinArgs(): int
            {
                return 1;
            }

            public function getSyntaxKey(): string
            {
                return 'set.syntax';
            }

            public function getHelpKey(): string
            {
                return 'set.help';
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
    }

    #[Test]
    public function commandWithUnknownSubCommandShowsCommandHelp(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
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

            public function getMinArgs(): int
            {
                return 1;
            }

            public function getSyntaxKey(): string
            {
                return 'set.syntax';
            }

            public function getHelpKey(): string
            {
                return 'set.help';
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
        $cmd->execute($this->createContext($sender, ['SET', 'UNKNOWN'], $notifier, $translator, $registry));

        self::assertNotEmpty($messages);
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
