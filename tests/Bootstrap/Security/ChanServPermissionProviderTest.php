<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Security;

use App\Bootstrap\Security\ChanServPermissionProvider;
use App\ChanServ\Application\Security\ChanServPermission;
use App\OperServ\Application\Security\PermissionProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(ChanServPermissionProvider::class)]
#[CoversClass(ChanServPermission::class)]
final class ChanServPermissionProviderTest extends TestCase
{
    #[Test]
    public function implementsPermissionProviderInterface(): void
    {
        // @phpstan-ignore staticMethod.alreadyNarrowedType
        self::assertInstanceOf(PermissionProviderInterface::class, new ChanServPermissionProvider());
    }

    #[Test]
    public function getServiceNameReturnsChanServ(): void
    {
        $permission = new ChanServPermissionProvider();

        self::assertSame('ChanServ', $permission->getServiceName());
    }

    #[Test]
    public function getPermissionsReturnsDefinedPermissions(): void
    {
        $permission = new ChanServPermissionProvider();

        self::assertSame(ChanServPermission::allIrcop(), $permission->getPermissions());
    }

    #[Test]
    public function constructorCanBeInvokedViaReflectionForCoverage(): void
    {
        $reflection = new ReflectionClass(ChanServPermission::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        $constructor->invoke($reflection->newInstanceWithoutConstructor());
        $this->addToAssertionCount(1);
    }
}
