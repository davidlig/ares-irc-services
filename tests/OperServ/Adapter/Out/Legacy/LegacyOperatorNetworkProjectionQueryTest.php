<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Legacy;

use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\OperServ\Adapter\Out\Legacy\LegacyOperatorNetworkProjectionQuery;
use App\OperServ\Application\Port\In\OperatorNetworkProjection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(LegacyOperatorNetworkProjectionQuery::class)]
#[CoversClass(OperatorNetworkProjection::class)]
final class LegacyOperatorNetworkProjectionQueryTest extends TestCase
{
    #[Test]
    public function returnsNullWhenTheNickHasNoOperatorAssignment(): void
    {
        $repository = $this->createStub(OperIrcopRepositoryInterface::class);
        $repository->method('findByNickId')->willReturn(null);

        self::assertNull(new LegacyOperatorNetworkProjectionQuery($repository)->findForNick(42, 'Alice'));
    }

    #[Test]
    public function projectsTheEffectiveForcedVhostAndOperclass(): void
    {
        $role = OperRole::create('ADMIN');
        $role->changeForcedVhostPattern('staff.example.test');
        $role->changeOperclass('services:admin');
        $repository = $this->createStub(OperIrcopRepositoryInterface::class);
        $repository->method('findByNickId')->willReturn(OperIrcop::create(42, $role));

        $projection = new LegacyOperatorNetworkProjectionQuery($repository)->findForNick(42, 'Alice');
        self::assertNotNull($projection);
        self::assertSame(42, $projection->nickId);
        self::assertSame('Alice.staff.example.test', $projection->forcedVhost);
        self::assertSame('services:admin', $projection->operclass);
    }

    #[Test]
    public function ignoresAnInvalidForcedVhostPattern(): void
    {
        $role = OperRole::create('ADMIN');
        $role->changeForcedVhostPattern('invalid');
        $repository = $this->createStub(OperIrcopRepositoryInterface::class);
        $repository->method('findByNickId')->willReturn(OperIrcop::create(42, $role));

        $projection = new LegacyOperatorNetworkProjectionQuery($repository)->findForNick(42, 'Alice');
        self::assertNotNull($projection);
        self::assertSame(42, $projection->nickId);
        self::assertNull($projection->forcedVhost);
        self::assertNull($projection->operclass);
    }

    #[Test]
    public function returnsEveryNickIdAssignedToTheRole(): void
    {
        $role = OperRole::create('ADMIN');
        $repository = $this->createStub(OperIrcopRepositoryInterface::class);
        $repository->method('findByRoleId')->willReturn([
            OperIrcop::create(42, $role),
            OperIrcop::create(84, $role),
        ]);

        self::assertSame(
            [42, 84],
            new LegacyOperatorNetworkProjectionQuery($repository)->findNickIdsByRoleId(7),
        );
    }
}
