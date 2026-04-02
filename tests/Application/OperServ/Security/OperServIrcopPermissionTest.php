<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Security;

use App\Application\OperServ\Security\OperServIrcopPermission;
use App\Application\Security\PermissionProviderInterface;
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
    public function getPermissionsReturnsOperServPermissions(): void
    {
        $permission = new OperServIrcopPermission();

        self::assertSame(['operserv.kill', 'operserv.global'], $permission->getPermissions());
    }
}
