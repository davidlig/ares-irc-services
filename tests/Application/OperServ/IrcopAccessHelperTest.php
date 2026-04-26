<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ;

use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcopAccessHelper::class)]
final class IrcopAccessHelperTest extends TestCase
{
    #[Test]
    public function isRootDelegatesToRootUserRegistry(): void
    {
        $rootRegistry = new RootUserRegistry('Admin');
        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepository = $this->createStub(OperRoleRepositoryInterface::class);

        $helper = new IrcopAccessHelper($rootRegistry, $ircopRepository, $roleRepository);

        self::assertTrue($helper->isRoot('Admin'));
        self::assertFalse($helper->isRoot('SomeUser'));
    }

    #[Test]
    public function getIrcopByNickIdReturnsNullWhenNotFound(): void
    {
        $rootRegistry = new RootUserRegistry('');
        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepository = $this->createStub(OperRoleRepositoryInterface::class);

        $ircopRepository->method('findByNickId')->willReturn(null);

        $helper = new IrcopAccessHelper($rootRegistry, $ircopRepository, $roleRepository);

        self::assertNull($helper->getIrcopByNickId(999));
    }

    #[Test]
    public function getIrcopByNickIdReturnsOperIrcopWhenFound(): void
    {
        $rootRegistry = new RootUserRegistry('');
        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepository = $this->createStub(OperRoleRepositoryInterface::class);

        $role = $this->createStub(OperRole::class);
        $ircop = OperIrcop::create(nickId: 42, role: $role);

        $ircopRepository->method('findByNickId')->willReturn($ircop);

        $helper = new IrcopAccessHelper($rootRegistry, $ircopRepository, $roleRepository);

        $result = $helper->getIrcopByNickId(42);

        self::assertNotNull($result);
        self::assertSame(42, $result->getNickId());
    }

    #[Test]
    public function hasPermissionReturnsTrueForRootUsers(): void
    {
        $rootRegistry = new RootUserRegistry('Admin');
        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepository = $this->createStub(OperRoleRepositoryInterface::class);

        $helper = new IrcopAccessHelper($rootRegistry, $ircopRepository, $roleRepository);

        self::assertTrue($helper->hasPermission(100, 'admin', 'operserv.any.permission'));
    }

    #[Test]
    public function hasPermissionReturnsFalseForNonRootWithoutIrcop(): void
    {
        $rootRegistry = new RootUserRegistry('');
        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepository = $this->createStub(OperRoleRepositoryInterface::class);

        $ircopRepository->method('findByNickId')->willReturn(null);

        $helper = new IrcopAccessHelper($rootRegistry, $ircopRepository, $roleRepository);

        self::assertFalse($helper->hasPermission(100, 'regularuser', 'operserv.admin.add'));
    }

    #[Test]
    public function hasPermissionReturnsTrueWhenRoleHasPermission(): void
    {
        $rootRegistry = new RootUserRegistry('');
        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepository = $this->createStub(OperRoleRepositoryInterface::class);

        $role = $this->createStub(OperRole::class);
        $role->method('getId')->willReturn(5);

        $ircop = OperIrcop::create(nickId: 100, role: $role);

        $ircopRepository->method('findByNickId')->willReturn($ircop);
        $roleRepository->method('hasPermission')->willReturn(true);

        $helper = new IrcopAccessHelper($rootRegistry, $ircopRepository, $roleRepository);

        self::assertTrue($helper->hasPermission(100, 'operator', 'operserv.admin.add'));
    }

    #[Test]
    public function hasPermissionReturnsFalseWhenRoleLacksPermission(): void
    {
        $rootRegistry = new RootUserRegistry('');
        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepository = $this->createStub(OperRoleRepositoryInterface::class);

        $role = $this->createStub(OperRole::class);
        $role->method('getId')->willReturn(5);

        $ircop = OperIrcop::create(nickId: 100, role: $role);

        $ircopRepository->method('findByNickId')->willReturn($ircop);
        $roleRepository->method('hasPermission')->willReturn(false);

        $helper = new IrcopAccessHelper($rootRegistry, $ircopRepository, $roleRepository);

        self::assertFalse($helper->hasPermission(100, 'operator', 'operserv.admin.add'));
    }

    #[Test]
    public function hasAnyPermissionReturnsTrueIfAnyPermissionMatches(): void
    {
        $rootRegistry = new RootUserRegistry('');
        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepository = $this->createStub(OperRoleRepositoryInterface::class);

        $role = $this->createStub(OperRole::class);
        $role->method('getId')->willReturn(5);

        $ircop = OperIrcop::create(nickId: 100, role: $role);

        $ircopRepository->method('findByNickId')->willReturn($ircop);

        $roleRepository->method('hasPermission')
            ->willReturnMap([
                [5, 'operserv.admin.add', false],
                [5, 'operserv.admin.del', true],
                [5, 'operserv.kill', false],
            ]);

        $helper = new IrcopAccessHelper($rootRegistry, $ircopRepository, $roleRepository);

        self::assertTrue($helper->hasAnyPermission(100, 'operator', [
            'operserv.admin.add',
            'operserv.admin.del',
            'operserv.kill',
        ]));
    }

    #[Test]
    public function hasAnyPermissionReturnsFalseIfNoPermissionsMatch(): void
    {
        $rootRegistry = new RootUserRegistry('');
        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepository = $this->createStub(OperRoleRepositoryInterface::class);

        $role = $this->createStub(OperRole::class);
        $role->method('getId')->willReturn(5);

        $ircop = OperIrcop::create(nickId: 100, role: $role);

        $ircopRepository->method('findByNickId')->willReturn($ircop);
        $roleRepository->method('hasPermission')->willReturn(false);

        $helper = new IrcopAccessHelper($rootRegistry, $ircopRepository, $roleRepository);

        self::assertFalse($helper->hasAnyPermission(100, 'operator', [
            'operserv.admin.add',
            'operserv.admin.del',
        ]));
    }

    #[Test]
    public function hasAnyPermissionReturnsTrueForRootWithoutCheckingPermissions(): void
    {
        $rootRegistry = new RootUserRegistry('Admin');
        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepository = $this->createStub(OperRoleRepositoryInterface::class);

        $helper = new IrcopAccessHelper($rootRegistry, $ircopRepository, $roleRepository);

        self::assertTrue($helper->hasAnyPermission(100, 'admin', [
            'operserv.admin.add',
            'operserv.admin.del',
        ]));
    }

    #[Test]
    public function getRoleNameReturnsRootForRootUsers(): void
    {
        $rootRegistry = new RootUserRegistry('Admin');
        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepository = $this->createStub(OperRoleRepositoryInterface::class);

        $helper = new IrcopAccessHelper($rootRegistry, $ircopRepository, $roleRepository);

        self::assertSame('ROOT', $helper->getRoleName(100, 'admin'));
    }

    #[Test]
    public function getRoleNameReturnsRoleNameForIrcop(): void
    {
        $rootRegistry = new RootUserRegistry('');
        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepository = $this->createStub(OperRoleRepositoryInterface::class);

        $role = $this->createStub(OperRole::class);
        $role->method('getName')->willReturn('OPERATOR');

        $ircop = OperIrcop::create(nickId: 100, role: $role);

        $ircopRepository->method('findByNickId')->willReturn($ircop);

        $helper = new IrcopAccessHelper($rootRegistry, $ircopRepository, $roleRepository);

        self::assertSame('OPERATOR', $helper->getRoleName(100, 'someuser'));
    }

    #[Test]
    public function getRoleNameReturnsNullForNonIrcop(): void
    {
        $rootRegistry = new RootUserRegistry('');
        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepository = $this->createStub(OperRoleRepositoryInterface::class);

        $ircopRepository->method('findByNickId')->willReturn(null);

        $helper = new IrcopAccessHelper($rootRegistry, $ircopRepository, $roleRepository);

        self::assertNull($helper->getRoleName(100, 'regularuser'));
    }

    #[Test]
    public function hasPermissionChecksRootWithNickLower(): void
    {
        $rootRegistry = new RootUserRegistry('Admin');
        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepository = $this->createStub(OperRoleRepositoryInterface::class);

        $helper = new IrcopAccessHelper($rootRegistry, $ircopRepository, $roleRepository);

        self::assertTrue($helper->hasPermission(999, 'admin', 'operserv.any.permission'));
        self::assertFalse($helper->hasPermission(999, 'otheruser', 'operserv.any.permission'));
    }

    #[Test]
    public function isIrcopReturnsTrueForRootUser(): void
    {
        $rootRegistry = new RootUserRegistry('Admin');
        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepository = $this->createStub(OperRoleRepositoryInterface::class);

        $helper = new IrcopAccessHelper($rootRegistry, $ircopRepository, $roleRepository);

        self::assertTrue($helper->isIrcop(999, 'admin'));
    }

    #[Test]
    public function isIrcopReturnsTrueForRegisteredIrcop(): void
    {
        $rootRegistry = new RootUserRegistry('');
        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepository = $this->createStub(OperRoleRepositoryInterface::class);

        $role = $this->createStub(OperRole::class);
        $ircop = OperIrcop::create(nickId: 100, role: $role);

        $ircopRepository->method('findByNickId')->willReturn($ircop);

        $helper = new IrcopAccessHelper($rootRegistry, $ircopRepository, $roleRepository);

        self::assertTrue($helper->isIrcop(100, 'operator'));
    }

    #[Test]
    public function isIrcopReturnsFalseForNonRootNonIrcop(): void
    {
        $rootRegistry = new RootUserRegistry('');
        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $roleRepository = $this->createStub(OperRoleRepositoryInterface::class);

        $ircopRepository->method('findByNickId')->willReturn(null);

        $helper = new IrcopAccessHelper($rootRegistry, $ircopRepository, $roleRepository);

        self::assertFalse($helper->isIrcop(999, 'regularuser'));
    }
}
