<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Legacy;

use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\OperServ\Adapter\Out\Legacy\LegacyOperatorRoleAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LegacyOperatorRoleAccess::class)]
final class LegacyOperatorRoleAccessTest extends TestCase
{
    #[Test]
    public function reportsMissingAssignmentWithoutPermissionOrRoleName(): void
    {
        $operators = $this->createStub(OperIrcopRepositoryInterface::class);
        $operators->method('findByNickId')->willReturn(null);
        $roles = $this->createMock(OperRoleRepositoryInterface::class);
        $roles->expects(self::never())->method('hasPermission');
        $access = new LegacyOperatorRoleAccess($operators, $roles);

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
        $access = new LegacyOperatorRoleAccess($operators, $roles);

        self::assertTrue($access->hasAssignedRole(10));
        self::assertTrue($access->hasPermission(10, 'operserv.kill'));
        self::assertSame('ADMIN', $access->roleName(10));
    }
}
