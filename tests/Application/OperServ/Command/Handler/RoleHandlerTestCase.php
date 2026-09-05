<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\NickServ\VhostDisplayResolver;
use App\Application\NickServ\VhostValidator;
use App\Application\OperServ\Command\Handler\RoleCommand;
use App\Application\OperServ\Command\Handler\RoleModesHandler;
use App\Application\OperServ\Command\Handler\RoleOperclassHandler;
use App\Application\OperServ\Command\Handler\RolePermissionsHandler;
use App\Application\OperServ\Command\Handler\RoleVhostHandler;
use App\Application\OperServ\Command\OperServCommandRegistry;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\ForcedVhostApplier;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\IrcopModeApplier;
use App\Application\OperServ\IrcopOperclassApplier;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\EventBusInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\OperclassServiceActionsInterface;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\SenderView;
use App\Application\Port\TranslationInterface;
use App\Application\Port\UserModeSupportInterface;
use App\Application\Security\PermissionRegistry;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperPermissionRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\Tests\Application\OperServ\RecordingOperclassActions;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

abstract class RoleHandlerTestCase extends TestCase
{
    protected function createAccessHelper(bool $isRoot): IrcopAccessHelper
    {
        $rootUsers = $isRoot ? 'TestUser' : '';
        $rootRegistry = new RootUserRegistry($rootUsers);
        $adminRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);

        return new IrcopAccessHelper($rootRegistry, $adminRepo, $roleRepo);
    }

    protected function createCmd(
        OperRoleRepositoryInterface $roleRepo,
        OperPermissionRepositoryInterface $permRepo,
        IrcopAccessHelper $accessHelper,
        PermissionRegistry $permissionRegistry,
        array $ircOpModes = ['o', 'a', 'N', 'O'],
        ?EventBusInterface $eventDispatcher = null,
        bool $supportsOperclass = false,
        ?OperclassServiceActionsInterface $operclassActions = null,
    ): RoleCommand {
        $userModeSupport = $this->createStub(UserModeSupportInterface::class);
        $userModeSupport->method('getIrcOpUserModes')->willReturn($ircOpModes);

        $protocolModule = $this->createStub(ProtocolModuleInterface::class);
        $protocolModule->method('getUserModeSupport')->willReturn($userModeSupport);
        if (null !== $operclassActions) {
            $protocolModule->method('getServiceActions')->willReturn($operclassActions);
        } elseif ($supportsOperclass) {
            $protocolModule->method('getServiceActions')->willReturn(
                new RecordingOperclassActions(),
            );
        }

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($protocolModule);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $modeApplier = new IrcopModeApplier(
            new IdentifiedSessionRegistry(),
            $connectionHolder,
            $ircopRepo,
            $nickRepo,
            $this->createStub(NetworkUserLookupPort::class),
            new NullLogger(),
        );

        $notifier = $this->createStub(NickServNotifierInterface::class);
        $vhostApplier = new ForcedVhostApplier(
            $ircopRepo,
            $nickRepo,
            new IdentifiedSessionRegistry(),
            $notifier,
            $this->createStub(NetworkUserLookupPort::class),
            $connectionHolder,
            new VhostDisplayResolver(),
            new NullLogger(),
        );

        return new RoleCommand(
            $roleRepo,
            new RolePermissionsHandler($roleRepo, $permRepo, $permissionRegistry),
            new RoleOperclassHandler(
                $roleRepo,
                $connectionHolder,
                new IrcopOperclassApplier(new IdentifiedSessionRegistry(), $connectionHolder, $ircopRepo, $nickRepo),
            ),
            new RoleModesHandler($roleRepo, $connectionHolder, $modeApplier),
            new RoleVhostHandler(
                $roleRepo,
                $vhostApplier,
                new VhostValidator('virtual'),
                $eventDispatcher ?? $this->createStub(EventBusInterface::class),
            ),
            $accessHelper,
        );
    }

    protected function createContext(
        ?SenderView $sender,
        array $args,
        OperServNotifierInterface $notifier,
        TranslationInterface $translator,
        OperServCommandRegistry $registry,
        IrcopAccessHelper $accessHelper,
    ): OperServContext {
        return new OperServContext(
            $sender,
            null,
            'ROLE',
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

    protected function createServiceNicks(): ServiceNicknameRegistry
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
}
