<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Legacy;

use App\Application\OperServ\ForcedVhostApplier;
use App\Application\OperServ\IrcopModeApplier;
use App\Application\OperServ\IrcopOperclassApplier;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\EventBusInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\VhostDisplayResolver;
use App\OperServ\Adapter\Out\Legacy\LegacyOperatorRoleNetworkProjection;
use App\OperServ\Application\PublishedEvent\OperRoleForcedVhostChangedEvent;
use App\Tests\Application\OperServ\RecordingOperclassActions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

#[CoversClass(LegacyOperatorRoleNetworkProjection::class)]
#[CoversClass(OperRoleForcedVhostChangedEvent::class)]
final class LegacyOperatorRoleNetworkProjectionTest extends TestCase
{
    #[Test]
    public function refreshesEachProjectedRoleSetting(): void
    {
        $connection = $this->createStub(ActiveConnectionHolderInterface::class);
        $operators = $this->createStub(OperIrcopRepositoryInterface::class);
        $nicks = $this->createStub(RegisteredNickRepositoryInterface::class);
        $sessions = new IdentifiedSessionRegistry();
        $events = $this->createMock(EventBusInterface::class);
        $events->expects(self::once())->method('dispatch')->with(self::callback(
            static fn (object $event): bool => $event instanceof OperRoleForcedVhostChangedEvent
                && 7 === $event->roleId
                && 'staff.example.net' === $event->pattern,
        ));
        $projection = new LegacyOperatorRoleNetworkProjection(
            new IrcopModeApplier(
                $sessions,
                $connection,
                $operators,
                $nicks,
                $this->createStub(NetworkUserLookupPort::class),
                new NullLogger(),
            ),
            new ForcedVhostApplier(
                $operators,
                $nicks,
                $sessions,
                $this->createStub(NickServNotifierInterface::class),
                $this->createStub(NetworkUserLookupPort::class),
                $connection,
                new VhostDisplayResolver(),
                new NullLogger(),
            ),
            new IrcopOperclassApplier($sessions, $connection, $operators, $nicks),
            $connection,
            $events,
        );

        $projection->refreshModes(7, [], []);
        $projection->refreshVhost(7, 'staff.example.net');
        $projection->refreshOperclass(7, 'netadmin');
    }

    #[Test]
    public function reportsAnUnsupportedProtocolSeparatelyFromItsMissingCatalog(): void
    {
        $actions = $this->createStub(ProtocolServiceActionsInterface::class);
        $projection = $this->projection($actions);

        self::assertFalse($projection->supportsOperclass());
        self::assertNull($projection->availableOperclasses());
    }

    #[Test]
    public function preservesANullCatalogForAProtocolThatSupportsOperclasses(): void
    {
        $actions = new RecordingOperclassActions();
        $actions->availableOperclasses = null;
        $projection = $this->projection($actions);

        self::assertTrue($projection->supportsOperclass());
        self::assertNull($projection->availableOperclasses());
    }

    #[Test]
    public function exposesTheCatalogFromASupportedProtocol(): void
    {
        $actions = new RecordingOperclassActions();
        $actions->availableOperclasses = ['locop', 'netadmin'];
        $projection = $this->projection($actions);

        self::assertTrue($projection->supportsOperclass());
        self::assertSame(['locop', 'netadmin'], $projection->availableOperclasses());
    }

    private function projection(ProtocolServiceActionsInterface $actions): LegacyOperatorRoleNetworkProjection
    {
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($actions);
        $connection = $this->createStub(ActiveConnectionHolderInterface::class);
        $connection->method('getProtocolModule')->willReturn($module);

        $modes = new ReflectionClass(IrcopModeApplier::class)->newInstanceWithoutConstructor();
        $vhost = new ReflectionClass(ForcedVhostApplier::class)->newInstanceWithoutConstructor();
        $operclass = new ReflectionClass(IrcopOperclassApplier::class)->newInstanceWithoutConstructor();

        return new LegacyOperatorRoleNetworkProjection(
            $modes,
            $vhost,
            $operclass,
            $connection,
            $this->createStub(EventBusInterface::class),
        );
    }
}
