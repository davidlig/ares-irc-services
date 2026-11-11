<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Persistence\Doctrine;

use App\OperServ\Adapter\Out\Persistence\Doctrine\BurstIrcopCache;
use App\OperServ\Adapter\Out\Persistence\Doctrine\OperIrcopDoctrineRepository;
use App\OperServ\Domain\Entity\OperIrcop;
use App\OperServ\Domain\Entity\OperPermission;
use App\OperServ\Domain\Entity\OperRole;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\Query;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use stdClass;
use UnexpectedValueException;

use function array_map;

#[CoversClass(OperIrcopDoctrineRepository::class)]
#[CoversClass(BurstIrcopCache::class)]
#[Group('integration')]
final class OperIrcopDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    private OperIrcopDoctrineRepository $repository;

    private BurstIrcopCache $burstCache;

    #[Test]
    public function findReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->find(999999));
    }

    #[Test]
    public function findReturnsOperIrcopWhenFound(): void
    {
        $role = $this->createOperRole('Admin');
        $ircop = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 100, role: $role);

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
        $ircop = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 200, role: $role);

        $this->repository->save($ircop);
        $this->flushAndClear();

        $found = $this->repository->findByNickId(200);

        self::assertNotNull($found);
        self::assertSame(200, $found->getNickId());
    }

    #[Test]
    public function findByNickIdsLoadsOnlyRequestedAssignmentsWithTheirRoles(): void
    {
        $role = $this->createOperRole('Admin');
        $first = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 201, role: $role);
        $second = OperIrcop::create(new DateTimeImmutable('2026-01-02T00:00:00+00:00'), nickId: 202, role: $role);
        $other = OperIrcop::create(new DateTimeImmutable('2026-01-03T00:00:00+00:00'), nickId: 203, role: $role);
        $this->entityManager->persist($first);
        $this->entityManager->persist($second);
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $assignments = $this->repository->findByNickIds([201, 202, 999999]);

        self::assertCount(2, $assignments);
        self::assertSame([201, 202], array_map(static fn (OperIrcop $entry): int => $entry->getNickId(), $assignments));
        foreach ($assignments as $assignment) {
            self::assertFalse($this->entityManager->isUninitializedObject($assignment->getRole()));
        }
    }

    #[Test]
    public function findByNickIdsReturnsEmptyForEmptyInput(): void
    {
        self::assertSame([], $this->repository->findByNickIds([]));
    }

    #[Test]
    public function batchSnapshotCachesMissingAssignmentsAndInvalidatesAfterWrites(): void
    {
        $token = new stdClass();
        $this->burstCache->beginBatch($token);
        $this->burstCache->primeBatch($token, [], [901]);
        self::assertNull($this->repository->findByNickId(901));

        $role = $this->createOperRole('Admin');
        $assignment = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 901, role: $role);
        $this->repository->save($assignment);

        self::assertSame($assignment, $this->repository->findByNickId(901));
        $this->burstCache->finishBatch($token);
    }

    #[Test]
    public function loadsAssignmentRoleAndPermissionsTogetherForAuthorization(): void
    {
        $role = $this->createOperRole('Admin');
        $permission = OperPermission::create('operserv.help');
        $role->addPermission($permission);
        $this->entityManager->persist($permission);
        $this->entityManager->flush();
        $this->repository->save(OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 250, role: $role));
        $this->flushAndClear();

        $found = $this->repository->findByNickIdWithRolePermissions(250);

        self::assertNotNull($found);
        self::assertSame('ADMIN', $found->getRole()->getName());
        self::assertSame(['operserv.help'], array_map(
            static fn (OperPermission $entry): string => $entry->getName(),
            $found->getRole()->getPermissions(),
        ));
    }

    #[Test]
    public function rejectsUnexpectedHydrationResultType(): void
    {
        $query = $this->createMock(Query::class);
        $query->expects(self::once())->method('setParameter')->with('nickId', 250)->willReturnSelf();
        $query->expects(self::once())->method('getOneOrNullResult')->willReturn('unexpected');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('createQuery')->willReturn($query);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Authorization query did not return an IRCOP assignment.');

        new OperIrcopDoctrineRepository($entityManager, new BurstIrcopCache())->findByNickIdWithRolePermissions(250);
    }

    #[Test]
    public function findAllReturnsEmptyArrayWhenNone(): void
    {
        self::assertSame([], $this->repository->findAll());
    }

    #[Test]
    public function findAllPreloadsDistinctRolesAndPermissions(): void
    {
        $permission = OperPermission::create('operserv.gline');
        $this->entityManager->persist($permission);
        $first = $this->createOperRole('First');
        $second = $this->createOperRole('Second');
        $first->addPermission($permission);
        $this->entityManager->persist(OperIrcop::create(new DateTimeImmutable('2026-01-01'), 1, $first));
        $this->entityManager->persist(OperIrcop::create(new DateTimeImmutable('2026-01-02'), 2, $second));
        $this->flushAndClear();

        $assignments = $this->repository->findAll();
        self::assertCount(2, $assignments);
        self::assertSame([2, 1], array_map(static fn (OperIrcop $entry): int => $entry->getNickId(), $assignments));
        $permissions = new ReflectionProperty(OperRole::class, 'permissions');
        foreach ($assignments as $assignment) {
            $role = $assignment->getRole();
            self::assertFalse($this->entityManager->isUninitializedObject($role));
            $collection = $permissions->getValue($role);
            self::assertInstanceOf(PersistentCollection::class, $collection);
            self::assertTrue($collection->isInitialized());
        }
        self::assertSame('operserv.gline', $assignments[1]->getRole()->getPermissions()[0]->getName());
    }

    #[Test]
    public function findAllReturnsAllOperIrcopsOrderedByAddedAtDesc(): void
    {
        $role = $this->createOperRole('Admin');

        $ircop1 = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 100, role: $role);
        $ircop2 = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 101, role: $role);
        $ircop3 = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 102, role: $role);

        // Set explicit timestamps to ensure ordering without sleep
        $this->setAddedAt($ircop1, new DateTimeImmutable('-3 seconds'));
        $this->setAddedAt($ircop2, new DateTimeImmutable('-2 seconds'));
        $this->setAddedAt($ircop3, new DateTimeImmutable('-1 second'));

        $this->entityManager->persist($ircop1);
        $this->entityManager->persist($ircop2);
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

        $admin1 = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 100, role: $adminRole);
        $admin2 = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 101, role: $adminRole);
        $oper1 = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 200, role: $operRole);

        $this->repository->save($admin1);
        $this->repository->save($admin2);
        $this->repository->save($oper1);
        $this->flushAndClear();

        $admins = $this->repository->findByRoleId($adminRole->getId());
        $opers = $this->repository->findByRoleId($operRole->getId());

        self::assertCount(2, $admins);
        self::assertCount(1, $opers);
        self::assertSame(101, $admins[0]->getNickId());
        self::assertSame(100, $admins[1]->getNickId());
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
        $ircop = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 300, role: $role, addedById: 1, reason: 'Test admin');

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
        $ircop = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 400, role: $role);

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

        $admin1 = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 500, role: $adminRole);
        $admin2 = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 501, role: $adminRole);
        $oper1 = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 600, role: $operRole);

        $this->repository->save($admin1);
        $this->repository->save($admin2);
        $this->repository->save($oper1);
        $this->flushAndClear();

        self::assertSame(2, $this->repository->countByRoleId($adminRole->getId()));
        self::assertSame(1, $this->repository->countByRoleId($operRole->getId()));
        self::assertSame(0, $this->repository->countByRoleId(999999));
    }

    #[Test]
    public function deleteByNickIdRemovesIrcopEntry(): void
    {
        $role = $this->createOperRole('Admin');
        $ircop1 = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 100, role: $role);
        $ircop2 = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 200, role: $role);

        $this->repository->save($ircop1);
        $this->repository->save($ircop2);
        $this->flushAndClear();

        $this->repository->deleteByNickId(100);
        $this->flushAndClear();

        self::assertNull($this->repository->findByNickId(100));
        self::assertNotNull($this->repository->findByNickId(200));
    }

    #[Test]
    public function deleteByNickIdDoesNothingWhenNoMatch(): void
    {
        $role = $this->createOperRole('Admin');
        $ircop = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 100, role: $role);

        $this->repository->save($ircop);
        $this->flushAndClear();

        $this->repository->deleteByNickId(999);
        $this->flushAndClear();

        self::assertNotNull($this->repository->findByNickId(100));
    }

    #[Test]
    public function clearAddedByIdClearsOnlyMatchingEntries(): void
    {
        $role = $this->createOperRole('Admin');
        $ircop1 = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 100, role: $role, addedById: 900);
        $ircop2 = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 200, role: $role, addedById: 901);
        $ircop3 = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), nickId: 300, role: $role, addedById: 900);

        $this->repository->save($ircop1);
        $this->repository->save($ircop2);
        $this->repository->save($ircop3);
        $this->flushAndClear();

        $this->repository->clearAddedById(900);
        $this->flushAndClear();

        $found1 = $this->repository->findByNickId(100);
        $found2 = $this->repository->findByNickId(200);
        $found3 = $this->repository->findByNickId(300);

        self::assertNotNull($found1);
        self::assertNotNull($found2);
        self::assertNotNull($found3);
        self::assertNull($found1->getAddedById());
        self::assertSame(901, $found2->getAddedById());
        self::assertNull($found3->getAddedById());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->burstCache = new BurstIrcopCache();
        $this->repository = new OperIrcopDoctrineRepository($this->entityManager, $this->burstCache);
    }

    private function setAddedAt(OperIrcop $ircop, DateTimeImmutable $time): void
    {
        $ref = new ReflectionProperty($ircop, 'addedAt');
        $ref->setValue($ircop, $time);
    }

    private function createOperRole(string $name): OperRole
    {
        $role = OperRole::create(name: $name, description: $name . ' role');
        $this->entityManager->persist($role);
        $this->entityManager->flush();

        return $role;
    }
}
