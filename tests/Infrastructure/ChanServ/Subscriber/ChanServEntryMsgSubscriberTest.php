<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ChanServ\Subscriber;

use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;
use App\Infrastructure\ChanServ\Subscriber\ChanServEntryMsgSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChanServEntryMsgSubscriber::class)]
final class ChanServEntryMsgSubscriberTest extends TestCase
{
    private RegisteredChannelRepositoryInterface&MockObject $channelRepository;

    private ChanServNotifierInterface&MockObject $notifier;

    private ChanServEntryMsgSubscriber $subscriber;

    private string $chanservUid = '001CHAN';

    protected function setUp(): void
    {
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->notifier = $this->createMock(ChanServNotifierInterface::class);
        $this->subscriber = new ChanServEntryMsgSubscriber(
            $this->channelRepository,
            $this->notifier,
            $this->chanservUid,
        );
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
        $event = new UserJoinedChannelEvent(
            uid: new Uid('001ABCD'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::None,
        );

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
            ->with('001ABCD', "[\x0303#test\x03] Welcome to #test!");

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function doesNotSendNoticeWhenChannelNotRegistered(): void
    {
        $event = new UserJoinedChannelEvent(
            uid: new Uid('001ABCD'),
            channel: new ChannelName('#unregistered'),
            role: ChannelMemberRole::None,
        );

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
        $event = new UserJoinedChannelEvent(
            uid: new Uid('001ABCD'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::None,
        );

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
        $event = new UserJoinedChannelEvent(
            uid: new Uid($this->chanservUid),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::None,
        );

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
        $event = new UserJoinedChannelEvent(
            uid: new Uid('001ABCD'),
            channel: new ChannelName('#TeSt'),
            role: ChannelMemberRole::None,
        );

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
        $event = new UserJoinedChannelEvent(
            uid: new Uid('001ABCD'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::None,
        );

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
            ->with('001ABCD', "[\x0303#test\x03] Welcome to #test! Enjoy your stay :)");

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function doesNotSendWhenChanservUidExactMatch(): void
    {
        $event = new UserJoinedChannelEvent(
            uid: new Uid($this->chanservUid),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::None,
        );

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
        $event = new UserJoinedChannelEvent(
            uid: new Uid('001ABCD'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::Op,
        );

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
            ->with('001ABCD', "[\x0303#test\x03] Welcome operator!");

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function sendsEntryMsgForVoiceRole(): void
    {
        $event = new UserJoinedChannelEvent(
            uid: new Uid('001ABCD'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::Voice,
        );

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
        $event = new UserJoinedChannelEvent(
            uid: new Uid('001ABCD'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::None,
        );

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
}
