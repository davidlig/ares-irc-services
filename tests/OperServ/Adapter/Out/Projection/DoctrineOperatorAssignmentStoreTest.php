<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Projection;

use App\OperServ\Adapter\Out\Projection\DoctrineOperatorAssignmentStore;
use App\OperServ\Application\Port\Out\OperatorAssignmentRecord;
use App\OperServ\Application\Port\Out\OperatorRoleRecord;
use App\OperServ\Domain\Entity\OperIrcop;
use App\OperServ\Domain\Entity\OperPermission;
use App\OperServ\Domain\Entity\OperRole;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
use App\OperServ\Domain\Repository\OperRoleRepositoryInterface;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(DoctrineOperatorAssignmentStore::class)]
#[CoversClass(OperatorAssignmentRecord::class)]
#[CoversClass(OperatorRoleRecord::class)]
final class DoctrineOperatorAssignmentStoreTest extends TestCase
{
    #[Test]
    public function preservesAMissingAssignment(): void
    {
        $assignments = $this->createStub(OperIrcopRepositoryInterface::class);

        self::assertNull($this->store($assignments)->findByNickId(42));
    }

    #[Test]
    public function mapsAssignmentsAndTheirCompleteRoles(): void
    {
        $role = self::role(7, 'ADMIN');
        $permission = OperPermission::create('operserv.raw');
        $role->addPermission($permission);
        $role->changeUserModes(['H', 'W']);
        $role->changeForcedVhostPattern('staff.example.net');
        $role->changeOperclass('netadmin');
        $assignment = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 42, $role, 1);
        $assignments = $this->createStub(OperIrcopRepositoryInterface::class);
        $assignments->method('findByNickId')->willReturn($assignment);
        $assignments->method('findAll')->willReturn([$assignment]);
        $store = $this->store($assignments);

        $found = $store->findByNickId(42);
        self::assertInstanceOf(OperatorAssignmentRecord::class, $found);
        self::assertSame(42, $found->nickId);
        self::assertSame($assignment->getAddedAt(), $found->addedAt);
        self::assertRoleRecord($found->role);

        $all = $store->all();
        self::assertCount(1, $all);
        self::assertSame(42, $all[0]->nickId);
        self::assertRoleRecord($all[0]->role);
    }

    #[Test]
    public function persistsANewAssignmentWithTheResolvedRole(): void
    {
        $addedAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $role = self::role(7, 'ADMIN');
        $roles = $this->createStub(OperRoleRepositoryInterface::class);
        $roles->method('findByName')->willReturn($role);
        $assignments = $this->createMock(OperIrcopRepositoryInterface::class);
        $assignments->expects(self::once())->method('findByNickId')->with(42)->willReturn(null);
        $assignments->expects(self::once())->method('save')->with(self::callback(
            static fn (OperIrcop $assignment): bool => 42 === $assignment->getNickId()
                && $role === $assignment->getRole()
                && 1 === $assignment->getAddedById()
                && $addedAt == $assignment->getAddedAt(),
        ));

        new DoctrineOperatorAssignmentStore($assignments, $roles)->assign(
            42,
            new OperatorRoleRecord(7, 'ADMIN', '', false),
            1,
            $addedAt,
        );
    }

    #[Test]
    public function changesAndPersistsAnExistingAssignment(): void
    {
        $oldRole = self::role(6, 'OPER');
        $newRole = self::role(7, 'ADMIN');
        $current = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 42, $oldRole);
        $roles = $this->createStub(OperRoleRepositoryInterface::class);
        $roles->method('findByName')->willReturn($newRole);
        $assignments = $this->createMock(OperIrcopRepositoryInterface::class);
        $assignments->expects(self::once())->method('findByNickId')->with(42)->willReturn($current);
        $assignments->expects(self::once())->method('save')->with($current);

        new DoctrineOperatorAssignmentStore($assignments, $roles)->assign(
            42,
            new OperatorRoleRecord(7, 'ADMIN', '', false),
            null,
            new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );

        self::assertSame($newRole, $current->getRole());
    }

    #[Test]
    public function rejectsAnAssignmentWhenItsRoleDisappeared(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Role disappeared during assignment.');

        $roles = $this->createStub(OperRoleRepositoryInterface::class);
        new DoctrineOperatorAssignmentStore(
            $this->createStub(OperIrcopRepositoryInterface::class),
            $roles,
        )->assign(42, new OperatorRoleRecord(7, 'MISSING', '', false), null, new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
    }

    #[Test]
    public function removesOnlyAnExistingAssignment(): void
    {
        $current = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 42, self::role(7, 'ADMIN'));
        $assignments = $this->createMock(OperIrcopRepositoryInterface::class);
        $assignments->expects(self::exactly(2))->method('findByNickId')->willReturnMap([
            [42, $current],
            [43, null],
        ]);
        $assignments->expects(self::once())->method('remove')->with($current);
        $store = $this->store($assignments);

        $store->remove(42);
        $store->remove(43);
    }

    private function store(OperIrcopRepositoryInterface $assignments): DoctrineOperatorAssignmentStore
    {
        return new DoctrineOperatorAssignmentStore(
            $assignments,
            $this->createStub(OperRoleRepositoryInterface::class),
        );
    }

    private static function role(int $id, string $name): OperRole
    {
        $role = OperRole::create($name, $name . ' description');
        new ReflectionProperty(OperRole::class, 'id')->setValue($role, $id);

        return $role;
    }

    private static function assertRoleRecord(OperatorRoleRecord $role): void
    {
        self::assertSame(7, $role->id);
        self::assertSame('ADMIN', $role->name);
        self::assertSame('ADMIN description', $role->description);
        self::assertFalse($role->protected);
        self::assertSame(['operserv.raw'], $role->permissions);
        self::assertSame(['H', 'W'], $role->userModes);
        self::assertSame('staff.example.net', $role->forcedVhostPattern);
        self::assertSame('netadmin', $role->operclass);
    }
}
