<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Event;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\PublishedEvent\UserModesChangedEvent;
use App\NickServ\Adapter\In\Event\VhostClearOnDeidentifySubscriber;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(VhostClearOnDeidentifySubscriber::class)]
final class VhostClearOnDeidentifySubscriberTest extends TestCase
{
    private MockObject&NetworkUserLookupPort $userLookup;

    private MockObject&NickServNotifierInterface $notifier;

    private VhostClearOnDeidentifySubscriber $subscriber;

    protected function setUp(): void
    {
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->notifier = $this->createMock(NickServNotifierInterface::class);
        $this->subscriber = new VhostClearOnDeidentifySubscriber(
            $this->userLookup,
            $this->notifier,
        );
    }

    #[Test]
    public function subscribesToUserModeChangedEvent(): void
    {
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $this->notifier->expects(self::never())->method('sendMessage');

        self::assertSame(
            [UserModesChangedEvent::class => ['onUserModeChanged', 0]],
            VhostClearOnDeidentifySubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function clearsVhostWhenUserLosesIdentifiedMode(): void
    {
        $event = new UserModesChangedEvent(
            uid: '001ABCD',
            modeDelta: '-ir',
        );

        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'test.local',
            cloakedHost: 'test.local',
            ipBase64: 'dGVzdA==',
            isIdentified: false,
            serverSid: '001',
        );

        $this->userLookup
            ->expects(self::once())
            ->method('findByUid')
            ->with('001ABCD')
            ->willReturn($sender);

        $this->notifier
            ->expects(self::once())
            ->method('setUserVhost')
            ->with('001ABCD', '', '001');

        $this->subscriber->onUserModeChanged($event);
    }

    #[Test]
    public function doesNothingWhenTheFinalNetworkStateIsStillIdentified(): void
    {
        $event = new UserModesChangedEvent(
            uid: '001ABCD',
            modeDelta: '+r',
        );

        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'test.local',
            cloakedHost: 'test.local',
            ipBase64: 'dGVzdA==',
            isIdentified: true,
            serverSid: '001',
        );

        $this->userLookup
            ->expects(self::once())
            ->method('findByUid')
            ->with('001ABCD')
            ->willReturn($sender);

        $this->notifier
            ->expects(self::never())
            ->method('setUserVhost');

        $this->subscriber->onUserModeChanged($event);
    }

    #[Test]
    public function doesNothingWhenUserNotFound(): void
    {
        $event = new UserModesChangedEvent(
            uid: '001ABCD',
            modeDelta: '-r',
        );

        $this->userLookup
            ->expects(self::once())
            ->method('findByUid')
            ->with('001ABCD')
            ->willReturn(null);

        $this->notifier
            ->expects(self::never())
            ->method('setUserVhost');

        $this->subscriber->onUserModeChanged($event);
    }

    #[Test]
    public function ignoresOtherModeChanges(): void
    {
        $event = new UserModesChangedEvent(
            uid: '001ABCD',
            modeDelta: '+i',
        );

        $this->userLookup
            ->expects(self::never())
            ->method('findByUid');

        $this->notifier
            ->expects(self::never())
            ->method('setUserVhost');

        $this->subscriber->onUserModeChanged($event);
    }
}
