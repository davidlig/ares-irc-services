<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Legacy;

use App\Application\OperServ\IrcopModeApplier;
use App\Application\OperServ\IrcopOperclassApplier;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\EventBusInterface;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Event\OperIrcopChangedEvent;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\OperServ\Adapter\Out\Legacy\LegacyOperatorAssignmentNetworkProjection;
use App\OperServ\Application\Port\Out\OperatorRoleRecord;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(LegacyOperatorAssignmentNetworkProjection::class)]
#[CoversClass(OperatorRoleRecord::class)]
final class LegacyOperatorAssignmentNetworkProjectionTest extends TestCase
{
    #[Test]
    public function applyingAndRemovingAnAssignmentDispatchesFinalReconciliationEvents(): void
    {
        $role = OperRole::create('OPER');
        $roles = $this->createStub(OperRoleRepositoryInterface::class);
        $roles->method('findByName')->willReturn($role);
        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::exactly(2))->method('dispatch')->with(self::callback(
            static fn (object $event): bool => $event instanceof OperIrcopChangedEvent
                && 42 === $event->nickId
                && 'Alice' === $event->nickname,
        ));
        $projection = $this->projection($roles, $events);
        $record = new OperatorRoleRecord(1, 'OPER', '', false);

        $projection->apply(42, 'Alice', $record);
        $projection->remove(42, 'Alice', $record);
    }

    #[Test]
    public function replacingARoleDispatchesExactlyOneFinalReconciliationEvent(): void
    {
        $oldRole = OperRole::create('OPER');
        $newRole = OperRole::create('ADMIN');
        $roles = $this->createMock(OperRoleRepositoryInterface::class);
        $roles->expects(self::exactly(2))->method('findByName')->willReturnMap([
            ['OPER', $oldRole],
            ['ADMIN', $newRole],
        ]);
        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::once())->method('dispatch')->with(self::callback(
            static fn (object $event): bool => $event instanceof OperIrcopChangedEvent
                && 42 === $event->nickId
                && 'Alice' === $event->nickname,
        ));
        $projection = $this->projection($roles, $events);

        $projection->replace(
            42,
            'Alice',
            new OperatorRoleRecord(1, 'OPER', '', false),
            new OperatorRoleRecord(2, 'ADMIN', '', false),
        );
    }

    private function projection(OperRoleRepositoryInterface $roles, EventBusInterface $events): LegacyOperatorAssignmentNetworkProjection
    {
        $connection = $this->createStub(ActiveConnectionHolderInterface::class);
        $operators = $this->createStub(OperIrcopRepositoryInterface::class);
        $nicks = $this->createStub(RegisteredNickRepositoryInterface::class);
        $sessions = new IdentifiedSessionRegistry();

        return new LegacyOperatorAssignmentNetworkProjection(
            new IrcopModeApplier(
                $sessions,
                $connection,
                $operators,
                $nicks,
                $this->createStub(NetworkUserLookupPort::class),
                new NullLogger(),
            ),
            new IrcopOperclassApplier($sessions, $connection, $operators, $nicks),
            $roles,
            $events,
        );
    }
}
