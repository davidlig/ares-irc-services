<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Subscriber;

use App\Application\Event\UserJoinedNetworkAppEvent;
use App\Application\Port\UserJoinedNetworkDTO;
use App\Domain\IRC\Event\UserJoinedNetworkEvent;
use App\Domain\IRC\Network\NetworkUser;
use App\Domain\IRC\ValueObject\Ident;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;
use App\Infrastructure\IRC\Subscriber\CoreToAppEventBridge;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[CoversClass(CoreToAppEventBridge::class)]
#[CoversClass(UserJoinedNetworkAppEvent::class)]
#[CoversClass(UserJoinedNetworkDTO::class)]
final class CoreToAppEventBridgeTest extends TestCase
{
    #[Test]
    public function getSubscribedEventsReturnsExpectedEvents(): void
    {
        $events = CoreToAppEventBridge::getSubscribedEvents();

        self::assertSame(
            [UserJoinedNetworkEvent::class => ['onUserJoinedNetwork', -128]],
            $events,
        );
    }

    #[Test]
    public function onUserJoinedNetworkDispatchesAppEventWithCorrectDto(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $bridge = new CoreToAppEventBridge($eventDispatcher);

        $user = new NetworkUser(
            uid: new Uid('001ABC'),
            nick: new Nick('TestUser'),
            ident: new Ident('testuser'),
            hostname: 'real.host.example.com',
            cloakedHost: 'cloak.host.example.com',
            virtualHost: 'vhost.example.com',
            modes: '+ixr',
            connectedAt: new DateTimeImmutable('2024-01-15 10:30:00'),
            realName: 'Test User',
            serverSid: '001',
            ipBase64: 'dGVzdGlw',
            serviceStamp: 123456,
        );

        $capturedAppEvent = null;
        $eventDispatcher
            ->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (UserJoinedNetworkAppEvent $event) use (&$capturedAppEvent): UserJoinedNetworkAppEvent {
                $capturedAppEvent = $event;

                return $event;
            });

        $coreEvent = new UserJoinedNetworkEvent($user);
        $bridge->onUserJoinedNetwork($coreEvent);

        self::assertNotNull($capturedAppEvent);
        $dto = $capturedAppEvent->user;

        self::assertSame('001ABC', $dto->uid);
        self::assertSame('TestUser', $dto->nick);
        self::assertSame('testuser', $dto->ident);
        self::assertSame('real.host.example.com', $dto->hostname);
        self::assertSame('cloak.host.example.com', $dto->cloakedHost);
        self::assertSame('dGVzdGlw', $dto->ipBase64);
        self::assertSame('vhost.example.com', $dto->displayHost);
        self::assertTrue($dto->isIdentified);
        self::assertFalse($dto->isOper);
        self::assertSame('001', $dto->serverSid);
    }

    #[Test]
    public function onUserJoinedNetworkWithOperUserSetsIsOperTrue(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $bridge = new CoreToAppEventBridge($eventDispatcher);

        $user = new NetworkUser(
            uid: new Uid('002XYZ'),
            nick: new Nick('OperUser'),
            ident: new Ident('oper'),
            hostname: 'oper.host.example.com',
            cloakedHost: 'cloak.oper.example.com',
            virtualHost: '',
            modes: '+io',
            connectedAt: new DateTimeImmutable('2024-01-15 11:00:00'),
            realName: 'IRC Operator',
            serverSid: '001',
            ipBase64: 'b3BlcmF0b3I=',
        );

        $capturedAppEvent = null;
        $eventDispatcher
            ->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (UserJoinedNetworkAppEvent $event) use (&$capturedAppEvent): UserJoinedNetworkAppEvent {
                $capturedAppEvent = $event;

                return $event;
            });

        $coreEvent = new UserJoinedNetworkEvent($user);
        $bridge->onUserJoinedNetwork($coreEvent);

        self::assertNotNull($capturedAppEvent);
        self::assertTrue($capturedAppEvent->user->isOper);
        self::assertFalse($capturedAppEvent->user->isIdentified);
    }

    #[Test]
    public function onUserJoinedNetworkWithCloakedHostAsDisplayHost(): void
    {
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $bridge = new CoreToAppEventBridge($eventDispatcher);

        $user = new NetworkUser(
            uid: new Uid('003DEF'),
            nick: new Nick('RegularUser'),
            ident: new Ident('regular'),
            hostname: 'regular.host.example.com',
            cloakedHost: 'cloak.regular.example.com',
            virtualHost: '*',
            modes: '+i',
            connectedAt: new DateTimeImmutable('2024-01-15 12:00:00'),
            realName: 'Regular User',
            serverSid: '002',
            ipBase64: 'cmVndWxhcg==',
        );

        $capturedAppEvent = null;
        $eventDispatcher
            ->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (UserJoinedNetworkAppEvent $event) use (&$capturedAppEvent): UserJoinedNetworkAppEvent {
                $capturedAppEvent = $event;

                return $event;
            });

        $coreEvent = new UserJoinedNetworkEvent($user);
        $bridge->onUserJoinedNetwork($coreEvent);

        self::assertNotNull($capturedAppEvent);
        self::assertSame('cloak.regular.example.com', $capturedAppEvent->user->displayHost);
    }
}
