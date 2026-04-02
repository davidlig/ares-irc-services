<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Security;

use App\Application\NickServ\Security\NickServIrcopPermission;
use App\Application\Security\PermissionProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickServIrcopPermission::class)]
final class NickServIrcopPermissionTest extends TestCase
{
    #[Test]
    public function implementsPermissionProviderInterface(): void
    {
        $permission = new NickServIrcopPermission();

        self::assertInstanceOf(PermissionProviderInterface::class, $permission);
    }

    #[Test]
    public function getServiceNameReturnsNickServ(): void
    {
        $permission = new NickServIrcopPermission();

        self::assertSame('NickServ', $permission->getServiceName());
    }

    #[Test]
    public function getPermissionsReturnsConfiguredPermissions(): void
    {
        $permission = new NickServIrcopPermission();

        self::assertSame(['nickserv.userip'], $permission->getPermissions());
    }
}
