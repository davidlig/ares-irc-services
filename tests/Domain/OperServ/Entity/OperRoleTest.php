<?php

declare(strict_types=1);

namespace App\Tests\Domain\OperServ\Entity;

use App\Domain\OperServ\Entity\OperPermission;
use App\Domain\OperServ\Entity\OperRole;
use Doctrine\Common\Collections\Collection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(OperRole::class)]
final class OperRoleTest extends TestCase
{
    #[Test]
    public function createSetsDefaultValues(): void
    {
        $role = OperRole::create('Admin', 'Admin role');

        self::assertSame('ADMIN', $role->getName());
        self::assertSame('Admin role', $role->getDescription());
        self::assertFalse($role->isProtected());
        self::assertCount(0, $role->getPermissions());
    }

    #[Test]
    public function createWithProtected(): void
    {
        $role = OperRole::create('Root', 'Root role', true);

        self::assertSame('ROOT', $role->getName());
        self::assertTrue($role->isProtected());
    }

    #[Test]
    public function createWithEmptyDescription(): void
    {
        $role = OperRole::create('Test');

        self::assertSame('TEST', $role->getName());
        self::assertSame('', $role->getDescription());
    }

    #[Test]
    public function createUpperCasesName(): void
    {
        $role = OperRole::create('myRole', 'desc');

        self::assertSame('MYROLE', $role->getName());
    }

    #[Test]
    public function getDescriptionReturnsValue(): void
    {
        $role = OperRole::create('Test', 'Initial description');

        self::assertSame('Initial description', $role->getDescription());
    }

    #[Test]
    public function setDescriptionUpdatesValue(): void
    {
        $role = OperRole::create('Test', 'Old description');

        $role->setDescription('New description');

        self::assertSame('New description', $role->getDescription());
    }

    #[Test]
    public function isProtectedReturnsCorrectValue(): void
    {
        $protected = OperRole::create('Protected', 'desc', true);
        $unprotected = OperRole::create('Unprotected', 'desc', false);

        self::assertTrue($protected->isProtected());
        self::assertFalse($unprotected->isProtected());
    }

    #[Test]
    public function getPermissionsReturnsEmptyCollectionInitially(): void
    {
        $role = OperRole::create('Test');

        $permissions = $role->getPermissions();

        self::assertInstanceOf(Collection::class, $permissions);
        self::assertCount(0, $permissions);
    }

    #[Test]
    public function addPermissionAddsToCollection(): void
    {
        $role = OperRole::create('Test');
        $permission = OperPermission::create('operserv.test', 'Test permission');

        $role->addPermission($permission);

        self::assertCount(1, $role->getPermissions());
        self::assertTrue($role->getPermissions()->contains($permission));
    }

    #[Test]
    public function addPermissionDoesNotDuplicate(): void
    {
        $role = OperRole::create('Test');
        $permission = OperPermission::create('operserv.test', 'Test permission');

        $role->addPermission($permission);
        $role->addPermission($permission);

        self::assertCount(1, $role->getPermissions());
    }

    #[Test]
    public function removePermissionRemovesFromCollection(): void
    {
        $role = OperRole::create('Test');
        $permission = OperPermission::create('operserv.test', 'Test permission');

        $role->addPermission($permission);
        self::assertCount(1, $role->getPermissions());

        $role->removePermission($permission);

        self::assertCount(0, $role->getPermissions());
        self::assertFalse($role->getPermissions()->contains($permission));
    }

    #[Test]
    public function hasPermissionReturnsTrueWhenPresent(): void
    {
        $role = OperRole::create('Test');
        $permission = OperPermission::create('operserv.admin.add', 'Add admin');

        $role->addPermission($permission);

        self::assertTrue($role->hasPermission('operserv.admin.add'));
    }

    #[Test]
    public function hasPermissionReturnsFalseWhenNotPresent(): void
    {
        $role = OperRole::create('Test');
        $permission = OperPermission::create('operserv.admin.add', 'Add admin');

        $role->addPermission($permission);

        self::assertFalse($role->hasPermission('operserv.admin.del'));
    }

    #[Test]
    public function hasPermissionReturnsFalseOnEmptyCollection(): void
    {
        $role = OperRole::create('Test');

        self::assertFalse($role->hasPermission('operserv.any'));
    }

    #[Test]
    public function hasPermissionIsCaseSensitive(): void
    {
        $role = OperRole::create('Test');
        $permission = OperPermission::create('operserv.ADMIN', 'Admin');

        $role->addPermission($permission);

        self::assertTrue($role->hasPermission('operserv.ADMIN'));
        self::assertFalse($role->hasPermission('operserv.admin'));
    }

    #[Test]
    public function multiplePermissions(): void
    {
        $role = OperRole::create('Test');
        $perm1 = OperPermission::create('operserv.admin.add', 'Add admin');
        $perm2 = OperPermission::create('operserv.admin.del', 'Delete admin');
        $perm3 = OperPermission::create('operserv.kill.local', 'Local kill');

        $role->addPermission($perm1);
        $role->addPermission($perm2);
        $role->addPermission($perm3);

        self::assertCount(3, $role->getPermissions());
        self::assertTrue($role->hasPermission('operserv.admin.add'));
        self::assertTrue($role->hasPermission('operserv.admin.del'));
        self::assertTrue($role->hasPermission('operserv.kill.local'));
        self::assertFalse($role->hasPermission('operserv.kill.global'));
    }

    #[Test]
    public function removePermissionOnEmptyCollectionDoesNotThrow(): void
    {
        $role = OperRole::create('Test');
        $permission = OperPermission::create('operserv.test', 'Test');

        $role->removePermission($permission);

        self::assertCount(0, $role->getPermissions());
    }

    #[Test]
    public function getIdReturnsValueSetByPersistence(): void
    {
        $role = OperRole::create('Test');

        $reflection = new ReflectionClass($role);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($role, 42);

        self::assertSame(42, $role->getId());
    }

    #[Test]
    public function addPermissionWithStub(): void
    {
        $role = OperRole::create('Test');

        $permission = $this->createStub(OperPermission::class);
        $permission->method('getName')->willReturn('operserv.stub');

        $role->addPermission($permission);

        self::assertCount(1, $role->getPermissions());
        self::assertTrue($role->hasPermission('operserv.stub'));
    }

    #[Test]
    public function hasPermissionWithStubReturnsFalseForDifferentName(): void
    {
        $role = OperRole::create('Test');

        $permission = $this->createStub(OperPermission::class);
        $permission->method('getName')->willReturn('operserv.stub');

        $role->addPermission($permission);

        self::assertFalse($role->hasPermission('operserv.other'));
    }

    #[Test]
    public function getUserModesReturnsEmptyArrayWhenNoModesSet(): void
    {
        $role = OperRole::create('Test');

        self::assertSame([], $role->getUserModes());
    }

    #[Test]
    public function getUserModesReturnsModesAfterSetUserModes(): void
    {
        $role = OperRole::create('Test');

        $role->setUserModes(['H', 'q']);

        self::assertSame(['H', 'q'], $role->getUserModes());
    }

    #[Test]
    public function setUserModesEmptyArrayStoresNull(): void
    {
        $role = OperRole::create('Test');
        $role->setUserModes(['H']);
        $role->setUserModes([]);

        self::assertSame([], $role->getUserModes());
    }

    #[Test]
    public function setUserModesDeduplicatesModes(): void
    {
        $role = OperRole::create('Test');

        $role->setUserModes(['H', 'q', 'H']);

        self::assertSame(['H', 'q'], $role->getUserModes());
    }

    #[Test]
    public function getUserModesPreservesOrder(): void
    {
        $role = OperRole::create('Test');

        $role->setUserModes(['z', 'a', 'H', 'q']);

        self::assertSame(['z', 'a', 'H', 'q'], $role->getUserModes());
    }
}
