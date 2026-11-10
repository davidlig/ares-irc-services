<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Projection;

use App\OperServ\Adapter\Out\Projection\DoctrineOperatorRoleStore;
use App\OperServ\Application\Port\Out\OperatorPermissionCache as OperatorPermissionCachePort;
use App\OperServ\Application\Port\Out\OperatorRoleRecord;
use App\OperServ\Domain\Entity\OperIrcop;
use App\OperServ\Domain\Entity\OperPermission;
use App\OperServ\Domain\Entity\OperRole;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
use App\OperServ\Domain\Repository\OperPermissionRepositoryInterface;
use App\OperServ\Domain\Repository\OperRoleRepositoryInterface;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function array_map;

#[CoversClass(DoctrineOperatorRoleStore::class)]
#[CoversClass(OperatorRoleRecord::class)]
final class DoctrineOperatorRoleStoreTest extends TestCase
{
    #[Test]
    public function findsAndListsCompleteRoleRecords(): void
    {
        $role = self::role(7, 'ADMIN', 'Network administrators');
        $role->addPermission(OperPermission::create('operserv.raw'));
        $role->changeUserModes(['H', 'W']);
        $role->changeForcedVhostPattern('staff.example.net');
        $role->changeOperclass('netadmin');
        $roles = $this->createStub(OperRoleRepositoryInterface::class);
        $roles->method('findByName')->willReturn($role);
        $roles->method('findAll')->willReturn([$role]);
        $store = $this->store($roles, $this->createStub(OperPermissionRepositoryInterface::class));
        $found = $store->findByName('ADMIN');
        self::assertInstanceOf(OperatorRoleRecord::class, $found);
        self::assertRoleRecord($found);

        $all = $store->all();
        self::assertCount(1, $all);
        self::assertRoleRecord($all[0]);
    }

    #[Test]
    public function preservesAMissingRole(): void
    {
        $roles = $this->createStub(OperRoleRepositoryInterface::class);

        self::assertNull($this->store(
            $roles,
            $this->createStub(OperPermissionRepositoryInterface::class),
        )->findByName('MISSING'));
    }

    #[Test]
    public function createsPersistsAndReturnsANewRole(): void
    {
        $roles = $this->createMock(OperRoleRepositoryInterface::class);
        $roles->expects(self::once())->method('save')->willReturnCallback(static function (OperRole $role): void {
            new ReflectionProperty(OperRole::class, 'id')->setValue($role, 9);
        });
        $store = $this->store($roles, $this->createStub(OperPermissionRepositoryInterface::class));

        $created = $store->create('helper', 'Help operators');

        self::assertSame(9, $created->id);
        self::assertSame('HELPER', $created->name);
        self::assertSame('Help operators', $created->description);
        self::assertFalse($created->protected);
        self::assertSame([], $created->permissions);
        self::assertSame([], $created->userModes);
        self::assertNull($created->forcedVhostPattern);
        self::assertNull($created->operclass);
    }

    #[Test]
    public function removesOnlyAnExistingRole(): void
    {
        $role = self::role(7, 'ADMIN');
        $roles = $this->createMock(OperRoleRepositoryInterface::class);
        $roles->expects(self::exactly(2))->method('findByName')->willReturnMap([
            ['ADMIN', $role],
            ['MISSING', null],
        ]);
        $roles->expects(self::once())->method('remove')->with($role);
        $store = $this->store($roles, $this->createStub(OperPermissionRepositoryInterface::class));

        $store->remove('ADMIN');
        $store->remove('MISSING');
    }

    #[Test]
    public function createsAndPersistsAMissingPermissionBeforeSavingTheRoleAssociation(): void
    {
        $role = self::role(7, 'ADMIN');
        $operations = [];
        $roles = $this->createMock(OperRoleRepositoryInterface::class);
        $roles->expects(self::once())->method('findByName')->with('ADMIN')->willReturn($role);
        $roles->expects(self::once())->method('save')->with($role)->willReturnCallback(static function () use (&$operations): void {
            $operations[] = 'role';
        });
        $permissions = $this->createMock(OperPermissionRepositoryInterface::class);
        $permissions->expects(self::once())->method('findByName')->with('operserv.global')->willReturn(null);
        $permissions->expects(self::once())->method('save')->willReturnCallback(static function (OperPermission $permission) use (&$operations): void {
            self::assertSame('operserv.global', $permission->getName());
            $operations[] = 'permission';
        });

        $this->store($roles, $permissions)->setPermissions('ADMIN', ['operserv.global']);

        self::assertSame(['permission', 'role'], $operations);
        self::assertSame(['operserv.global'], array_map(
            static fn (OperPermission $permission): string => $permission->getName(),
            $role->getPermissions(),
        ));
    }

    #[Test]
    public function reusesAnExistingPermissionWithoutPersistingItAgain(): void
    {
        $role = self::role(7, 'ADMIN');
        $permission = OperPermission::create('operserv.kill');
        $roles = $this->createMock(OperRoleRepositoryInterface::class);
        $roles->expects(self::once())->method('findByName')->with('ADMIN')->willReturn($role);
        $roles->expects(self::once())->method('save')->with($role);
        $permissions = $this->createMock(OperPermissionRepositoryInterface::class);
        $permissions->expects(self::once())->method('findByName')->with('operserv.kill')->willReturn($permission);
        $permissions->expects(self::never())->method('save');

        $this->store($roles, $permissions)->setPermissions('ADMIN', ['operserv.kill']);

        self::assertSame([$permission], $role->getPermissions());
    }

    #[Test]
    public function replacesPreviouslyAssociatedPermissions(): void
    {
        $role = self::role(7, 'ADMIN');
        $old = OperPermission::create('operserv.kill');
        $new = OperPermission::create('operserv.raw');
        $role->addPermission($old);
        $roles = $this->createMock(OperRoleRepositoryInterface::class);
        $roles->expects(self::once())->method('findByName')->with('ADMIN')->willReturn($role);
        $roles->expects(self::once())->method('save')->with($role);
        $permissions = $this->createStub(OperPermissionRepositoryInterface::class);
        $permissions->method('findByName')->willReturn($new);

        $this->store($roles, $permissions)->setPermissions('ADMIN', ['operserv.raw']);

        self::assertSame([$new], $role->getPermissions());
    }

    #[Test]
    public function persistsEachMutableRoleProjectionSetting(): void
    {
        $role = self::role(7, 'ADMIN');
        $roles = $this->createMock(OperRoleRepositoryInterface::class);
        $roles->expects(self::exactly(3))->method('findByName')->with('ADMIN')->willReturn($role);
        $roles->expects(self::exactly(3))->method('save')->with($role);
        $store = $this->store($roles, $this->createStub(OperPermissionRepositoryInterface::class));

        $store->setUserModes('ADMIN', ['H', 'W']);
        $store->setForcedVhostPattern('ADMIN', 'staff.example.net');
        $store->setOperclass('ADMIN', 'netadmin');

        self::assertSame(['H', 'W'], $role->getUserModes());
        self::assertSame('staff.example.net', $role->getForcedVhostPattern());
        self::assertSame('netadmin', $role->getOperclass());
    }

    #[Test]
    public function invalidatesPermissionSnapshotsAfterChangingRolePermissions(): void
    {
        $role = self::role(7, 'ADMIN');
        $operator = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 42, $role);
        $roles = $this->createMock(OperRoleRepositoryInterface::class);
        $roles->expects(self::once())->method('findByName')->with('ADMIN')->willReturn($role);
        $roles->expects(self::once())->method('save')->with($role);
        $operators = $this->createMock(OperIrcopRepositoryInterface::class);
        $operators->expects(self::once())->method('findByRoleId')->with(7)->willReturn([$operator]);
        $cache = $this->createMock(OperatorPermissionCachePort::class);
        $cache->expects(self::once())->method('invalidate')->with(42);
        $store = new DoctrineOperatorRoleStore($roles, $this->createStub(OperPermissionRepositoryInterface::class), $operators, $cache);

        $store->setPermissions('ADMIN', []);
    }

    #[Test]
    public function invalidatesPermissionSnapshotsBeforeRemovingARole(): void
    {
        $role = self::role(7, 'ADMIN');
        $operator = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 42, $role);
        $roles = $this->createMock(OperRoleRepositoryInterface::class);
        $roles->expects(self::once())->method('findByName')->with('ADMIN')->willReturn($role);
        $operators = $this->createMock(OperIrcopRepositoryInterface::class);
        $operators->expects(self::once())->method('findByRoleId')->with(7)->willReturn([$operator]);
        $cache = $this->createMock(OperatorPermissionCachePort::class);
        $operations = [];
        $roles->expects(self::once())->method('remove')->with($role)->willReturnCallback(static function () use (&$operations): void {
            $operations[] = 'remove';
        });
        $cache->expects(self::once())->method('invalidate')->with(42)->willReturnCallback(static function () use (&$operations): void {
            $operations[] = 'invalidate';
        });

        new DoctrineOperatorRoleStore($roles, $this->createStub(OperPermissionRepositoryInterface::class), $operators, $cache)->remove('ADMIN');

        self::assertSame(['invalidate', 'remove'], $operations);
    }

    #[Test]
    public function rejectsMutationWhenTheRoleDisappeared(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Role disappeared during mutation.');

        $this->store(
            $this->createStub(OperRoleRepositoryInterface::class),
            $this->createStub(OperPermissionRepositoryInterface::class),
        )->setUserModes('MISSING', []);
    }

    private function store(OperRoleRepositoryInterface $roles, OperPermissionRepositoryInterface $permissions): DoctrineOperatorRoleStore
    {
        $operators = $this->createStub(OperIrcopRepositoryInterface::class);
        $operators->method('findByRoleId')->willReturn([]);

        return new DoctrineOperatorRoleStore($roles, $permissions, $operators, $this->createStub(OperatorPermissionCachePort::class));
    }

    private static function role(int $id, string $name, string $description = ''): OperRole
    {
        $role = OperRole::create($name, $description);
        new ReflectionProperty(OperRole::class, 'id')->setValue($role, $id);

        return $role;
    }

    private static function assertRoleRecord(OperatorRoleRecord $role): void
    {
        self::assertSame(7, $role->id);
        self::assertSame('ADMIN', $role->name);
        self::assertSame('Network administrators', $role->description);
        self::assertFalse($role->protected);
        self::assertSame(['operserv.raw'], $role->permissions);
        self::assertSame(['H', 'W'], $role->userModes);
        self::assertSame('staff.example.net', $role->forcedVhostPattern);
        self::assertSame('netadmin', $role->operclass);
    }
}
