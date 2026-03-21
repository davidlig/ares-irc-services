<?php

declare(strict_types=1);

namespace App\Tests\Application\MemoServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\MemoServ\Command\Handler\HelpCommand;
use App\Application\MemoServ\Command\MemoServCommandInterface;
use App\Application\MemoServ\Command\MemoServCommandRegistry;
use App\Application\MemoServ\Command\MemoServContext;
use App\Application\MemoServ\Command\MemoServNotifierInterface;
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
        array $args,
        MemoServNotifierInterface $notifier,
        TranslatorInterface $translator,
        MemoServCommandRegistry $registry,
    ): MemoServContext {
        return new MemoServContext(
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
            $this->createServiceNicks(),
        );
    }

    #[Test]
    public function emptyArgsShowsGeneralHelpAndFooter(): void
    {
        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new class implements MemoServCommandInterface {
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

            public function execute(MemoServContext $c): void
            {
            }
        };
        $registry = new MemoServCommandRegistry([$handler]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        $cmd->execute($this->createContext([], $notifier, $translator, $registry));

        self::assertContains('help.footer', $messages);
    }

    #[Test]
    public function unknownCommandRepliesHelpUnknown(): void
    {
        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new class implements MemoServCommandInterface {
            public function getName(): string
            {
                return 'SEND';
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
                return 'send.help';
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

            public function execute(MemoServContext $c): void
            {
            }
        };
        $registry = new MemoServCommandRegistry([$handler]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        $cmd->execute($this->createContext(['UNKNOWNCMD'], $notifier, $translator, $registry));

        self::assertContains('help.unknown_command', $messages);
    }

    #[Test]
    public function knownCommandShowsCommandHelp(): void
    {
        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $sendHandler = new class implements MemoServCommandInterface {
            public function getName(): string
            {
                return 'SEND';
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
                return 'send.syntax';
            }

            public function getHelpKey(): string
            {
                return 'send.help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'send.short';
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

            public function execute(MemoServContext $c): void
            {
            }
        };
        $registry = new MemoServCommandRegistry([$sendHandler]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        $cmd->execute($this->createContext(['SEND'], $notifier, $translator, $registry));

        self::assertContains('send.help', $messages);
    }

    #[Test]
    public function knownCommandWithExistingSubCommandShowsSubCommandHelp(): void
    {
        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $sendHandler = new class implements MemoServCommandInterface {
            public function getName(): string
            {
                return 'SEND';
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
                return 'send.syntax';
            }

            public function getHelpKey(): string
            {
                return 'send.help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'send.short';
            }

            public function getSubCommandHelp(): array
            {
                return [
                    ['name' => 'ADD', 'desc_key' => 'send.add.desc', 'help_key' => 'send.add.help', 'syntax_key' => 'send.add.syntax'],
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

            public function execute(MemoServContext $c): void
            {
            }
        };
        $registry = new MemoServCommandRegistry([$sendHandler]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        $cmd->execute($this->createContext(['SEND', 'ADD'], $notifier, $translator, $registry));

        self::assertContains('send.add.help', $messages);
    }

    #[Test]
    public function knownCommandWithUnknownSubCommandShowsCommandHelp(): void
    {
        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $sendHandler = new class implements MemoServCommandInterface {
            public function getName(): string
            {
                return 'SEND';
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
                return 'send.syntax';
            }

            public function getHelpKey(): string
            {
                return 'send.help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'send.short';
            }

            public function getSubCommandHelp(): array
            {
                return [
                    ['name' => 'ADD', 'desc_key' => 'x', 'help_key' => 'send.add.help', 'syntax_key' => 'x'],
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

            public function execute(MemoServContext $c): void
            {
            }
        };
        $registry = new MemoServCommandRegistry([$sendHandler]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        $cmd->execute($this->createContext(['SEND', 'UNKNOWNSUB'], $notifier, $translator, $registry));

        self::assertContains('send.help', $messages);
    }

    #[Test]
    public function knownCommandWithSubCommandCaseInsensitiveShowsSubCommandHelp(): void
    {
        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $sendHandler = new class implements MemoServCommandInterface {
            public function getName(): string
            {
                return 'SEND';
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
                return 'send.syntax';
            }

            public function getHelpKey(): string
            {
                return 'send.help';
            }

            public function getOrder(): int
            {
                return 0;
            }

            public function getShortDescKey(): string
            {
                return 'send.short';
            }

            public function getSubCommandHelp(): array
            {
                return [
                    ['name' => 'ADD', 'desc_key' => 'send.add.desc', 'help_key' => 'send.add.help', 'syntax_key' => 'send.add.syntax'],
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

            public function execute(MemoServContext $c): void
            {
            }
        };
        $registry = new MemoServCommandRegistry([$sendHandler]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        $cmd->execute($this->createContext(['SEND', 'add'], $notifier, $translator, $registry));

        self::assertContains('send.add.help', $messages);
    }

    #[Test]
    public function getNameReturnsHelp(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        self::assertSame('HELP', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsQuestionMark(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        self::assertSame(['?'], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsZero(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        self::assertSame(0, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsHelpSyntax(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        self::assertSame('help.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsHelpHelp(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        self::assertSame('help.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturns99(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        self::assertSame(99, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsHelpShort(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        self::assertSame('help.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsNull(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        self::assertNull($cmd->getRequiredPermission());
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
