<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Security;

use App\Bootstrap\Security\CRegServPermissionProvider;
use App\CRegServ\Application\Security\CRegServPermission;
use App\OperServ\Application\Security\PermissionProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CRegServPermissionProvider::class)]
#[CoversClass(CRegServPermission::class)]
final class CRegServPermissionProviderTest extends TestCase
{
    #[Test]
    public function implementsPermissionProviderInterface(): void
    {
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertInstanceOf(PermissionProviderInterface::class, new CRegServPermissionProvider());
    }

    #[Test]
    public function getServiceNameReturnsCRegServ(): void
    {
        $permission = new CRegServPermissionProvider();

        self::assertSame('CReG', $permission->getServiceName());
    }

    #[Test]
    public function getPermissionsReturnsTheIrcopPermissionSet(): void
    {
        $permission = new CRegServPermissionProvider();

        self::assertSame([CRegServPermission::APROBAR, CRegServPermission::DENEGAR, CRegServPermission::PENDIENTES, CRegServPermission::DENEGADOS, CRegServPermission::DROP, CRegServPermission::SAREGISTER], $permission->getPermissions());
    }
}
