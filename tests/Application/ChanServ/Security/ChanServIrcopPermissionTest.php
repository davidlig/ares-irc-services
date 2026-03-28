<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Security;

use App\Application\ChanServ\Security\ChanServIrcopPermission;
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
    public function getPermissionsReturnsEmptyArrayUntilCommandsAreImplemented(): void
    {
        $permission = new ChanServIrcopPermission();

        $permissions = $permission->getPermissions();

        self::assertSame([], $permissions);
    }

    #[Test]
    public function constantsHaveCorrectValues(): void
    {
        self::assertSame('CHANSERV_DROP', ChanServIrcopPermission::DROP);
        self::assertSame('CHANSERV_SUSPEND', ChanServIrcopPermission::SUSPEND);
        self::assertSame('CHANSERV_UNSUSPEND', ChanServIrcopPermission::UNSUSPEND);
        self::assertSame('CHANSERV_CLOSE', ChanServIrcopPermission::CLOSE);
        self::assertSame('CHANSERV_UNCLOSE', ChanServIrcopPermission::UNCLOSE);
    }
}
