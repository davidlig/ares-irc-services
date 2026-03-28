<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\OperServ\Doctrine;

use App\Domain\OperServ\Entity\OperPermission;
use App\Domain\OperServ\Repository\OperPermissionRepositoryInterface;
use App\Infrastructure\OperServ\Doctrine\OperPermissionDoctrineRepository;
use App\Tests\Integration\DoctrineIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(OperPermissionDoctrineRepository::class)]
#[Group('integration')]
final class OperPermissionDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    private OperPermissionRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new OperPermissionDoctrineRepository($this->entityManager);
    }

    #[Test]
    public function findReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->find(999));
    }

    #[Test]
    public function findReturnsOperPermissionWhenFound(): void
    {
        $permission = OperPermission::create('operserv.test.permission', 'Test permission');

        $this->repository->save($permission);
        $this->flushAndClear();

        $found = $this->repository->find($permission->getId());

        self::assertNotNull($found);
        self::assertSame('operserv.test.permission', $found->getName());
        self::assertSame('Test permission', $found->getDescription());
    }

    #[Test]
    public function findByNameReturnsPermissionByName(): void
    {
        $permission = OperPermission::create('operserv.kill', 'Can kill users');

        $this->repository->save($permission);
        $this->flushAndClear();

        $found = $this->repository->findByName('operserv.kill');

        self::assertNotNull($found);
        self::assertSame('operserv.kill', $found->getName());
        self::assertSame('Can kill users', $found->getDescription());
    }

    #[Test]
    public function findByNameReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByName('operserv.nonexistent.permission'));
    }

    #[Test]
    public function findAllReturnsAllPermissionsSortedByName(): void
    {
        $permission1 = OperPermission::create('operserv.zline.list', 'ZLine list permission');
        $permission2 = OperPermission::create('operserv.admin.add', 'Admin add permission');
        $permission3 = OperPermission::create('operserv.kill', 'Kill permission');

        $this->repository->save($permission1);
        $this->repository->save($permission2);
        $this->repository->save($permission3);
        $this->flushAndClear();

        $all = $this->repository->findAll();

        self::assertCount(3, $all);
        self::assertSame('operserv.admin.add', $all[0]->getName());
        self::assertSame('operserv.kill', $all[1]->getName());
        self::assertSame('operserv.zline.list', $all[2]->getName());
    }

    #[Test]
    public function findAllReturnsEmptyArrayWhenNone(): void
    {
        self::assertSame([], $this->repository->findAll());
    }

    #[Test]
    public function savePersistsAndFlushes(): void
    {
        $permission = OperPermission::create('operserv.network.view', 'Can view network info');

        $this->repository->save($permission);

        self::assertNotSame(0, $permission->getId());

        $this->entityManager->clear();

        $found = $this->repository->find($permission->getId());

        self::assertNotNull($found);
        self::assertSame('operserv.network.view', $found->getName());
    }
}
