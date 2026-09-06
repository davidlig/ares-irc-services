<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\Security;

use App\Application\Security\PermissionProviderInterface;
use App\NickServ\Application\Security\NickServIrcopPermission;
use App\NickServ\Application\Security\NickServPermission;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickServIrcopPermission::class)]
final class NickServIrcopPermissionTest extends TestCase
{
    #[Test]
    public function implementsPermissionProviderInterface(): void
    {
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertInstanceOf(PermissionProviderInterface::class, new NickServIrcopPermission());
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

        self::assertSame([NickServPermission::USERIP, NickServPermission::SUSPEND, NickServPermission::RENAME, NickServPermission::DROP, NickServPermission::DROP_FORCE, NickServPermission::RESTORE, NickServPermission::FORBID, NickServPermission::FORBIDVHOST, NickServPermission::SASET, NickServPermission::HISTORY], $permission->getPermissions());
    }
}
