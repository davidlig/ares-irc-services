<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\OperServ\Command\Handler\HelpCommand;
use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\SenderView;
use App\Application\Shared\Help\UnifiedHelpFormatter;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(HelpCommand::class)]
final class HelpCommandTest extends TestCase
{
    private function createAccessHelper(bool $isRoot): IrcopAccessHelper
    {
        $rootUsers = $isRoot ? 'TestUser' : '';
        $rootRegistry = new RootUserRegistry($rootUsers);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);

        return new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
    }

    private function createContext(
        ?SenderView $sender,
        array $args,
        OperServNotifierInterface $notifier,
        TranslatorInterface $translator,
        OperServCommandRegistry $registry,
        IrcopAccessHelper $accessHelper,
    ): OperServContext {
        return new OperServContext(
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
            $accessHelper,
            $this->createServiceNicks(),
        );
    }

    #[Test]
    public function unknownCommandRepliesHelpUnknown(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $handler = new class implements OperServCommandInterface {
            public function getName(): string
            {
                return 'IRCOP';
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
                return 'ircop.syntax';
            }

            public function getHelpKey(): string
            {
                return 'ircop.help';
            }

            public function getOrder(): int
            {
                return 1;
            }

            public function getShortDescKey(): string
            {
                return 'ircop.short';
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

            public function execute(OperServContext $c): void
            {
            }
        };
        $registry = new OperServCommandRegistry([$handler]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        $cmd->execute($this->createContext($sender, ['UNKNOWNCMD'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('help.unknown_command', $messages);
    }

    #[Test]
    public function emptyArgsShowsGeneralHelp(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $handler = new class implements OperServCommandInterface {
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

            public function execute(OperServContext $c): void
            {
            }
        };
        $registry = new OperServCommandRegistry([$handler]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        $cmd->execute($this->createContext($sender, [], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('help.footer', $messages);
    }

    #[Test]
    public function operOnlyCommandHiddenFromNonRoot(): void
    {
        $sender = new SenderView('UID1', 'NonRootUser', 'i', 'h', 'c', 'ip', false, false, '', '');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(false);

        $operOnlyHandler = new class implements OperServCommandInterface {
            public function getName(): string
            {
                return 'IRCOP';
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
                return 'ircop.syntax';
            }

            public function getHelpKey(): string
            {
                return 'ircop.help';
            }

            public function getOrder(): int
            {
                return 1;
            }

            public function getShortDescKey(): string
            {
                return 'ircop.short';
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

            public function execute(OperServContext $c): void
            {
            }
        };
        $registry = new OperServCommandRegistry([$operOnlyHandler]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        $cmd->execute($this->createContext($sender, ['IRCOP'], $notifier, $translator, $registry, $accessHelper));

        self::assertContains('help.unknown_command', $messages);
    }

    #[Test]
    public function operOnlyCommandShownToRoot(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip', false, false, '', '');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $operOnlyHandler = new class implements OperServCommandInterface {
            public function getName(): string
            {
                return 'IRCOP';
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
                return 'ircop.syntax';
            }

            public function getHelpKey(): string
            {
                return 'ircop.help';
            }

            public function getOrder(): int
            {
                return 1;
            }

            public function getShortDescKey(): string
            {
                return 'ircop.short';
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

            public function execute(OperServContext $c): void
            {
            }
        };
        $registry = new OperServCommandRegistry([$operOnlyHandler]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        $cmd->execute($this->createContext($sender, ['IRCOP'], $notifier, $translator, $registry, $accessHelper));

        self::assertNotEmpty($messages);
        self::assertContains('ircop.help', $messages);
    }

    #[Test]
    public function knownCommandShowsCommandHelp(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $handler = new class implements OperServCommandInterface {
            public function getName(): string
            {
                return 'IRCOP';
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
                return 'ircop.syntax';
            }

            public function getHelpKey(): string
            {
                return 'ircop.help';
            }

            public function getOrder(): int
            {
                return 1;
            }

            public function getShortDescKey(): string
            {
                return 'ircop.short';
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

            public function execute(OperServContext $c): void
            {
            }
        };
        $registry = new OperServCommandRegistry([$handler]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        $cmd->execute($this->createContext($sender, ['IRCOP'], $notifier, $translator, $registry, $accessHelper));

        self::assertNotEmpty($messages);
        self::assertContains('ircop.help', $messages);
    }

    #[Test]
    public function commandWithSubCommandShowsSubCommandHelp(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $handlerWithSub = new class implements OperServCommandInterface {
            public function getName(): string
            {
                return 'IRCOP';
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
                return 'ircop.syntax';
            }

            public function getHelpKey(): string
            {
                return 'ircop.help';
            }

            public function getOrder(): int
            {
                return 1;
            }

            public function getShortDescKey(): string
            {
                return 'ircop.short';
            }

            public function getSubCommandHelp(): array
            {
                return [
                    ['name' => 'ADD', 'desc_key' => 'ircop.add.short', 'help_key' => 'ircop.add.help', 'syntax_key' => 'ircop.add.syntax'],
                ];
            }

            public function isOperOnly(): bool
            {
                return true;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function execute(OperServContext $c): void
            {
            }
        };
        $registry = new OperServCommandRegistry([$handlerWithSub]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        $cmd->execute($this->createContext($sender, ['IRCOP', 'ADD'], $notifier, $translator, $registry, $accessHelper));

        self::assertNotEmpty($messages);
        self::assertContains('ircop.add.help', $messages);
    }

    #[Test]
    public function commandWithUnknownSubCommandShowsCommandHelp(): void
    {
        $sender = new SenderView('UID1', 'TestUser', 'i', 'h', 'c', 'ip');
        $messages = [];
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('OperServ');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $accessHelper = $this->createAccessHelper(true);

        $handlerWithSub = new class implements OperServCommandInterface {
            public function getName(): string
            {
                return 'IRCOP';
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
                return 'ircop.syntax';
            }

            public function getHelpKey(): string
            {
                return 'ircop.help';
            }

            public function getOrder(): int
            {
                return 1;
            }

            public function getShortDescKey(): string
            {
                return 'ircop.short';
            }

            public function getSubCommandHelp(): array
            {
                return [
                    ['name' => 'ADD', 'desc_key' => 'ircop.add.short', 'help_key' => 'ircop.add.help', 'syntax_key' => 'ircop.add.syntax'],
                ];
            }

            public function isOperOnly(): bool
            {
                return true;
            }

            public function getRequiredPermission(): ?string
            {
                return null;
            }

            public function execute(OperServContext $c): void
            {
            }
        };
        $registry = new OperServCommandRegistry([$handlerWithSub]);

        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        $cmd->execute($this->createContext($sender, ['IRCOP', 'UNKNOWN'], $notifier, $translator, $registry, $accessHelper));

        self::assertNotEmpty($messages);
        self::assertContains('ircop.help', $messages);
    }

    #[Test]
    public function getNameReturnsHelp(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        self::assertSame('HELP', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsArray(): void
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
    public function getSyntaxKeyReturnsString(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        self::assertSame('help.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsString(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        self::assertSame('help.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsInt(): void
    {
        $cmd = new HelpCommand(new UnifiedHelpFormatter());
        self::assertSame(99, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsString(): void
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
