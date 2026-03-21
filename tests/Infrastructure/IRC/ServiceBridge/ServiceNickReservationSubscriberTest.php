<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\ServiceBridge;

use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Application\Port\SenderView;
use App\Application\Port\ServiceCommandListenerInterface;
use App\Application\Port\ServiceNickReservationInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\ServiceBridge\ServiceNickReservationSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

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
            ->willReturnCallback(static function (ConnectionInterface $conn, string $serverSid, string $nick, string $reason) use (&$reservedNicks): void {
                $reservedNicks[] = ['serverSid' => $serverSid, 'nick' => $nick, 'reason' => $reason];
            });

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getNickReservation')->willReturn($reservation);

        $connectionHolderReflection = new ReflectionClass($connectionHolder);
        $moduleProperty = $connectionHolderReflection->getProperty('protocolModule');
        $moduleProperty->setAccessible(true);
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
            new NullLogger(),
        );

        $event = new NetworkBurstCompleteEvent($connection, '001');
        $subscriber->onBurstComplete($event);

        self::assertCount(3, $reservedNicks);
        self::assertSame('001', $reservedNicks[0]['serverSid']);
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
        $moduleProperty->setAccessible(true);
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
            new NullLogger(),
        );

        $event = new NetworkBurstCompleteEvent($connection, '001');
        $subscriber->onBurstComplete($event);
    }

    #[Test]
    public function onBurstCompleteDoesNothingWhenNoProtocolModule(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connectionHolder = new ActiveConnectionHolder();

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $listener = $this->createStub(ServiceCommandListenerInterface::class);

        $subscriber = new ServiceNickReservationSubscriber(
            $connectionHolder,
            $userLookup,
            [$listener],
            new NullLogger(),
        );

        $event = new NetworkBurstCompleteEvent($connection, '001');

        $subscriber->onBurstComplete($event);

        self::assertTrue(true);
    }

    #[Test]
    public function onBurstCompleteDoesNothingWhenReservationNull(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);

        $connectionHolder = new ActiveConnectionHolder();

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getNickReservation')->willReturn(null);

        $connectionHolderReflection = new ReflectionClass($connectionHolder);
        $moduleProperty = $connectionHolderReflection->getProperty('protocolModule');
        $moduleProperty->setAccessible(true);
        $moduleProperty->setValue($connectionHolder, $module);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $listener = $this->createStub(ServiceCommandListenerInterface::class);

        $subscriber = new ServiceNickReservationSubscriber(
            $connectionHolder,
            $userLookup,
            [$listener],
            new NullLogger(),
        );

        $event = new NetworkBurstCompleteEvent($connection, '001');

        $subscriber->onBurstComplete($event);

        self::assertTrue(true);
    }
}
