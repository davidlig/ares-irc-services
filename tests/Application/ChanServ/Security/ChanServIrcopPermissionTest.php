<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Security;

use App\Application\ChanServ\Security\ChanServIrcopPermission;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\Security\PermissionProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServIrcopPermission::class)]
final class ChanServIrcopPermissionTest extends TestCase
{
    #[Test]
    public function implementsPermissionProviderInterface(): void
    {
        $permission = new ChanServIrcopPermission();

        self::assertInstanceOf(PermissionProviderInterface::class, $permission);
    }

    #[Test]
    public function getServiceNameReturnsChanServ(): void
    {
        $permission = new ChanServIrcopPermission();

        self::assertSame('ChanServ', $permission->getServiceName());
    }

    #[Test]
    public function getPermissionsReturnsDefinedPermissions(): void
    {
        $permission = new ChanServIrcopPermission();

        self::assertSame([ChanServPermission::DROP, ChanServPermission::SUSPEND, ChanServPermission::FORBID], $permission->getPermissions());
    }
}
