<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Security;

use App\Application\Security\PermissionProviderInterface;
use App\Bootstrap\Security\NickServPermissionProvider;
use App\NickServ\Application\Security\NickServPermission;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickServPermissionProvider::class)]
#[CoversClass(NickServPermission::class)]
final class NickServPermissionProviderTest extends TestCase
{
    #[Test]
    public function implementsPermissionProviderInterface(): void
    {
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertInstanceOf(PermissionProviderInterface::class, new NickServPermissionProvider());
    }

    #[Test]
    public function getServiceNameReturnsNickServ(): void
    {
        $permission = new NickServPermissionProvider();

        self::assertSame('NickServ', $permission->getServiceName());
    }

    #[Test]
    public function getPermissionsReturnsConfiguredPermissions(): void
    {
        $permission = new NickServPermissionProvider();

        self::assertSame([NickServPermission::USERIP, NickServPermission::SUSPEND, NickServPermission::RENAME, NickServPermission::DROP, NickServPermission::DROP_FORCE, NickServPermission::RESTORE, NickServPermission::FORBID, NickServPermission::FORBIDVHOST, NickServPermission::SASET, NickServPermission::NOEXPIRE, NickServPermission::HISTORY], $permission->getPermissions());
    }
}
