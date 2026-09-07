<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Security;

use App\Application\Security\PermissionProviderInterface;
use App\Bootstrap\Security\MemoServPermissionProvider;
use App\MemoServ\Application\Security\MemoServPermission;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(MemoServPermissionProvider::class)]
#[CoversClass(MemoServPermission::class)]
final class MemoServPermissionProviderTest extends TestCase
{
    #[Test]
    public function implementsPermissionProviderInterface(): void
    {
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertInstanceOf(PermissionProviderInterface::class, new MemoServPermissionProvider());
    }

    #[Test]
    public function getServiceNameReturnsMemoServ(): void
    {
        $permission = new MemoServPermissionProvider();

        self::assertSame('MemoServ', $permission->getServiceName());
    }

    #[Test]
    public function getPermissionsReturnsEmpty(): void
    {
        $permission = new MemoServPermissionProvider();

        self::assertSame([], $permission->getPermissions());
    }

    #[Test]
    public function constructorCanBeInvokedViaReflectionForCoverage(): void
    {
        $reflection = new ReflectionClass(MemoServPermission::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        $constructor->invoke($reflection->newInstanceWithoutConstructor());
        $this->addToAssertionCount(1);
    }
}
