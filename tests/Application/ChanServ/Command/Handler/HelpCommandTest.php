<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\HelpCommand;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Application\Security\PermissionRegistry;
use App\Application\Shared\Help\UnifiedHelpFormatter;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(HelpCommand::class)]
final class HelpCommandTest extends TestCase
{
    private function createContext(
        array $args,
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
        ChanServCommandRegistry $registry,
    ): ChanServContext {
        return new ChanServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            null,
            'HELP',
            $args,
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
    }

    #[Test]
    public function emptyArgsShowsGeneralHelpAndFooter(): void
    {
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new class implements ChanServCommandInterface {
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

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function execute(ChanServContext $c): void
            {
            }
        };
        $registry = new ChanServCommandRegistry([$handler]);

        $cmd = $this->createCommand();
        $cmd->execute($this->createContext([], $notifier, $translator, $registry));

        self::assertContains('help.footer', $messages);
    }

    #[Test]
    public function unknownCommandRepliesHelpUnknown(): void
    {
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new class implements ChanServCommandInterface {
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

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function execute(ChanServContext $c): void
            {
            }
        };
        $registry = new ChanServCommandRegistry([$handler]);

        $cmd = $this->createCommand();
        $cmd->execute($this->createContext(['UNKNOWNCMD'], $notifier, $translator, $registry));

        self::assertContains('help.unknown_command', $messages);
    }

    #[Test]
    public function oneArgShowsCommandHelpForKnownCommand(): void
    {
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $registerHandler = new class implements ChanServCommandInterface {
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

            public function execute(ChanServContext $c): void
            {
            }
        };
        $registry = new ChanServCommandRegistry([$registerHandler]);

        $cmd = $this->createCommand();
        $cmd->execute($this->createContext(['REGISTER'], $notifier, $translator, $registry));

        self::assertContains('register.help', $messages);
        self::assertContains('help.footer', $messages);
    }

    #[Test]
    public function generalHelpWithInactivityExpirySendsIntroExpirationAndFooter(): void
    {
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ([] !== $params ? json_encode($params) : ''));

        $handler = new class implements ChanServCommandInterface {
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

            public function allowsSuspendedChannel(): bool
            {
                return true;
            }

            public function execute(ChanServContext $c): void
            {
            }
        };
        $registry = new ChanServCommandRegistry([$handler]);

        $cmd = $this->createCommand(30);
        $cmd->execute($this->createContext([], $notifier, $translator, $registry));

        $all = implode(' ', $messages);
        self::assertStringContainsString('help.intro_expiration', $all);
        self::assertStringContainsString('help.footer', $all);
    }

    #[Test]
    public function twoArgsWithValidSubCommandShowsSubCommandHelp(): void
    {
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $setHandler = new class implements ChanServCommandInterface {
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
                        'help_key' => 'set.founder.help',
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

            public function execute(ChanServContext $c): void
            {
            }
        };
        $registry = new ChanServCommandRegistry([$setHandler]);

        $cmd = $this->createCommand();
        $cmd->execute($this->createContext(['SET', 'FOUNDER'], $notifier, $translator, $registry));

        self::assertContains('set.founder.help', $messages);
        self::assertContains('help.footer', $messages);
    }

    #[Test]
    public function twoArgsWithUnknownSubCommandShowsCommandHelp(): void
    {
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $setHandler = new class implements ChanServCommandInterface {
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
                        'help_key' => 'set.founder.help',
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

            public function execute(ChanServContext $c): void
            {
            }
        };
        $registry = new ChanServCommandRegistry([$setHandler]);

        $cmd = $this->createCommand();
        $cmd->execute($this->createContext(['SET', 'NOSUCHSUB'], $notifier, $translator, $registry));

        self::assertContains('set.help', $messages);
        self::assertContains('help.footer', $messages);
    }

    #[Test]
    public function twoArgsWithSubCommandCaseInsensitiveShowsSubCommandHelp(): void
    {
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $setHandler = new class implements ChanServCommandInterface {
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
                        'help_key' => 'set.founder.help',
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

            public function execute(ChanServContext $c): void
            {
            }
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
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $registerHandler = new class implements ChanServCommandInterface {
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

            public function execute(ChanServContext $c): void
            {
            }
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
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $accessHandler = new class implements ChanServCommandInterface {
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
                        'help_key' => 'access.add.help',
                        'syntax_key' => 'access.add.syntax',
                    ],
                    [
                        'name' => 'DEL',
                        'desc_key' => 'access.del.desc',
                        'help_key' => 'access.del.help',
                        'syntax_key' => 'access.del.syntax',
                    ],
                    [
                        'name' => 'LIST',
                        'desc_key' => 'access.list.desc',
                        'help_key' => 'access.list.help',
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

            public function execute(ChanServContext $c): void
            {
            }
        };
        $registry = new ChanServCommandRegistry([$accessHandler]);

        $cmd = $this->createCommand();
        $cmd->execute($this->createContext(['ACCESS'], $notifier, $translator, $registry));

        self::assertContains('access.help', $messages);
        self::assertContains('help.options_header', $messages);
        self::assertContains('help.subcommand_line', $messages);
    }

    #[Test]
    public function helpWithUnknownSubcommandFallsBackToCommandHelp(): void
    {
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $akickHandler = new class implements ChanServCommandInterface {
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
                        'help_key' => 'akick.add.help',
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

            public function execute(ChanServContext $c): void
            {
            }
        };
        $registry = new ChanServCommandRegistry([$akickHandler]);

        $cmd = $this->createCommand();
        $cmd->execute($this->createContext(['AKICK', 'INVALID'], $notifier, $translator, $registry));

        self::assertContains('akick.help', $messages);
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

    private function createCommand(int $inactivityExpiryDays = 0): HelpCommand
    {
        return new HelpCommand(
            new UnifiedHelpFormatter(),
            new IrcopAccessHelper(
                new RootUserRegistry(''),
                $this->createStub(OperIrcopRepositoryInterface::class),
                $this->createStub(OperRoleRepositoryInterface::class),
            ),
            new RootUserRegistry(''),
            new PermissionRegistry([]),
            $inactivityExpiryDays,
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider1 = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

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
            public function __construct(private string $key, private string $nick)
            {
            }

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
            public function __construct(private string $key, private string $nick)
            {
            }

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
            public function __construct(private string $key, private string $nick)
            {
            }

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
