<?php

declare(strict_types=1);

namespace App\Tests\Application\MemoServ\Security;

use App\Application\MemoServ\Security\MemoServIrcopPermission;
use App\Application\Security\PermissionProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(MemoServIrcopPermission::class)]
final class MemoServIrcopPermissionTest extends TestCase
{
    #[Test]
    public function implementsPermissionProviderInterface(): void
    {
        $permission = new MemoServIrcopPermission();

        self::assertInstanceOf(PermissionProviderInterface::class, $permission);
    }

    #[Test]
    public function getServiceNameReturnsMemoServ(): void
    {
        $permission = new MemoServIrcopPermission();

        self::assertSame('MemoServ', $permission->getServiceName());
    }

    #[Test]
    public function getPermissionsReturnsEmptyArrayUntilCommandsAreImplemented(): void
    {
        $permission = new MemoServIrcopPermission();

        self::assertSame([], $permission->getPermissions());
    }
}
