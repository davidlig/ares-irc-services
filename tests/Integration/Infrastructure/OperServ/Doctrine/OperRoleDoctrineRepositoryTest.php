<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\OperServ\Doctrine;

use App\Domain\OperServ\Entity\OperPermission;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\Infrastructure\OperServ\Doctrine\OperRoleDoctrineRepository;
use App\Tests\Integration\DoctrineIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(OperRoleDoctrineRepository::class)]
#[Group('integration')]
final class OperRoleDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    private OperRoleRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new OperRoleDoctrineRepository($this->entityManager);
    }

    private function createRole(string $name, string $description = '', bool $protected = false): OperRole
    {
        return OperRole::create($name, $description, $protected);
    }

    private function createPermission(string $name, string $description = ''): OperPermission
    {
        return OperPermission::create($name, $description);
    }

    #[Test]
    public function findReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->find(999));
    }

    #[Test]
    public function findReturnsOperRoleWhenFound(): void
    {
        $role = $this->createRole('Admin', 'Administrator role');
        $this->repository->save($role);
        $this->flushAndClear();

        $found = $this->repository->find($role->getId());

        self::assertNotNull($found);
        self::assertSame('ADMIN', $found->getName());
        self::assertSame('Administrator role', $found->getDescription());
    }

    #[Test]
    public function findByNameIsCaseInsensitive(): void
    {
        $role = $this->createRole('Admin', 'Administrator role');
        $this->repository->save($role);
        $this->flushAndClear();

        self::assertNotNull($this->repository->findByName('ADMIN'));
        self::assertNotNull($this->repository->findByName('admin'));
        self::assertNotNull($this->repository->findByName('AdMiN'));

        $found = $this->repository->findByName('admin');
        self::assertSame('ADMIN', $found->getName());
    }

    #[Test]
    public function findByNameReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByName('nonexistent'));
    }

    #[Test]
    public function findAllReturnsRolesSortedByName(): void
    {
        $roleZulu = $this->createRole('Zulu', 'Z role');
        $roleAlpha = $this->createRole('Alpha', 'A role');
        $roleBeta = $this->createRole('Beta', 'B role');

        $this->repository->save($roleZulu);
        $this->repository->save($roleAlpha);
        $this->repository->save($roleBeta);
        $this->flushAndClear();

        $all = $this->repository->findAll();

        self::assertCount(3, $all);
        self::assertSame('ALPHA', $all[0]->getName());
        self::assertSame('BETA', $all[1]->getName());
        self::assertSame('ZULU', $all[2]->getName());
    }

    #[Test]
    public function findAllReturnsEmptyArrayWhenNone(): void
    {
        self::assertSame([], $this->repository->findAll());
    }

    #[Test]
    public function findProtectedReturnsOnlyProtectedRoles(): void
    {
        $roleProtected1 = $this->createRole('Protected1', 'Protected role 1', true);
        $roleProtected2 = $this->createRole('Protected2', 'Protected role 2', true);
        $roleUnprotected = $this->createRole('Unprotected', 'Normal role', false);

        $this->repository->save($roleProtected1);
        $this->repository->save($roleProtected2);
        $this->repository->save($roleUnprotected);
        $this->flushAndClear();

        $protected = $this->repository->findProtected();

        self::assertCount(2, $protected);
        foreach ($protected as $role) {
            self::assertTrue($role->isProtected());
        }
        self::assertSame('PROTECTED1', $protected[0]->getName());
        self::assertSame('PROTECTED2', $protected[1]->getName());
    }

    #[Test]
    public function findProtectedReturnsEmptyArrayWhenNone(): void
    {
        self::assertSame([], $this->repository->findProtected());
    }

    #[Test]
    public function savePersistsAndFlushes(): void
    {
        $role = $this->createRole('TestRole', 'Test description');

        $this->repository->save($role);

        self::assertNotNull($role->getId());
        $found = $this->repository->find($role->getId());
        self::assertNotNull($found);
        self::assertSame('TESTROLE', $found->getName());
    }

    #[Test]
    public function removeRemovesAndFlushes(): void
    {
        $role = $this->createRole('ToDelete', 'Will be deleted');
        $this->repository->save($role);
        $this->flushAndClear();

        $id = $role->getId();
        $managedRole = $this->repository->find($id);
        self::assertNotNull($managedRole);
        $this->repository->remove($managedRole);
        $this->flushAndClear();

        self::assertNull($this->repository->find($id));
    }

    #[Test]
    public function hasPermissionReturnsFalseWhenRoleNotFound(): void
    {
        self::assertFalse($this->repository->hasPermission(999, 'some.permission'));
    }

    #[Test]
    public function hasPermissionReturnsTrueWhenPermissionExists(): void
    {
        $role = $this->createRole('Admin', 'Admin role');
        $permission = $this->createPermission(OperPermission::ADMIN_ADD, 'Add admin');
        $this->entityManager->persist($permission);
        $role->addPermission($permission);
        $this->repository->save($role);
        $this->flushAndClear();

        self::assertTrue($this->repository->hasPermission($role->getId(), OperPermission::ADMIN_ADD));
    }

    #[Test]
    public function hasPermissionReturnsFalseWhenPermissionDoesNotExist(): void
    {
        $role = $this->createRole('Admin', 'Admin role');
        $permission = $this->createPermission(OperPermission::ADMIN_ADD, 'Add admin');
        $this->entityManager->persist($permission);
        $role->addPermission($permission);
        $this->repository->save($role);
        $this->flushAndClear();

        self::assertFalse($this->repository->hasPermission($role->getId(), OperPermission::KILL_LOCAL));
    }

    #[Test]
    public function hasPermissionReturnsFalseForRoleWithNoPermissions(): void
    {
        $role = $this->createRole('Empty', 'Role with no permissions');
        $this->repository->save($role);
        $this->flushAndClear();

        self::assertFalse($this->repository->hasPermission($role->getId(), OperPermission::ADMIN_ADD));
    }
}
