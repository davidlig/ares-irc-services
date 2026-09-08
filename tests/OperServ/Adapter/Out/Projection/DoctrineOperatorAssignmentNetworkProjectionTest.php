<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Projection;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\OperServ\Adapter\In\Event\IrcopModeApplier;
use App\OperServ\Adapter\In\Event\IrcopOperclassApplier;
use App\OperServ\Adapter\Out\Projection\DoctrineOperatorAssignmentNetworkProjection;
use App\OperServ\Application\Port\Out\OperatorRoleRecord;
use App\OperServ\Application\PublishedEvent\OperIrcopChangedEvent;
use App\OperServ\Domain\Entity\OperRole;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
use App\OperServ\Domain\Repository\OperRoleRepositoryInterface;
use App\Shared\Application\Port\ActiveConnectionHolderInterface;
use App\Shared\Application\Port\EventBusInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(DoctrineOperatorAssignmentNetworkProjection::class)]
#[CoversClass(OperatorRoleRecord::class)]
#[CoversClass(OperIrcopChangedEvent::class)]
final class DoctrineOperatorAssignmentNetworkProjectionTest extends TestCase
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

    private function projection(OperRoleRepositoryInterface $roles, EventBusInterface $events): DoctrineOperatorAssignmentNetworkProjection
    {
        $connection = $this->createStub(ActiveConnectionHolderInterface::class);
        $operators = $this->createStub(OperIrcopRepositoryInterface::class);
        $nicks = $this->createStub(RegisteredNickRepositoryInterface::class);
        $sessions = new IdentifiedSessionRegistry();

        return new DoctrineOperatorAssignmentNetworkProjection(
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
