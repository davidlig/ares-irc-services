<?php

declare(strict_types=1);

namespace App\Tests\Domain\OperServ\Entity;

use App\Domain\OperServ\Entity\OperPermission;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(OperPermission::class)]
final class OperPermissionTest extends TestCase
{
    #[Test]
    public function createWithDefaultDescription(): void
    {
        $permission = OperPermission::create('operserv.test.permission');

        self::assertSame('operserv.test.permission', $permission->getName());
        self::assertSame('', $permission->getDescription());
    }

    #[Test]
    public function createWithDescription(): void
    {
        $permission = OperPermission::create('operserv.test.permission', 'Test permission description');

        self::assertSame('operserv.test.permission', $permission->getName());
        self::assertSame('Test permission description', $permission->getDescription());
    }

    #[Test]
    public function getNameReturnsPermissionName(): void
    {
        $permission = OperPermission::create('operserv.admin.add');

        self::assertSame('operserv.admin.add', $permission->getName());
    }

    #[Test]
    public function getDescriptionReturnsDescription(): void
    {
        $permission = OperPermission::create('operserv.kill', 'Kill users');

        self::assertSame('Kill users', $permission->getDescription());
    }

    #[Test]
    public function setDescriptionUpdatesDescription(): void
    {
        $permission = OperPermission::create('operserv.kline.add', 'Original description');

        $permission->setDescription('Updated description');

        self::assertSame('Updated description', $permission->getDescription());
    }

    #[Test]
    public function getIdReturnsValueSetByPersistence(): void
    {
        $permission = OperPermission::create('operserv.test');
        $reflection = new ReflectionClass($permission);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($permission, 123);

        self::assertSame(123, $permission->getId());
    }
}
