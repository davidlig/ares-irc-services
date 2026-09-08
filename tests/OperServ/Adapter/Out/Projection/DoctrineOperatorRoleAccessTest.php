<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Projection;

use App\OperServ\Adapter\Out\Projection\DoctrineOperatorRoleAccess;
use App\OperServ\Domain\Entity\OperIrcop;
use App\OperServ\Domain\Entity\OperRole;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
use App\OperServ\Domain\Repository\OperRoleRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineOperatorRoleAccess::class)]
final class DoctrineOperatorRoleAccessTest extends TestCase
{
    #[Test]
    public function reportsMissingAssignmentWithoutPermissionOrRoleName(): void
    {
        $operators = $this->createStub(OperIrcopRepositoryInterface::class);
        $operators->method('findByNickId')->willReturn(null);
        $roles = $this->createMock(OperRoleRepositoryInterface::class);
        $roles->expects(self::never())->method('hasPermission');
        $access = new DoctrineOperatorRoleAccess($operators, $roles);

        self::assertFalse($access->hasAssignedRole(10));
        self::assertFalse($access->hasPermission(10, 'operserv.kill'));
        self::assertNull($access->roleName(10));
    }

    #[Test]
    public function delegatesPermissionToTheAssignedRole(): void
    {
        $role = $this->createStub(OperRole::class);
        $role->method('getId')->willReturn(7);
        $role->method('getName')->willReturn('ADMIN');
        $operator = OperIrcop::create(10, $role);
        $operators = $this->createStub(OperIrcopRepositoryInterface::class);
        $operators->method('findByNickId')->willReturn($operator);
        $roles = $this->createMock(OperRoleRepositoryInterface::class);
        $roles->expects(self::once())->method('hasPermission')->with(7, 'operserv.kill')->willReturn(true);
        $access = new DoctrineOperatorRoleAccess($operators, $roles);

        self::assertTrue($access->hasAssignedRole(10));
        self::assertTrue($access->hasPermission(10, 'operserv.kill'));
        self::assertSame('ADMIN', $access->roleName(10));
    }
}
