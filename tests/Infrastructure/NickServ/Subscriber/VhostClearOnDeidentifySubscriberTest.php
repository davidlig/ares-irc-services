<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Subscriber;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\IRC\Event\UserModeChangedEvent;
use App\Domain\IRC\ValueObject\Uid;
use App\Infrastructure\NickServ\Subscriber\VhostClearOnDeidentifySubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(VhostClearOnDeidentifySubscriber::class)]
final class VhostClearOnDeidentifySubscriberTest extends TestCase
{
    private NetworkUserLookupPort&MockObject $userLookup;

    private NickServNotifierInterface&MockObject $notifier;

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
            [UserModeChangedEvent::class => ['onUserModeChanged', 0]],
            VhostClearOnDeidentifySubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function clearsVhostWhenUserLosesIdentifiedMode(): void
    {
        $event = new UserModeChangedEvent(
            uid: new Uid('001ABCD'),
            modeDelta: '-r',
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
    public function doesNothingWhenModeIsNotMinusR(): void
    {
        $event = new UserModeChangedEvent(
            uid: new Uid('001ABCD'),
            modeDelta: '+r',
        );

        $this->userLookup
            ->expects(self::never())
            ->method('findByUid');

        $this->notifier
            ->expects(self::never())
            ->method('setUserVhost');

        $this->subscriber->onUserModeChanged($event);
    }

    #[Test]
    public function doesNothingWhenUserNotFound(): void
    {
        $event = new UserModeChangedEvent(
            uid: new Uid('001ABCD'),
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
        $event = new UserModeChangedEvent(
            uid: new Uid('001ABCD'),
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
