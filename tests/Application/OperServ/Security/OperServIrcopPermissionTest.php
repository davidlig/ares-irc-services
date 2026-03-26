<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Security;

use App\Application\OperServ\Security\OperServIrcopPermission;
use App\Application\Security\PermissionProviderInterface;
use App\Domain\OperServ\Entity\OperPermission;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(OperServIrcopPermission::class)]
final class OperServIrcopPermissionTest extends TestCase
{
    #[Test]
    public function implementsPermissionProviderInterface(): void
    {
        $permission = new OperServIrcopPermission();

        self::assertInstanceOf(PermissionProviderInterface::class, $permission);
    }

    #[Test]
    public function getServiceNameReturnsOperServ(): void
    {
        $permission = new OperServIrcopPermission();

        self::assertSame('OperServ', $permission->getServiceName());
    }

    #[Test]
    public function getPermissionsReturnsAllPermissions(): void
    {
        $permission = new OperServIrcopPermission();

        $permissions = $permission->getPermissions();

        self::assertContains(OperPermission::ADMIN_ADD, $permissions);
        self::assertContains(OperPermission::ADMIN_DEL, $permissions);
        self::assertContains(OperPermission::ADMIN_LIST, $permissions);
        self::assertContains(OperPermission::ROLE_MANAGE, $permissions);
        self::assertContains(OperPermission::PERMISSION_MANAGE, $permissions);
        self::assertContains(OperPermission::KILL_LOCAL, $permissions);
        self::assertContains(OperPermission::KILL_GLOBAL, $permissions);
    }

    #[Test]
    public function getPermissionsUsesOperPermissionConstants(): void
    {
        $permission = new OperServIrcopPermission();

        $permissions = $permission->getPermissions();

        self::assertContains('operserv.admin.add', $permissions);
        self::assertContains('operserv.kill.local', $permissions);
        self::assertContains('operserv.gline.list', $permissions);
    }

    #[Test]
    public function permissionsAreFromOperPermissionEntity(): void
    {
        $permission = new OperServIrcopPermission();

        $permissions = $permission->getPermissions();

        foreach ($permissions as $perm) {
            self::assertTrue(
                str_starts_with($perm, 'operserv.'),
                "Permission '{$perm}' should start with 'operserv.'"
            );
        }
    }
}
