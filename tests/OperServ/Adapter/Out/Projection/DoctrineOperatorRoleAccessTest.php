<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Projection;

use App\OperServ\Adapter\Out\Projection\DoctrineOperatorRoleAccess;
use App\OperServ\Adapter\Out\Projection\OperatorPermissionCache;
use App\OperServ\Domain\Entity\OperIrcop;
use App\OperServ\Domain\Entity\OperPermission;
use App\OperServ\Domain\Entity\OperRole;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
use DateTimeImmutable;
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
        $operators->method('findByNickIdWithRolePermissions')->willReturn(null);
        $access = new DoctrineOperatorRoleAccess($operators, new OperatorPermissionCache());

        self::assertFalse($access->hasAssignedRole(10));
        self::assertFalse($access->hasPermission(10, 'operserv.kill'));
        self::assertNull($access->roleName(10));
    }

    #[Test]
    public function delegatesPermissionToTheAssignedRole(): void
    {
        $role = OperRole::create('ADMIN');
        $role->addPermission(OperPermission::create('operserv.kill'));
        $operator = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 10, $role);
        $operators = $this->createMock(OperIrcopRepositoryInterface::class);
        $operators->expects(self::once())->method('findByNickIdWithRolePermissions')->with(10)->willReturn($operator);
        $access = new DoctrineOperatorRoleAccess($operators, new OperatorPermissionCache());

        self::assertTrue($access->hasAssignedRole(10));
        self::assertTrue($access->hasPermission(10, 'operserv.kill'));
        self::assertSame('ADMIN', $access->roleName(10));
        self::assertFalse($access->hasPermission(10, 'operserv.raw'));
    }
}
