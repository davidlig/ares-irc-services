<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Event;

use App\ChanServ\Adapter\In\Event\ChanServEntryMsgSubscriber;
use App\ChanServ\Adapter\Out\Network\IrcChannelEntryMessageDelivery;
use App\ChanServ\Adapter\Out\Network\ServiceRegistryChanServNetworkIdentity;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\UseCase\DeliverEntryMessage\DeliverChannelEntryMessage;
use App\ChanServ\Application\UseCase\DeliverEntryMessage\DeliverChannelEntryMessageHandler;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Application\Port\In\SendNoticePort;
use App\Irc\Application\Port\In\ServiceUidProviderInterface;
use App\Irc\Application\Port\In\ServiceUidRegistry;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServEntryMsgSubscriber::class)]
#[CoversClass(IrcChannelEntryMessageDelivery::class)]
#[CoversClass(ServiceRegistryChanServNetworkIdentity::class)]
#[CoversClass(DeliverChannelEntryMessage::class)]
#[CoversClass(DeliverChannelEntryMessageHandler::class)]
final class ChanServEntryMsgSubscriberTest extends TestCase
{
    private MockObject&RegisteredChannelRepositoryInterface $channelRepository;

    private MockObject&SendNoticePort $notifier;

    private ChanServEntryMsgSubscriber $subscriber;

    private string $chanservUid = '001CHAN';

    protected function setUp(): void
    {
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->notifier = $this->createMock(SendNoticePort::class);
        $uidRegistry = $this->createUidRegistry();
        $this->subscriber = new ChanServEntryMsgSubscriber(
            new DeliverChannelEntryMessageHandler(
                $this->channelRepository,
                new IrcChannelEntryMessageDelivery($this->notifier, $uidRegistry),
                new ServiceRegistryChanServNetworkIdentity($uidRegistry),
            ),
        );
    }

    private function createUidRegistry(string $uid = '001CHAN'): ServiceUidRegistry
    {
        $provider = new class('chanserv', 'ChanServ', $uid) implements ServiceUidProviderInterface {
            public function __construct(private string $key, private string $nick, private string $uid) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }

            public function getUid(): string
            {
                return $this->uid;
            }
        };

        return ServiceUidRegistry::fromIterable([$provider]);
    }

    #[Test]
    public function subscribesToUserJoinedChannelEvent(): void
    {
        $this->channelRepository->expects(self::never())->method('findByChannelName');
        $this->notifier->expects(self::never())->method('sendNotice');

        self::assertSame(
            [UserJoinedChannelEvent::class => ['onUserJoinedChannel', 0]],
            ChanServEntryMsgSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function sendsEntryMsgWhenChannelHasEntryMessage(): void
    {
        $event = new UserJoinedChannelEvent('001ABCD', '#test');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getEntrymsg')->willReturn('Welcome to #test!');

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($channel);

        $this->notifier
            ->expects(self::once())
            ->method('sendNotice')
            ->with('001CHAN', '001ABCD', "[\x0303#test\x03] Welcome to #test!");

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function doesNotSendNoticeWhenChannelNotRegistered(): void
    {
        $event = new UserJoinedChannelEvent('001ABCD', '#unregistered');

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#unregistered')
            ->willReturn(null);

        $this->notifier
            ->expects(self::never())
            ->method('sendNotice');

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function doesNotSendNoticeWhenEntryMessageIsEmpty(): void
    {
        $event = new UserJoinedChannelEvent('001ABCD', '#test');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getEntrymsg')->willReturn('');

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($channel);

        $this->notifier
            ->expects(self::never())
            ->method('sendNotice');

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function doesNotSendNoticeWhenUserIsChanServ(): void
    {
        $event = new UserJoinedChannelEvent($this->chanservUid, '#test');

        $this->channelRepository
            ->expects(self::never())
            ->method('findByChannelName');

        $this->notifier
            ->expects(self::never())
            ->method('sendNotice');

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function handlesChannelNameCaseInsensitively(): void
    {
        $event = new UserJoinedChannelEvent('001ABCD', '#TeSt');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getEntrymsg')->willReturn('Welcome!');

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($channel);

        $this->notifier
            ->expects(self::once())
            ->method('sendNotice');

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function sendsEntryMsgWithSpecialCharacters(): void
    {
        $event = new UserJoinedChannelEvent('001ABCD', '#test');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getEntrymsg')->willReturn('Welcome to #test! Enjoy your stay :)');

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($channel);

        $this->notifier
            ->expects(self::once())
            ->method('sendNotice')
            ->with('001CHAN', '001ABCD', "[\x0303#test\x03] Welcome to #test! Enjoy your stay :)");

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function doesNotSendWhenChanservUidExactMatch(): void
    {
        $event = new UserJoinedChannelEvent($this->chanservUid, '#test');

        $this->channelRepository
            ->expects(self::never())
            ->method('findByChannelName');

        $this->notifier
            ->expects(self::never())
            ->method('sendNotice');

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function sendsEntryMsgForDifferentChannelRoles(): void
    {
        $event = new UserJoinedChannelEvent('001ABCD', '#test');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getEntrymsg')->willReturn('Welcome operator!');

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($channel);

        $this->notifier
            ->expects(self::once())
            ->method('sendNotice')
            ->with('001CHAN', '001ABCD', "[\x0303#test\x03] Welcome operator!");

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function sendsEntryMsgForVoiceRole(): void
    {
        $event = new UserJoinedChannelEvent('001ABCD', '#test');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getEntrymsg')->willReturn('Welcome!');

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($channel);

        $this->notifier
            ->expects(self::once())
            ->method('sendNotice');

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function entryMsgWithEmptyStringFromRegisteredChannel(): void
    {
        $event = new UserJoinedChannelEvent('001ABCD', '#test');

        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getEntrymsg')->willReturn('');

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($channel);

        $this->notifier
            ->expects(self::never())
            ->method('sendNotice');

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function doesNotSendNoticeWhenChannelIsSuspended(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('isSuspended')->willReturn(true);
        $channel->method('isBlocked')->willReturn(true);

        $this->channelRepository
            ->expects(self::once())
            ->method('findByChannelName')
            ->with('#test')
            ->willReturn($channel);
        $this->notifier
            ->expects(self::never())
            ->method('sendNotice');

        $event = new UserJoinedChannelEvent('001ABCD', '#test');
        $this->subscriber->onUserJoinedChannel($event);
    }
}
