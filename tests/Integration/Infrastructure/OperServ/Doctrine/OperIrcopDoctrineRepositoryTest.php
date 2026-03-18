<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\OperServ\Doctrine;

use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Infrastructure\OperServ\Doctrine\OperIrcopDoctrineRepository;
use App\Tests\Integration\DoctrineIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(OperIrcopDoctrineRepository::class)]
#[Group('integration')]
final class OperIrcopDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    private OperIrcopDoctrineRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new OperIrcopDoctrineRepository($this->entityManager);
    }

    #[Test]
    public function findReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->find(999999));
    }

    #[Test]
    public function findReturnsOperIrcopWhenFound(): void
    {
        $role = $this->createOperRole('Admin');
        $ircop = OperIrcop::create(nickId: 100, role: $role);

        $this->repository->save($ircop);
        $this->flushAndClear();

        $id = $ircop->getId();
        $found = $this->repository->find($id);

        self::assertNotNull($found);
        self::assertSame(100, $found->getNickId());
        self::assertSame($role->getId(), $found->getRole()->getId());
    }

    #[Test]
    public function findByNickIdReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByNickId(999999));
    }

    #[Test]
    public function findByNickIdReturnsOperIrcopWhenFound(): void
    {
        $role = $this->createOperRole('Admin');
        $ircop = OperIrcop::create(nickId: 200, role: $role);

        $this->repository->save($ircop);
        $this->flushAndClear();

        $found = $this->repository->findByNickId(200);

        self::assertNotNull($found);
        self::assertSame(200, $found->getNickId());
    }

    #[Test]
    public function findAllReturnsEmptyArrayWhenNone(): void
    {
        self::assertSame([], $this->repository->findAll());
    }

    #[Test]
    public function findAllReturnsAllOperIrcopsOrderedByAddedAtDesc(): void
    {
        $role = $this->createOperRole('Admin');
        $roleId = $role->getId();

        $ircop1 = OperIrcop::create(nickId: 100, role: $role);
        $this->entityManager->persist($ircop1);
        $this->entityManager->flush();

        sleep(1);

        $role = $this->entityManager->find(OperRole::class, $roleId);
        $ircop2 = OperIrcop::create(nickId: 101, role: $role);
        $this->entityManager->persist($ircop2);
        $this->entityManager->flush();

        sleep(1);

        $role = $this->entityManager->find(OperRole::class, $roleId);
        $ircop3 = OperIrcop::create(nickId: 102, role: $role);
        $this->entityManager->persist($ircop3);
        $this->entityManager->flush();

        $this->entityManager->clear();

        $all = $this->repository->findAll();

        self::assertCount(3, $all);
        self::assertSame(102, $all[0]->getNickId());
        self::assertSame(101, $all[1]->getNickId());
        self::assertSame(100, $all[2]->getNickId());
    }

    #[Test]
    public function findByRoleIdReturnsFilteredByRole(): void
    {
        $adminRole = $this->createOperRole('Admin');
        $operRole = $this->createOperRole('Oper');

        $admin1 = OperIrcop::create(nickId: 100, role: $adminRole);
        $admin2 = OperIrcop::create(nickId: 101, role: $adminRole);
        $oper1 = OperIrcop::create(nickId: 200, role: $operRole);

        $this->repository->save($admin1);
        $this->repository->save($admin2);
        $this->repository->save($oper1);
        $this->flushAndClear();

        $admins = $this->repository->findByRoleId($adminRole->getId());
        $opers = $this->repository->findByRoleId($operRole->getId());

        self::assertCount(2, $admins);
        self::assertCount(1, $opers);
        self::assertSame(100, $admins[0]->getNickId());
        self::assertSame(101, $admins[1]->getNickId());
    }

    #[Test]
    public function findByRoleIdReturnsEmptyArrayWhenNone(): void
    {
        self::assertSame([], $this->repository->findByRoleId(999999));
    }

    #[Test]
    public function savePersistsAndFlushes(): void
    {
        $role = $this->createOperRole('Admin');
        $ircop = OperIrcop::create(nickId: 300, role: $role, addedById: 1, reason: 'Test admin');

        $this->repository->save($ircop);

        self::assertGreaterThan(0, $ircop->getId());

        $this->flushAndClear();

        $found = $this->repository->find($ircop->getId());

        self::assertNotNull($found);
        self::assertSame(300, $found->getNickId());
        self::assertSame('Test admin', $found->getReason());
        self::assertSame(1, $found->getAddedById());
    }

    #[Test]
    public function removeRemovesAndFlushes(): void
    {
        $role = $this->createOperRole('Admin');
        $ircop = OperIrcop::create(nickId: 400, role: $role);

        $this->repository->save($ircop);
        $this->flushAndClear();

        $id = $ircop->getId();

        $found = $this->repository->find($id);
        self::assertNotNull($found);

        $this->repository->remove($found);
        $this->flushAndClear();

        self::assertNull($this->repository->find($id));
    }

    #[Test]
    public function countByRoleIdReturnsCorrectCount(): void
    {
        $adminRole = $this->createOperRole('Admin');
        $operRole = $this->createOperRole('Oper');

        $admin1 = OperIrcop::create(nickId: 500, role: $adminRole);
        $admin2 = OperIrcop::create(nickId: 501, role: $adminRole);
        $oper1 = OperIrcop::create(nickId: 600, role: $operRole);

        $this->repository->save($admin1);
        $this->repository->save($admin2);
        $this->repository->save($oper1);
        $this->flushAndClear();

        self::assertSame(2, $this->repository->countByRoleId($adminRole->getId()));
        self::assertSame(1, $this->repository->countByRoleId($operRole->getId()));
        self::assertSame(0, $this->repository->countByRoleId(999999));
    }

    private function createOperRole(string $name): OperRole
    {
        $role = OperRole::create(name: $name, description: $name . ' role');
        $this->entityManager->persist($role);
        $this->entityManager->flush();

        return $role;
    }
}
