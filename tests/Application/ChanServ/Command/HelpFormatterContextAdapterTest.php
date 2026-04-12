<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\HelpFormatterContextAdapter;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Application\Security\PermissionProviderInterface;
use App\Application\Security\PermissionRegistry;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(HelpFormatterContextAdapter::class)]
final class HelpFormatterContextAdapterTest extends TestCase
{
    private function createContext(
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
        ChanServCommandRegistry $registry,
        $channelModeSupport = null,
    ): ChanServContext {
        return new ChanServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'),
            null,
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
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
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

            public function execute(ChanServContext $c): void
            {
            }
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

            public function execute(ChanServContext $c): void
            {
            }
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

            public function execute(ChanServContext $c): void
            {
            }
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

            public function execute(ChanServContext $c): void
            {
            }
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

            public function execute(ChanServContext $c): void
            {
            }
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

            public function getRequiredPermission(): ?string
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

            public function execute(ChanServContext $c): void
            {
            }
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

            public function getRequiredPermission(): ?string
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

            public function execute(ChanServContext $c): void
            {
            }
        };
        $registry = new ChanServCommandRegistry([$dropCmd]);

        $sender = new SenderView('UID1', 'RootAdmin', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);

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

        $rootRegistry = new RootUserRegistry('rootadmin');
        $permissionRegistry = new PermissionRegistry([]);
        $adapter = new HelpFormatterContextAdapter(
            $context,
            new IrcopAccessHelper($rootRegistry, $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(OperRoleRepositoryInterface::class)),
            $rootRegistry,
            $permissionRegistry,
        );

        $ircopCommands = iterator_to_array($adapter->getIrcopCommands());
        self::assertCount(1, $ircopCommands);
        self::assertSame('DROP', $ircopCommands[0]->getName());
    }

    #[Test]
    public function getIrcopCommandsReturnsEmptyForNonOper(): void
    {
        $sender = new SenderView('UID1', 'NormalUser', 'i', 'h', 'c', 'ip', false, false, 'SID1', 'h', 'o', '');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);

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
    public function hasIrcopAccessReturnsTrueForRoot(): void
    {
        $sender = new SenderView('UID1', 'RootAdmin', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);

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

        $rootRegistry = new RootUserRegistry('rootadmin');
        $adapter = new HelpFormatterContextAdapter(
            $context,
            new IrcopAccessHelper($rootRegistry, $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(OperRoleRepositoryInterface::class)),
            $rootRegistry,
            new PermissionRegistry([]),
        );

        self::assertTrue($adapter->hasIrcopAccess());
    }

    #[Test]
    public function hasIrcopAccessReturnsFalseForOperWithoutIrcopRole(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);

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

        $chanServPermission = new readonly class('ChanServ', [ChanServPermission::DROP]) implements PermissionProviderInterface {
            public function __construct(private string $name, private array $perms)
            {
            }

            public function getServiceName(): string
            {
                return $this->name;
            }

            public function getPermissions(): array
            {
                return $this->perms;
            }
        };
        $rootRegistry = new RootUserRegistry('');
        $accessHelper = new IrcopAccessHelper($rootRegistry, $this->createStub(OperIrcopRepositoryInterface::class), $this->createStub(OperRoleRepositoryInterface::class));
        $adapter = new HelpFormatterContextAdapter(
            $context,
            $accessHelper,
            $rootRegistry,
            new PermissionRegistry([$chanServPermission]),
        );

        self::assertFalse($adapter->hasIrcopAccess());
    }

    #[Test]
    public function hasIrcopAccessReturnsTrueForOperWithChanServPermission(): void
    {
        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);

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

        $chanServPermission = new readonly class('ChanServ', [ChanServPermission::DROP]) implements PermissionProviderInterface {
            public function __construct(private string $name, private array $perms)
            {
            }

            public function getServiceName(): string
            {
                return $this->name;
            }

            public function getPermissions(): array
            {
                return $this->perms;
            }
        };

        $operRole = OperRole::create('Oper');
        $roleRef = new ReflectionProperty(OperRole::class, 'id');
        $roleRef->setAccessible(true);
        $roleRef->setValue($operRole, 5);

        $operIrcop = \App\Domain\OperServ\Entity\OperIrcop::create(1, $operRole, null, null);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($operIrcop);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('hasPermission')->willReturnMap([
            [5, ChanServPermission::DROP, true],
        ]);

        $rootRegistry = new RootUserRegistry('');
        $accessHelper = new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
        $adapter = new HelpFormatterContextAdapter(
            $context,
            $accessHelper,
            $rootRegistry,
            new PermissionRegistry([$chanServPermission]),
        );

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

            public function getRequiredPermission(): ?string
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

            public function execute(ChanServContext $c): void
            {
            }
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

            public function execute(ChanServContext $c): void
            {
            }
        };
        $registry = new ChanServCommandRegistry([$dropCmd, $infoCmd]);

        $sender = new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', false, true, 'SID1', 'h', 'o', '');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);

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

        $chanServPermission = new readonly class('ChanServ', [ChanServPermission::DROP]) implements PermissionProviderInterface {
            public function __construct(private string $name, private array $perms)
            {
            }

            public function getServiceName(): string
            {
                return $this->name;
            }

            public function getPermissions(): array
            {
                return $this->perms;
            }
        };

        $operRole = OperRole::create('Oper');
        $roleRef = new ReflectionProperty(OperRole::class, 'id');
        $roleRef->setAccessible(true);
        $roleRef->setValue($operRole, 5);

        $operIrcop = \App\Domain\OperServ\Entity\OperIrcop::create(1, $operRole, null, null);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($operIrcop);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('hasPermission')->willReturnMap([
            [5, ChanServPermission::DROP, true],
        ]);

        $rootRegistry = new RootUserRegistry('');
        $accessHelper = new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
        $adapter = new HelpFormatterContextAdapter(
            $context,
            $accessHelper,
            $rootRegistry,
            new PermissionRegistry([$chanServPermission]),
        );

        $ircopCommands = iterator_to_array($adapter->getIrcopCommands());
        self::assertCount(1, $ircopCommands);
        self::assertSame('DROP', $ircopCommands[0]->getName());
    }

    private function createAdapter(ChanServContext $context): HelpFormatterContextAdapter
    {
        $accessHelper = new IrcopAccessHelper(
            new RootUserRegistry(''),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(OperRoleRepositoryInterface::class),
        );
        $rootRegistry = new RootUserRegistry('');
        $permissionRegistry = new PermissionRegistry([]);

        return new HelpFormatterContextAdapter($context, $accessHelper, $rootRegistry, $permissionRegistry);
    }
}
