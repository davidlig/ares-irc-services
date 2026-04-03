<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\NickServ\Command\HelpFormatterContextAdapter;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\SenderView;
use App\Application\Security\PermissionProviderInterface;
use App\Application\Security\PermissionRegistry;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(HelpFormatterContextAdapter::class)]
final class HelpFormatterContextAdapterIrcopTest extends TestCase
{
    private function createIrcopCommandStub(string $name, string $permission): NickServCommandInterface
    {
        return new class($name, $permission) implements NickServCommandInterface {
            public function __construct(
                private readonly string $name,
                private readonly string $permission,
            ) {
            }

            public function getName(): string
            {
                return $this->name;
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
                return $this->permission;
            }

            public function getHelpParams(): array
            {
                return [];
            }

            public function execute(NickServContext $context): void
            {
            }
        };
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

    private function createPermissionRegistry(array $permissions): PermissionRegistry
    {
        $provider = new class($permissions) implements PermissionProviderInterface {
            public function __construct(private array $perms)
            {
            }

            public function getServiceName(): string
            {
                return 'NickServ';
            }

            public function getPermissions(): array
            {
                return $this->perms;
            }
        };

        return new PermissionRegistry([$provider]);
    }

    #[Test]
    public function getIrcopCommandsReturnsAllIrcopCommandsForRootUser(): void
    {
        $cmd1 = $this->createIrcopCommandStub('USERIP', 'nickserv.userip');
        $cmd2 = $this->createIrcopCommandStub('INFO', 'nickserv.info');
        $registry = new NickServCommandRegistry([$cmd1, $cmd2]);
        $rootRegistry = new RootUserRegistry('RootAdmin');
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
        $permissionRegistry = $this->createPermissionRegistry(['nickserv.userip', 'nickserv.info']);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $context = new NickServContext(
            new SenderView('UID1', 'RootAdmin', 'i', 'h', 'c', 'ip', true, true),
            $account,
            'HELP',
            [],
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            $registry,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
        $adapter = new HelpFormatterContextAdapter(
            $context,
            $accessHelper,
            $rootRegistry,
            $permissionRegistry,
        );

        $commands = iterator_to_array($adapter->getIrcopCommands());

        self::assertCount(2, $commands);
        self::assertSame('USERIP', $commands[0]->getName());
        self::assertSame('INFO', $commands[1]->getName());
    }

    #[Test]
    public function getIrcopCommandsReturnsEmptyWhenNotIdentified(): void
    {
        $cmd = $this->createIrcopCommandStub('USERIP', 'nickserv.userip');
        $registry = new NickServCommandRegistry([$cmd]);
        $rootRegistry = new RootUserRegistry('');
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
        $permissionRegistry = $this->createPermissionRegistry(['nickserv.userip']);
        $context = new NickServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip', true, true),
            null,
            'HELP',
            [],
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            $registry,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
        $adapter = new HelpFormatterContextAdapter(
            $context,
            $accessHelper,
            $rootRegistry,
            $permissionRegistry,
        );

        $commands = iterator_to_array($adapter->getIrcopCommands());

        self::assertSame([], $commands);
    }

    #[Test]
    public function getIrcopCommandsReturnsEmptyWhenNotOper(): void
    {
        $cmd = $this->createIrcopCommandStub('USERIP', 'nickserv.userip');
        $registry = new NickServCommandRegistry([$cmd]);
        $rootRegistry = new RootUserRegistry('');
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
        $permissionRegistry = $this->createPermissionRegistry(['nickserv.userip']);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $context = new NickServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip', true, false),
            $account,
            'HELP',
            [],
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            $registry,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
        $adapter = new HelpFormatterContextAdapter(
            $context,
            $accessHelper,
            $rootRegistry,
            $permissionRegistry,
        );

        $commands = iterator_to_array($adapter->getIrcopCommands());

        self::assertSame([], $commands);
    }

    #[Test]
    public function getIrcopCommandsReturnsFilteredCommandsForOperWithPermissions(): void
    {
        $cmd1 = $this->createIrcopCommandStub('USERIP', 'nickserv.userip');
        $cmd2 = $this->createIrcopCommandStub('SOMEOTHER', 'nickserv.other');
        $registry = new NickServCommandRegistry([$cmd1, $cmd2]);
        $rootRegistry = new RootUserRegistry('');
        $role = $this->createStub(OperRole::class);
        $role->method('getId')->willReturn(10);
        $role->method('hasPermission')->willReturnCallback(static fn (string $perm): bool => 'nickserv.userip' === $perm);
        $ircop = $this->createStub(\App\Domain\OperServ\Entity\OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->expects(self::atLeastOnce())
            ->method('hasPermission')
            ->willReturnCallback(static fn (int $roleId, string $perm): bool => 10 === $roleId && 'nickserv.userip' === $perm);
        $accessHelper = new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
        $permissionRegistry = $this->createPermissionRegistry(['nickserv.userip', 'nickserv.other']);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $context = new NickServContext(
            new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true),
            $account,
            'HELP',
            [],
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            $registry,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
        $adapter = new HelpFormatterContextAdapter(
            $context,
            $accessHelper,
            $rootRegistry,
            $permissionRegistry,
        );

        $commands = iterator_to_array($adapter->getIrcopCommands());

        self::assertCount(1, $commands);
        self::assertSame('USERIP', $commands[0]->getName());
    }

    #[Test]
    public function hasIrcopAccessReturnsTrueForRootUser(): void
    {
        $rootRegistry = new RootUserRegistry('RootAdmin');
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
        $permissionRegistry = $this->createPermissionRegistry(['nickserv.userip']);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $context = new NickServContext(
            new SenderView('UID1', 'RootAdmin', 'i', 'h', 'c', 'ip', true, true),
            $account,
            'HELP',
            [],
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
        $adapter = new HelpFormatterContextAdapter(
            $context,
            $accessHelper,
            $rootRegistry,
            $permissionRegistry,
        );

        self::assertTrue($adapter->hasIrcopAccess());
    }

    #[Test]
    public function hasIrcopAccessReturnsTrueForOperWithPermissions(): void
    {
        $rootRegistry = new RootUserRegistry('');
        $role = $this->createStub(OperRole::class);
        $role->method('getId')->willReturn(10);
        $ircop = $this->createStub(\App\Domain\OperServ\Entity\OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->expects(self::atLeastOnce())
            ->method('hasPermission')
            ->willReturnCallback(static fn (int $roleId, string $perm): bool => 10 === $roleId && 'nickserv.userip' === $perm);
        $accessHelper = new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
        $permissionRegistry = $this->createPermissionRegistry(['nickserv.userip']);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $context = new NickServContext(
            new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true),
            $account,
            'HELP',
            [],
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
        $adapter = new HelpFormatterContextAdapter(
            $context,
            $accessHelper,
            $rootRegistry,
            $permissionRegistry,
        );

        self::assertTrue($adapter->hasIrcopAccess());
    }

    #[Test]
    public function hasIrcopAccessReturnsFalseForOperWithoutPermissions(): void
    {
        $rootRegistry = new RootUserRegistry('');
        $role = $this->createStub(OperRole::class);
        $role->method('getId')->willReturn(10);
        $ircop = $this->createStub(\App\Domain\OperServ\Entity\OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);
        $roleRepo = $this->createMock(OperRoleRepositoryInterface::class);
        $roleRepo->expects(self::atLeastOnce())
            ->method('hasPermission')
            ->willReturn(false);
        $accessHelper = new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
        $permissionRegistry = $this->createPermissionRegistry(['nickserv.userip']);
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $context = new NickServContext(
            new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip', true, true),
            $account,
            'HELP',
            [],
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
        $adapter = new HelpFormatterContextAdapter(
            $context,
            $accessHelper,
            $rootRegistry,
            $permissionRegistry,
        );

        self::assertFalse($adapter->hasIrcopAccess());
    }

    #[Test]
    public function shouldShowCommandInGeneralHelpReturnsFalseForIrcopPermission(): void
    {
        $command = $this->createIrcopCommandStub('USERIP', 'nickserv.userip');
        $context = new NickServContext(
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip', false, false),
            null,
            'HELP',
            [],
            $this->createStub(NickServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
        $rootRegistry = new RootUserRegistry('');
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $accessHelper = new IrcopAccessHelper($rootRegistry, $ircopRepo, $roleRepo);
        $permissionRegistry = $this->createPermissionRegistry([]);
        $adapter = new HelpFormatterContextAdapter(
            $context,
            $accessHelper,
            $rootRegistry,
            $permissionRegistry,
        );

        self::assertFalse($adapter->shouldShowCommandInGeneralHelp($command));
    }
}
