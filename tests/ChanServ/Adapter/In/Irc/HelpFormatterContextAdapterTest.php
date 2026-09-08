<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc;

use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\TranslationInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\HelpFormatterContextAdapter;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChanServOperatorAccess;
use App\ChanServ\Application\Security\ChanServPermission;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(HelpFormatterContextAdapter::class)]
final class HelpFormatterContextAdapterTest extends TestCase
{
    private function createContext(
        ChanServNotifierInterface $notifier,
        TranslationInterface $translator,
        ChanServCommandRegistry $registry,
        ?ChannelModeSupportInterface $channelModeSupport = null,
        ?NetworkUserLookupPort $userLookup = null,
        ?SenderView $sender = null,
        ?ChanAccountView $account = null,
    ): ChanServContext {
        return new ChanServContext(
            $sender ?? new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
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
            $channelModeSupport ?? new NullChannelModeSupport(),
            $userLookup ?? $this->createStub(NetworkUserLookupPort::class),
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

    #[Test]
    public function replyDelegatesToContext(): void
    {
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
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
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $context = $this->createContext($notifier, $translator, new ChanServCommandRegistry([]));
        $adapter = $this->createAdapter($context);

        $adapter->replyRaw('Raw message');

        self::assertSame(['Raw message'], $messages);
    }

    #[Test]
    public function transDelegatesToContext(): void
    {
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $context = $this->createContext($this->createStub(ChanServNotifierInterface::class), $translator, new ChanServCommandRegistry([]));
        $adapter = $this->createAdapter($context);

        self::assertSame('help.key', $adapter->trans('help.key'));
    }

    #[Test]
    public function getCommandsForGeneralHelpReturnsRegistryAll(): void
    {
        $cmd = new class implements ChanServCommandInterface {
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
            $this->createStub(TranslationInterface::class),
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
            $this->createStub(TranslationInterface::class),
            $registry,
        );
        $adapter = $this->createAdapter($context);

        self::assertFalse($adapter->shouldShowCommandInGeneralHelp($cmd));
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpReturnsTrueForNormalCommand(): void
    {
        $cmd = new class implements ChanServCommandInterface {
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
            $this->createStub(TranslationInterface::class),
            $registry,
        );
        $adapter = $this->createAdapter($context);

        self::assertTrue($adapter->shouldShowCommandInGeneralHelp($cmd));
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpRespectsModeDependentCommandWhenSupportHasAdmin(): void
    {
        $cmd = new class implements ChanServCommandInterface {
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
            $this->createStub(TranslationInterface::class),
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
            $this->createStub(TranslationInterface::class),
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
            $this->createStub(TranslationInterface::class),
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
            $this->createStub(TranslationInterface::class),
            new ChanServCommandRegistry([]),
        );
        $adapter = $this->createAdapter($context);

        self::assertFalse($adapter->hasIrcopAccess());
    }

    #[Test]
    public function hasIrcopAccessIsFalseForAnIdentifiedNonOper(): void
    {
        $context = $this->createContext(
            $this->createStub(ChanServNotifierInterface::class),
            $this->createStub(TranslationInterface::class),
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
            $this->createStub(TranslationInterface::class),
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
        $translator = $this->createStub(TranslationInterface::class);
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
        $translator = $this->createStub(TranslationInterface::class);
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
        $translator = $this->createStub(TranslationInterface::class);
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
    public function hasIrcopAccessReturnsTrueForRoot(): void
    {
        $sender = new SenderView('UID1', 'RootAdmin', 'i', 'h', 'c', 'ip', true, true, 'SID1', 'h', 'o');
        $account = new ChanAccountView(1, 'User', 'en');

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message): void {});
        $translator = $this->createStub(TranslationInterface::class);
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
        $translator = $this->createStub(TranslationInterface::class);
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
        $translator = $this->createStub(TranslationInterface::class);
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
        $translator = $this->createStub(TranslationInterface::class);
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

    private function createAdapter(ChanServContext $context): HelpFormatterContextAdapter
    {
        return new HelpFormatterContextAdapter($context, $this->createStub(ChanServOperatorAccess::class));
    }
}
