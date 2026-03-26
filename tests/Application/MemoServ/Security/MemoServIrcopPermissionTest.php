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
    public function getPermissionsReturnsAllPermissions(): void
    {
        $permission = new MemoServIrcopPermission();

        $permissions = $permission->getPermissions();

        self::assertContains(MemoServIrcopPermission::SEND, $permissions);
        self::assertContains(MemoServIrcopPermission::DISABLE, $permissions);
        self::assertContains(MemoServIrcopPermission::ENABLE, $permissions);
        self::assertContains(MemoServIrcopPermission::READ, $permissions);
        self::assertContains(MemoServIrcopPermission::DELETE, $permissions);
    }

    #[Test]
    public function constantsHaveCorrectValues(): void
    {
        self::assertSame('MEMOSERV_SEND', MemoServIrcopPermission::SEND);
        self::assertSame('MEMOSERV_DISABLE', MemoServIrcopPermission::DISABLE);
        self::assertSame('MEMOSERV_ENABLE', MemoServIrcopPermission::ENABLE);
        self::assertSame('MEMOSERV_READ', MemoServIrcopPermission::READ);
        self::assertSame('MEMOSERV_DELETE', MemoServIrcopPermission::DELETE);
    }

    #[Test]
    public function allPermissionsAreUppercaseWithUnderscore(): void
    {
        $permission = new MemoServIrcopPermission();

        foreach ($permission->getPermissions() as $perm) {
            self::assertMatchesRegularExpression('/^[A-Z_]+$/', $perm);
        }
    }
}
