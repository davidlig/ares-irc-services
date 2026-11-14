<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\ServiceBridge;

use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\ServiceBridge\ServiceNickReservationInventory;
use App\Irc\Adapter\ServiceBridge\ServiceNickReservationSubscriber;
use App\Irc\Application\Port\In\ManagedServiceNickReservationLookup;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceCommandListenerInterface;
use App\Irc\Application\Port\In\ServiceNickReservationInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionClass;
use RuntimeException;

#[CoversClass(ServiceNickReservationSubscriber::class)]
final class ServiceNickReservationSubscriberTest extends TestCase
{
    #[Test]
    public function getSubscribedEventsReturnsBurstCompleteWithPriority200(): void
    {
        $events = ServiceNickReservationSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(NetworkBurstCompleteEvent::class, $events);
        self::assertSame(['onBurstComplete', 200], $events[NetworkBurstCompleteEvent::class]);
    }

    #[Test]
    public function onBurstCompleteReservesAllServiceNicks(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);

        $connectionHolder = new ActiveConnectionHolder();

        $reservedNicks = [];
        $reservation = $this->createMock(ServiceNickReservationInterface::class);
        $reservation->expects(self::exactly(3))
            ->method('reserveNick')
            ->willReturnCallback(static function (string $nick, string $reason) use (&$reservedNicks): void {
                $reservedNicks[] = ['nick' => $nick, 'reason' => $reason];
            });

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getNickReservation')->willReturn($reservation);

        $connectionHolderReflection = new ReflectionClass($connectionHolder);
        $moduleProperty = $connectionHolderReflection->getProperty('protocolModule');
        $moduleProperty->setValue($connectionHolder, $module);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);

        $listener1 = $this->createStub(ServiceCommandListenerInterface::class);
        $listener1->method('getServiceName')->willReturn('NickServ');

        $listener2 = $this->createStub(ServiceCommandListenerInterface::class);
        $listener2->method('getServiceName')->willReturn('ChanServ');

        $listener3 = $this->createStub(ServiceCommandListenerInterface::class);
        $listener3->method('getServiceName')->willReturn('MemoServ');

        $subscriber = new ServiceNickReservationSubscriber(
            $connectionHolder,
            $userLookup,
            [$listener1, $listener2, $listener3],
            $this->createStub(ServiceNickReservationInventory::class),
            new NullLogger(),
        );

        $event = new NetworkBurstCompleteEvent($connection, '001');
        $subscriber->onBurstComplete($event);

        self::assertCount(3, $reservedNicks);
        self::assertSame('NickServ', $reservedNicks[0]['nick']);
        self::assertSame('Reserved for network services', $reservedNicks[0]['reason']);
        self::assertSame('ChanServ', $reservedNicks[1]['nick']);
        self::assertSame('MemoServ', $reservedNicks[2]['nick']);
    }

    #[Test]
    public function onBurstCompleteKillsUserWithServiceNick(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);

        $connectionHolder = new ActiveConnectionHolder();

        $reservation = $this->createStub(ServiceNickReservationInterface::class);

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())
            ->method('killUser')
            ->with('001', '001USER1', 'Service nickname reserved');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getNickReservation')->willReturn($reservation);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolderReflection = new ReflectionClass($connectionHolder);
        $moduleProperty = $connectionHolderReflection->getProperty('protocolModule');
        $moduleProperty->setValue($connectionHolder, $module);

        $existingUser = new SenderView(
            uid: '001USER1',
            nick: 'NickServ',
            ident: 'testuser',
            hostname: 'test.host',
            cloakedHost: 'test.cloaked',
            ipBase64: '',
            isIdentified: false,
            isOper: false,
            serverSid: '001',
            displayHost: 'test.host',
        );

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')
            ->willReturnCallback(static fn (string $nick): ?SenderView => 'NickServ' === $nick ? $existingUser : null);

        $listener = $this->createStub(ServiceCommandListenerInterface::class);
        $listener->method('getServiceName')->willReturn('NickServ');

        $subscriber = new ServiceNickReservationSubscriber(
            $connectionHolder,
            $userLookup,
            [$listener],
            $this->createStub(ServiceNickReservationInventory::class),
            new NullLogger(),
        );

        $event = new NetworkBurstCompleteEvent($connection, '001');
        $subscriber->onBurstComplete($event);
    }

    #[Test]
    public function onBurstCompleteDoesNothingWhenNoProtocolModule(): void
    {
        self::expectNotToPerformAssertions();

        $connection = $this->createStub(ConnectionInterface::class);
        $connectionHolder = new ActiveConnectionHolder();

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $listener = $this->createStub(ServiceCommandListenerInterface::class);

        $subscriber = new ServiceNickReservationSubscriber(
            $connectionHolder,
            $userLookup,
            [$listener],
            $this->createStub(ServiceNickReservationInventory::class),
            new NullLogger(),
        );

        $event = new NetworkBurstCompleteEvent($connection, '001');

        $subscriber->onBurstComplete($event);
    }

    #[Test]
    public function onBurstCompleteDoesNothingWhenReservationNull(): void
    {
        self::expectNotToPerformAssertions();

        $connection = $this->createStub(ConnectionInterface::class);

        $connectionHolder = new ActiveConnectionHolder();

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getNickReservation')->willReturn(null);

        $connectionHolderReflection = new ReflectionClass($connectionHolder);
        $moduleProperty = $connectionHolderReflection->getProperty('protocolModule');
        $moduleProperty->setValue($connectionHolder, $module);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $listener = $this->createStub(ServiceCommandListenerInterface::class);

        $subscriber = new ServiceNickReservationSubscriber(
            $connectionHolder,
            $userLookup,
            [$listener],
            $this->createStub(ServiceNickReservationInventory::class),
            new NullLogger(),
        );

        $event = new NetworkBurstCompleteEvent($connection, '001');

        $subscriber->onBurstComplete($event);
    }

    #[Test]
    public function onBurstCompleteDoesNotReleaseTrackedWireReservationReplacedByOperatorBan(): void
    {
        $reservation = $this->createMock(ServiceNickReservationInterface::class);
        $reservation->expects(self::never())->method('releaseNick');
        $reservation->expects(self::once())->method('reserveNick')->with('NewOper', 'Reserved for network services');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Obsolete wire service nickname reservation requires manual review and removal; current ownership cannot be verified',
            ['nick' => 'OldOper', 'protocol' => 'unreal'],
        );

        $snapshots = [];
        $inventory = $this->createMock(ServiceNickReservationInventory::class);
        $inventory->expects(self::once())->method('namesForProtocol')->with('unreal')->willReturn(['OldOper', 'newoper']);
        $inventory->expects(self::once())->method('replaceForProtocol')
            ->willReturnCallback(static function (string $protocol, array $names) use (&$snapshots): void {
                TestCase::assertSame('unreal', $protocol);
                $snapshots[] = $names;
            });

        $this->subscriberFor($reservation, $inventory, ['NewOper'], 'unreal', $logger)
            ->onBurstComplete($this->burstEvent());

        self::assertSame([['OldOper', 'newoper']], $snapshots);
    }

    #[Test]
    public function onBurstCompleteDiscoversLegacyManagedReservationsWhenProtocolSupportsIt(): void
    {
        $reservation = new class implements ServiceNickReservationInterface, ManagedServiceNickReservationLookup {
            /** @var list<string> */
            public array $released = [];

            /** @var list<string> */
            public array $reserved = [];

            public function findManagedServiceNicks(string $reason): array
            {
                TestCase::assertSame('Reserved for network services', $reason);

                return ['OldNick', 'nEwNiCk'];
            }

            public function reserveNick(string $nick, string $reason): void
            {
                $this->reserved[] = $nick;
            }

            public function reserveNickWithDuration(string $nick, int $durationSeconds, string $reason): void {}

            public function releaseNick(string $nick): void
            {
                $this->released[] = $nick;
            }
        };

        $inventory = $this->createMock(ServiceNickReservationInventory::class);
        $inventory->method('namesForProtocol')->willReturn([]);
        $inventory->expects(self::once())->method('replaceForProtocol')->with('unrealudb', ['NewNick']);

        $this->subscriberFor($reservation, $inventory, ['NewNick'], 'unrealudb')
            ->onBurstComplete($this->burstEvent());

        self::assertSame(['OldNick'], $reservation->released);
        self::assertSame(['NewNick'], $reservation->reserved);
    }

    #[Test]
    public function onBurstCompleteDoesNotReleaseHistoricalUdbReservationReplacedByOperatorBan(): void
    {
        $reservation = $this->createMockForIntersectionOfInterfaces([
            ServiceNickReservationInterface::class,
            ManagedServiceNickReservationLookup::class,
        ]);
        $reservation->expects(self::once())->method('findManagedServiceNicks')
            ->with('Reserved for network services')->willReturn([]);
        $reservation->expects(self::never())->method('releaseNick');
        $reservation->expects(self::once())->method('reserveNick')->with('NewNick', 'Reserved for network services');

        $inventory = $this->createMock(ServiceNickReservationInventory::class);
        $inventory->method('namesForProtocol')->willReturn(['OldNick']);
        $inventory->expects(self::once())->method('replaceForProtocol')->with('unrealudb', ['NewNick']);

        $this->subscriberFor($reservation, $inventory, ['NewNick'], 'unrealudb')
            ->onBurstComplete($this->burstEvent());
    }

    #[Test]
    public function onBurstCompleteDoesNotReserveWhenWireInventoryCannotBeRead(): void
    {
        $reservation = $this->createMock(ServiceNickReservationInterface::class);
        $reservation->expects(self::never())->method('releaseNick');
        $reservation->expects(self::never())->method('reserveNick');

        $inventory = $this->createMock(ServiceNickReservationInventory::class);
        $inventory->method('namesForProtocol')->willThrowException(new RuntimeException('inventory unavailable'));
        $inventory->expects(self::never())->method('replaceForProtocol');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('inventory unavailable');

        $this->subscriberFor($reservation, $inventory, ['NewNick'], 'unreal')
            ->onBurstComplete($this->burstEvent());
    }

    #[Test]
    public function onBurstCompleteCanDiscoverUdbReservationsWhenInventoryCannotBeRead(): void
    {
        $reservation = $this->createMockForIntersectionOfInterfaces([
            ServiceNickReservationInterface::class,
            ManagedServiceNickReservationLookup::class,
        ]);
        $reservation->method('findManagedServiceNicks')->willReturn([]);
        $reservation->expects(self::once())->method('reserveNick')->with('NewNick', 'Reserved for network services');

        $inventory = $this->createMock(ServiceNickReservationInventory::class);
        $inventory->method('namesForProtocol')->willThrowException(new RuntimeException('inventory unavailable'));
        $inventory->expects(self::never())->method('replaceForProtocol');

        $this->subscriberFor($reservation, $inventory, ['NewNick'], 'unrealudb')
            ->onBurstComplete($this->burstEvent());
    }

    #[Test]
    public function onBurstCompletePersistsWireNamesBeforeNetworkEffects(): void
    {
        $events = [];
        $reservation = $this->createMock(ServiceNickReservationInterface::class);
        $reservation->expects(self::never())->method('releaseNick');
        $reservation->expects(self::once())->method('reserveNick')
            ->willReturnCallback(static function (string $nick, string $reason) use (&$events): void {
                TestCase::assertSame('Reserved for network services', $reason);
                $events[] = ['reserve', $nick];
            });

        $inventory = $this->createMock(ServiceNickReservationInventory::class);
        $inventory->method('namesForProtocol')->willReturn(['OldNick']);
        $inventory->expects(self::once())->method('replaceForProtocol')
            ->willReturnCallback(static function (string $protocol, array $names) use (&$events): void {
                TestCase::assertSame('inspircd', $protocol);
                $events[] = ['inventory', $names];
            });

        $this->subscriberFor($reservation, $inventory, ['NewNick'], 'inspircd')
            ->onBurstComplete($this->burstEvent());

        self::assertSame([
            ['inventory', ['OldNick', 'NewNick']],
            ['reserve', 'NewNick'],
        ], $events);
    }

    #[Test]
    public function onBurstCompleteTracksFailedReleaseAndNewReservationForRetry(): void
    {
        $reservation = $this->createMockForIntersectionOfInterfaces([
            ServiceNickReservationInterface::class,
            ManagedServiceNickReservationLookup::class,
        ]);
        $reservation->method('findManagedServiceNicks')->willReturn(['OldNick']);
        $reservation->expects(self::once())->method('releaseNick')->with('OldNick')
            ->willThrowException(new RuntimeException('network unavailable'));
        $reservation->expects(self::once())->method('reserveNick')->with('NewNick', 'Reserved for network services');

        $snapshots = [];
        $inventory = $this->createMock(ServiceNickReservationInventory::class);
        $inventory->method('namesForProtocol')->willReturn(['OldNick']);
        $inventory->expects(self::once())->method('replaceForProtocol')
            ->willReturnCallback(static function (string $protocol, array $names) use (&$snapshots): void {
                TestCase::assertSame('unrealudb', $protocol);
                $snapshots[] = $names;
            });

        $this->subscriberFor($reservation, $inventory, ['NewNick'], 'unrealudb')
            ->onBurstComplete($this->burstEvent());

        self::assertSame([['OldNick', 'NewNick']], $snapshots);
    }

    #[Test]
    public function onBurstCompleteKeepsTrackedWireNamesAfterSecondRename(): void
    {
        $reservation = new class implements ServiceNickReservationInterface {
            /** @var list<string> */
            public array $released = [];

            public function reserveNick(string $nick, string $reason): void {}

            public function reserveNickWithDuration(string $nick, int $durationSeconds, string $reason): void {}

            public function releaseNick(string $nick): void
            {
                $this->released[] = $nick;
            }
        };

        $inventory = new class implements ServiceNickReservationInventory {
            /** @var list<string> */
            public array $names = ['OldNick'];

            public function namesForProtocol(string $protocol): array
            {
                return $this->names;
            }

            public function replaceForProtocol(string $protocol, array $nicknames): void
            {
                $this->names = $nicknames;
            }
        };

        $this->subscriberFor($reservation, $inventory, ['NewNick'], 'inspircd')
            ->onBurstComplete($this->burstEvent());
        self::assertSame(['OldNick', 'NewNick'], $inventory->names);

        $this->subscriberFor($reservation, $inventory, ['NextNick'], 'inspircd')
            ->onBurstComplete($this->burstEvent());

        self::assertSame([], $reservation->released);
        self::assertSame(['OldNick', 'NewNick', 'NextNick'], $inventory->names);
    }

    #[Test]
    public function onBurstCompleteStillReservesWhenLegacyDiscoveryFails(): void
    {
        $reservation = $this->createMockForIntersectionOfInterfaces([
            ServiceNickReservationInterface::class,
            ManagedServiceNickReservationLookup::class,
        ]);
        $reservation->method('findManagedServiceNicks')->willThrowException(new RuntimeException('store unavailable'));
        $reservation->expects(self::never())->method('releaseNick');
        $reservation->expects(self::once())->method('reserveNick')->with('NewNick', 'Reserved for network services');

        $inventory = $this->createMock(ServiceNickReservationInventory::class);
        $inventory->method('namesForProtocol')->willReturn(['OldNick']);
        $inventory->expects(self::never())->method('replaceForProtocol');

        $this->subscriberFor($reservation, $inventory, ['NewNick'], 'unrealudb')
            ->onBurstComplete($this->burstEvent());
    }

    #[Test]
    public function onBurstCompleteContinuesAfterUdbInventoryWriteFails(): void
    {
        $reservation = $this->createMockForIntersectionOfInterfaces([
            ServiceNickReservationInterface::class,
            ManagedServiceNickReservationLookup::class,
        ]);
        $reservation->method('findManagedServiceNicks')->willReturn([]);
        $reservation->expects(self::never())->method('releaseNick');
        $reservation->expects(self::once())->method('reserveNick')->with('NewNick', 'Reserved for network services');

        $inventory = $this->createMock(ServiceNickReservationInventory::class);
        $inventory->method('namesForProtocol')->willReturn([]);
        $inventory->expects(self::once())->method('replaceForProtocol')->with('unrealudb', ['NewNick'])
            ->willThrowException(new RuntimeException('database unavailable'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Could not save service nickname reservation inventory',
            ['protocol' => 'unrealudb', 'exception' => 'database unavailable'],
        );
        $logger->expects(self::once())->method('info')->with('Reserved service nicknames', ['count' => 1]);

        $this->subscriberFor($reservation, $inventory, ['NewNick'], 'unrealudb', $logger)
            ->onBurstComplete($this->burstEvent());
    }

    #[Test]
    public function onBurstCompleteDoesNotReserveWhenWireInventoryPreparationFails(): void
    {
        $reservation = $this->createMock(ServiceNickReservationInterface::class);
        $reservation->expects(self::never())->method('releaseNick');
        $reservation->expects(self::never())->method('reserveNick');

        $inventory = $this->createMock(ServiceNickReservationInventory::class);
        $inventory->method('namesForProtocol')->willReturn([]);
        $inventory->expects(self::once())->method('replaceForProtocol')
            ->willThrowException(new RuntimeException('database unavailable'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database unavailable');

        $this->subscriberFor($reservation, $inventory, ['NewNick'], 'unreal')
            ->onBurstComplete($this->burstEvent());
    }

    #[Test]
    public function onBurstCompleteKeepsWireIntentionsWhenReservationFails(): void
    {
        $reservation = new class implements ServiceNickReservationInterface {
            /** @var list<string> */
            public array $released = [];

            public int $reserveCalls = 0;

            public function reserveNick(string $nick, string $reason): void
            {
                ++$this->reserveCalls;
                if (1 === $this->reserveCalls) {
                    throw new RuntimeException('network unavailable');
                }
            }

            public function reserveNickWithDuration(string $nick, int $durationSeconds, string $reason): void {}

            public function releaseNick(string $nick): void
            {
                $this->released[] = $nick;
            }
        };

        $inventory = new class implements ServiceNickReservationInventory {
            /** @var list<string> */
            public array $names = ['OldNick'];

            public int $writes = 0;

            public function namesForProtocol(string $protocol): array
            {
                return $this->names;
            }

            public function replaceForProtocol(string $protocol, array $nicknames): void
            {
                ++$this->writes;
                $this->names = $nicknames;
            }
        };

        try {
            $this->subscriberFor($reservation, $inventory, ['NewNick'], 'inspircd')
                ->onBurstComplete($this->burstEvent());
            self::fail('Expected the first reservation to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('network unavailable', $exception->getMessage());
        }

        self::assertSame(['OldNick', 'NewNick'], $inventory->names);
        self::assertSame(1, $inventory->writes);

        $this->subscriberFor($reservation, $inventory, ['NextNick'], 'inspircd')
            ->onBurstComplete($this->burstEvent());

        self::assertSame([], $reservation->released);
        self::assertSame(['OldNick', 'NewNick', 'NextNick'], $inventory->names);
        self::assertSame(2, $inventory->writes);
    }

    /** @param list<string> $names */
    private function subscriberFor(
        ServiceNickReservationInterface $reservation,
        ServiceNickReservationInventory $inventory,
        array $names,
        string $protocol,
        LoggerInterface $logger = new NullLogger(),
    ): ServiceNickReservationSubscriber {
        $holder = new ActiveConnectionHolder();
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getProtocolName')->willReturn($protocol);
        $module->method('getNickReservation')->willReturn($reservation);
        $module->method('getServiceActions')->willReturn($this->createStub(ProtocolServiceActionsInterface::class));
        new ReflectionClass($holder)->getProperty('protocolModule')->setValue($holder, $module);

        $listeners = [];
        foreach ($names as $name) {
            $listener = $this->createStub(ServiceCommandListenerInterface::class);
            $listener->method('getServiceName')->willReturn($name);
            $listeners[] = $listener;
        }

        return new ServiceNickReservationSubscriber(
            $holder,
            $this->createStub(NetworkUserLookupPort::class),
            $listeners,
            $inventory,
            $logger,
        );
    }

    private function burstEvent(): NetworkBurstCompleteEvent
    {
        return new NetworkBurstCompleteEvent($this->createStub(ConnectionInterface::class), '001');
    }
}
