<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\Handler\HelpCommand;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\TimezoneHelpProvider;
use App\Application\Port\SenderView;
use App\Application\Shared\Help\UnifiedHelpFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(HelpCommand::class)]
final class HelpCommandTest extends TestCase
{
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
            new \App\Application\NickServ\PendingVerificationRegistry(),
            new \App\Application\NickServ\RecoveryTokenRegistry(),
        );
    }

    #[Test]
    public function doesNothingWhenSenderNull(): void
    {
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');
        $translator = $this->createStub(TranslatorInterface::class);
        $registry = new NickServCommandRegistry([]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), 0);
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

            public function execute(NickServContext $c): void
            {
            }
        };
        $registry = new NickServCommandRegistry([$handler]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), 0);
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

            public function execute(NickServContext $c): void
            {
            }
        };
        $registry = new NickServCommandRegistry([$handler]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), 0);
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
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

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

            public function execute(NickServContext $c): void
            {
            }
        };
        $registry = new NickServCommandRegistry([$handler]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), 30);
        $cmd->execute($this->createContext($sender, [], $notifier, $translator, $registry));

        self::assertContains('help.intro_expiration', $messages);
    }

    #[Test]
    public function operOnlyCommandHiddenFromNonOper(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip', false, false, '', '');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $operOnlyHandler = new class implements NickServCommandInterface {
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
                return true;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function execute(NickServContext $c): void
            {
            }
        };
        $registry = new NickServCommandRegistry([$operOnlyHandler]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), 0);
        $cmd->execute($this->createContext($sender, ['OPER'], $notifier, $translator, $registry));

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

            public function execute(NickServContext $c): void
            {
            }
        };
        $registry = new NickServCommandRegistry([$handler]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), 0);
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

            public function execute(NickServContext $c): void
            {
            }
        };
        $registry = new NickServCommandRegistry([$handlerWithSub]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), 0);
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

            public function execute(NickServContext $c): void
            {
            }
        };
        $registry = new NickServCommandRegistry([$handlerWithSub]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), 0);
        $cmd->execute($this->createContext($sender, ['SET', 'TIMEZONE', 'Europe'], $notifier, $translator, $registry));

        self::assertNotEmpty($messages);
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

            public function execute(NickServContext $c): void
            {
            }
        };
        $registry = new NickServCommandRegistry([$handlerWithSub]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), 0);
        $cmd->execute($this->createContext($sender, ['SET', 'TIMEZONE', 'UnknownRegion'], $notifier, $translator, $registry));

        self::assertContains('help.set_timezone.region_unknown', $messages);
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

            public function execute(NickServContext $c): void
            {
            }
        };
        $registry = new NickServCommandRegistry([$handlerWithSub]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), 0);
        $cmd->execute($this->createContext($sender, ['SET', 'TIMEZONE'], $notifier, $translator, $registry));

        self::assertContains('help.set_timezone.index_label', $messages);
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

            public function execute(NickServContext $c): void
            {
            }
        };
        $registry = new NickServCommandRegistry([$handlerWithSub]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), 0);
        $cmd->execute($this->createContext($sender, ['SET', 'UNKNOWN'], $notifier, $translator, $registry));

        self::assertNotEmpty($messages);
    }

    #[Test]
    public function getAliasesReturnsArray(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), 0);
        self::assertSame(['?'], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsZero(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), 0);
        self::assertSame(0, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsString(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), 0);
        self::assertSame('help.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsString(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), 0);
        self::assertSame('help.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsInt(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), 0);
        self::assertSame(99, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsString(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), 0);
        self::assertSame('help.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), 0);
        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), 0);
        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsNull(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter(), new TimezoneHelpProvider(), 0);
        self::assertNull($cmd->getRequiredPermission());
    }
}
