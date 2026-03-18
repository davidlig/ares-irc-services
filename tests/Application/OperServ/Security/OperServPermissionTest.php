<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Security;

use App\Application\OperServ\Security\OperServPermission;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function sprintf;

#[CoversClass(OperServPermission::class)]
final class OperServPermissionTest extends TestCase
{
    #[Test]
    public function adminAddConstantHasExpectedValue(): void
    {
        self::assertSame('operserv.admin.add', OperServPermission::ADMIN_ADD);
    }

    #[Test]
    public function adminDelConstantHasExpectedValue(): void
    {
        self::assertSame('operserv.admin.del', OperServPermission::ADMIN_DEL);
    }

    #[Test]
    public function adminListConstantHasExpectedValue(): void
    {
        self::assertSame('operserv.admin.list', OperServPermission::ADMIN_LIST);
    }

    #[Test]
    public function roleManageConstantHasExpectedValue(): void
    {
        self::assertSame('operserv.role.manage', OperServPermission::ROLE_MANAGE);
    }

    #[Test]
    public function permissionManageConstantHasExpectedValue(): void
    {
        self::assertSame('operserv.permission.manage', OperServPermission::PERMISSION_MANAGE);
    }

    #[Test]
    public function killLocalConstantHasExpectedValue(): void
    {
        self::assertSame('operserv.kill.local', OperServPermission::KILL_LOCAL);
    }

    #[Test]
    public function killGlobalConstantHasExpectedValue(): void
    {
        self::assertSame('operserv.kill.global', OperServPermission::KILL_GLOBAL);
    }

    #[Test]
    public function klineAddConstantHasExpectedValue(): void
    {
        self::assertSame('operserv.kline.add', OperServPermission::KLINE_ADD);
    }

    #[Test]
    public function klineDelConstantHasExpectedValue(): void
    {
        self::assertSame('operserv.kline.del', OperServPermission::KLINE_DEL);
    }

    #[Test]
    public function klineListConstantHasExpectedValue(): void
    {
        self::assertSame('operserv.kline.list', OperServPermission::KLINE_LIST);
    }

    #[Test]
    public function glineAddConstantHasExpectedValue(): void
    {
        self::assertSame('operserv.gline.add', OperServPermission::GLINE_ADD);
    }

    #[Test]
    public function glineDelConstantHasExpectedValue(): void
    {
        self::assertSame('operserv.gline.del', OperServPermission::GLINE_DEL);
    }

    #[Test]
    public function glineListConstantHasExpectedValue(): void
    {
        self::assertSame('operserv.gline.list', OperServPermission::GLINE_LIST);
    }

    #[Test]
    public function userinfoConstantHasExpectedValue(): void
    {
        self::assertSame('operserv.userinfo', OperServPermission::USERINFO);
    }

    #[Test]
    public function channelinfoConstantHasExpectedValue(): void
    {
        self::assertSame('operserv.channelinfo', OperServPermission::CHANNELINFO);
    }

    #[Test]
    public function networkViewConstantHasExpectedValue(): void
    {
        self::assertSame('operserv.network.view', OperServPermission::NETWORK_VIEW);
    }

    #[Test]
    public function allConstantsAreStrings(): void
    {
        $reflection = new ReflectionClass(OperServPermission::class);
        $constants = $reflection->getReflectionConstants();

        foreach ($constants as $constant) {
            if ($constant->isPublic()) {
                self::assertIsString($constant->getValue(), sprintf('Constant %s should be a string', $constant->getName()));
            }
        }
    }

    #[Test]
    public function constructorIsPrivate(): void
    {
        $reflection = new ReflectionClass(OperServPermission::class);
        $constructor = $reflection->getConstructor();

        self::assertTrue($constructor->isPrivate(), 'Constructor should be private');
    }

    #[Test]
    public function cannotBeInstantiated(): void
    {
        $reflection = new ReflectionClass(OperServPermission::class);
        $constructor = $reflection->getConstructor();

        self::assertTrue($constructor->isPrivate(), 'Class should not be instantiable');

        $constructor->setAccessible(true);
        $instance = $reflection->newInstanceWithoutConstructor();
        $constructor->invoke($instance);

        self::assertInstanceOf(OperServPermission::class, $instance);
    }
}
