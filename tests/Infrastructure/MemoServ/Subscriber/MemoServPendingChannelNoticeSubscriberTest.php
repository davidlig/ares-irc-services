<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\MemoServ\Subscriber;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\MemoServ\Command\MemoServNotifierInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;
use App\Domain\MemoServ\Repository\MemoRepositoryInterface;
use App\Domain\MemoServ\Repository\MemoSettingsRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\MemoServ\Subscriber\MemoServPendingChannelNoticeSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(MemoServPendingChannelNoticeSubscriber::class)]
final class MemoServPendingChannelNoticeSubscriberTest extends TestCase
{
    private const CHANNEL_ID = 1;

    private const NICK_ID = 10;

    private const MEMOSERV_UID = '001MEMO';

    private RegisteredChannelRepositoryInterface&MockObject $channelRepository;

    private RegisteredNickRepositoryInterface&MockObject $nickRepository;

    private MemoRepositoryInterface&MockObject $memoRepository;

    private MemoSettingsRepositoryInterface&MockObject $memoSettingsRepository;

    private ChannelAccessRepositoryInterface&MockObject $accessRepository;

    private ChannelLevelRepositoryInterface&MockObject $levelRepository;

    private ChanServAccessHelper $accessHelper;

    private MemoServNotifierInterface&MockObject $notifier;

    private NetworkUserLookupPort&MockObject $userLookup;

    private TranslatorInterface&MockObject $translator;

    private MemoServPendingChannelNoticeSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->memoRepository = $this->createMock(MemoRepositoryInterface::class);
        $this->memoSettingsRepository = $this->createMock(MemoSettingsRepositoryInterface::class);
        $this->accessRepository = $this->createMock(ChannelAccessRepositoryInterface::class);
        $this->levelRepository = $this->createMock(ChannelLevelRepositoryInterface::class);
        $this->accessHelper = new ChanServAccessHelper($this->accessRepository, $this->levelRepository);
        $this->notifier = $this->createMock(MemoServNotifierInterface::class);
        $this->userLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->translator = $this->createMock(TranslatorInterface::class);

        $this->subscriber = new MemoServPendingChannelNoticeSubscriber(
            $this->channelRepository,
            $this->nickRepository,
            $this->memoRepository,
            $this->memoSettingsRepository,
            $this->accessHelper,
            $this->notifier,
            $this->userLookup,
            $this->translator,
            self::MEMOSERV_UID,
            'en',
        );
    }

    #[Test]
    public function getSubscribedEventsReturnsUserJoinedChannelWithPriority(): void
    {
        $this->channelRepository->expects(self::never())->method('findByChannelName');
        $this->nickRepository->expects(self::never())->method('findByNick');
        $this->memoRepository->expects(self::never())->method('countUnreadByTargetChannel');
        $this->memoSettingsRepository->expects(self::never())->method('isEnabledForChannel');
        $this->accessRepository->expects(self::never())->method('findByChannelAndNick');
        $this->levelRepository->expects(self::never())->method('findByChannelAndKey');
        $this->notifier->expects(self::never())->method('sendNotice');
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->translator->expects(self::never())->method('trans');
        self::assertSame(
            [UserJoinedChannelEvent::class => ['onUserJoinedChannel', -10]],
            MemoServPendingChannelNoticeSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function doesNothingWhenUidIsMemoServ(): void
    {
        $event = new UserJoinedChannelEvent(
            uid: new Uid(self::MEMOSERV_UID),
            channel: new ChannelName('#test'),
            role: \App\Domain\IRC\Network\ChannelMemberRole::None,
        );

        $this->channelRepository->expects(self::never())->method('findByChannelName');
        $this->nickRepository->expects(self::never())->method('findByNick');
        $this->memoRepository->expects(self::never())->method('countUnreadByTargetChannel');
        $this->memoSettingsRepository->expects(self::never())->method('isEnabledForChannel');
        $this->accessRepository->expects(self::never())->method('findByChannelAndNick');
        $this->levelRepository->expects(self::never())->method('findByChannelAndKey');
        $this->notifier->expects(self::never())->method('sendNotice');
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->translator->expects(self::never())->method('trans');

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function doesNothingWhenChannelNotRegistered(): void
    {
        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: \App\Domain\IRC\Network\ChannelMemberRole::None,
        );

        $this->channelRepository->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn(null);
        $this->nickRepository->expects(self::never())->method('findByNick');
        $this->memoRepository->expects(self::never())->method('countUnreadByTargetChannel');
        $this->memoSettingsRepository->expects(self::never())->method('isEnabledForChannel');
        $this->accessRepository->expects(self::never())->method('findByChannelAndNick');
        $this->levelRepository->expects(self::never())->method('findByChannelAndKey');
        $this->notifier->expects(self::never())->method('sendNotice');
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->translator->expects(self::never())->method('trans');

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function doesNothingWhenMemoNotEnabledForChannel(): void
    {
        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: \App\Domain\IRC\Network\ChannelMemberRole::None,
        );
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);

        $this->channelRepository->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->memoSettingsRepository->expects(self::atLeastOnce())->method('isEnabledForChannel')->with(self::CHANNEL_ID)->willReturn(false);
        $this->nickRepository->expects(self::never())->method('findByNick');
        $this->memoRepository->expects(self::never())->method('countUnreadByTargetChannel');
        $this->accessRepository->expects(self::never())->method('findByChannelAndNick');
        $this->levelRepository->expects(self::never())->method('findByChannelAndKey');
        $this->notifier->expects(self::never())->method('sendNotice');
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->translator->expects(self::never())->method('trans');

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function doesNothingWhenNoUnreadMemos(): void
    {
        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: \App\Domain\IRC\Network\ChannelMemberRole::None,
        );
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);

        $this->channelRepository->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->memoSettingsRepository->expects(self::atLeastOnce())->method('isEnabledForChannel')->with(self::CHANNEL_ID)->willReturn(true);
        $this->memoRepository->expects(self::atLeastOnce())->method('countUnreadByTargetChannel')->with(self::CHANNEL_ID)->willReturn(0);
        $this->nickRepository->expects(self::never())->method('findByNick');
        $this->accessRepository->expects(self::never())->method('findByChannelAndNick');
        $this->levelRepository->expects(self::never())->method('findByChannelAndKey');
        $this->notifier->expects(self::never())->method('sendNotice');
        $this->userLookup->expects(self::never())->method('findByUid');
        $this->translator->expects(self::never())->method('trans');

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function doesNothingWhenUserLookupReturnsNull(): void
    {
        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: \App\Domain\IRC\Network\ChannelMemberRole::None,
        );
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);

        $this->channelRepository->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->memoSettingsRepository->expects(self::atLeastOnce())->method('isEnabledForChannel')->with(self::CHANNEL_ID)->willReturn(true);
        $this->memoRepository->expects(self::atLeastOnce())->method('countUnreadByTargetChannel')->with(self::CHANNEL_ID)->willReturn(3);
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001USER')->willReturn(null);
        $this->nickRepository->expects(self::never())->method('findByNick');
        $this->accessRepository->expects(self::never())->method('findByChannelAndNick');
        $this->levelRepository->expects(self::never())->method('findByChannelAndKey');
        $this->notifier->expects(self::never())->method('sendNotice');
        $this->translator->expects(self::never())->method('trans');

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function doesNothingWhenNickNotRegistered(): void
    {
        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: \App\Domain\IRC\Network\ChannelMemberRole::None,
        );
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);
        $sender = new SenderView(
            uid: '001USER',
            nick: 'TestUser',
            ident: '~u',
            hostname: 'user.example.com',
            cloakedHost: 'user.example.com',
            ipBase64: '',
        );

        $this->channelRepository->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->memoSettingsRepository->expects(self::atLeastOnce())->method('isEnabledForChannel')->with(self::CHANNEL_ID)->willReturn(true);
        $this->memoRepository->expects(self::atLeastOnce())->method('countUnreadByTargetChannel')->with(self::CHANNEL_ID)->willReturn(3);
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn(null);
        $this->accessRepository->expects(self::never())->method('findByChannelAndNick');
        $this->levelRepository->expects(self::never())->method('findByChannelAndKey');
        $this->notifier->expects(self::never())->method('sendNotice');
        $this->translator->expects(self::never())->method('trans');

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function doesNothingWhenUserLevelBelowMemoread(): void
    {
        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: \App\Domain\IRC\Network\ChannelMemberRole::None,
        );
        $channel = $this->createStub(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);
        $sender = new SenderView(
            uid: '001USER',
            nick: 'TestUser',
            ident: '~u',
            hostname: 'user.example.com',
            cloakedHost: 'user.example.com',
            ipBase64: '',
        );
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(self::NICK_ID);
        $account->method('getLanguage')->willReturn('en');

        $this->channelRepository->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->memoSettingsRepository->expects(self::atLeastOnce())->method('isEnabledForChannel')->with(self::CHANNEL_ID)->willReturn(true);
        $this->memoRepository->expects(self::atLeastOnce())->method('countUnreadByTargetChannel')->with(self::CHANNEL_ID)->willReturn(3);
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository->expects(self::atLeastOnce())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn(null);
        $this->levelRepository->expects(self::atLeastOnce())->method('findByChannelAndKey')->with(self::CHANNEL_ID, ChannelLevel::KEY_MEMOREAD)->willReturn(new ChannelLevel(self::CHANNEL_ID, ChannelLevel::KEY_MEMOREAD, 200));
        $this->notifier->expects(self::never())->method('sendNotice');
        $this->translator->expects(self::never())->method('trans');

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function sendsNoticeWhenUserHasMemoreadAndUnreadMemos(): void
    {
        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: \App\Domain\IRC\Network\ChannelMemberRole::None,
        );
        $channel = $this->createMock(\App\Domain\ChanServ\Entity\RegisteredChannel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->expects(self::atLeastOnce())->method('isFounder')->with(self::NICK_ID)->willReturn(false);

        $sender = new SenderView(
            uid: '001USER',
            nick: 'TestUser',
            ident: '~u',
            hostname: 'user.example.com',
            cloakedHost: 'user.example.com',
            ipBase64: '',
        );
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(self::NICK_ID);
        $account->method('getLanguage')->willReturn('en');

        $access = new \App\Domain\ChanServ\Entity\ChannelAccess(self::CHANNEL_ID, self::NICK_ID, 250);

        $this->channelRepository->expects(self::atLeastOnce())->method('findByChannelName')->with('#test')->willReturn($channel);
        $this->memoSettingsRepository->expects(self::atLeastOnce())->method('isEnabledForChannel')->with(self::CHANNEL_ID)->willReturn(true);
        $this->memoRepository->expects(self::atLeastOnce())->method('countUnreadByTargetChannel')->with(self::CHANNEL_ID)->willReturn(2);
        $this->userLookup->expects(self::atLeastOnce())->method('findByUid')->with('001USER')->willReturn($sender);
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($account);
        $this->accessRepository->expects(self::atLeastOnce())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn($access);
        $this->levelRepository->expects(self::atLeastOnce())->method('findByChannelAndKey')->with(self::CHANNEL_ID, ChannelLevel::KEY_MEMOREAD)->willReturn(new ChannelLevel(self::CHANNEL_ID, ChannelLevel::KEY_MEMOREAD, 200));
        $this->translator->expects(self::atLeastOnce())->method('trans')->with(
            'notify.channel_pending',
            ['%channel%' => '#test', '%count%' => 2, '%bot%' => 'MemoServ'],
            'memoserv',
            'en',
        )->willReturn('You have 2 pending memo(s) for #test.');
        $this->notifier->method('getNick')->willReturn('MemoServ');
        $this->notifier->expects(self::once())->method('sendNotice')->with('001USER', 'You have 2 pending memo(s) for #test.');

        $this->subscriber->onUserJoinedChannel($event);
    }
}
