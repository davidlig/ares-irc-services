<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Event;

use App\Application\ChanServ\ChanServAccessHelper;
use App\Application\Port\ServiceUidProviderInterface;
use App\Application\Shared\ServiceUidRegistry;
use App\Domain\ChanServ\Entity\ChannelAccess;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Domain\Event\UserJoinedChannelEvent;
use App\Irc\Domain\Network\ChannelMemberRole;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Uid;
use App\MemoServ\Adapter\In\Event\MemoServPendingChannelNoticeSubscriber;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Application\Model\MemoAccountView;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(MemoServPendingChannelNoticeSubscriber::class)]
final class MemoServPendingChannelNoticeSubscriberTest extends TestCase
{
    private const int CHANNEL_ID = 1;

    private const int NICK_ID = 10;

    private const string MEMOSERV_UID = '001MEMO';

    private function createUidRegistry(): ServiceUidRegistry
    {
        $provider = new class('memoserv', 'MemoServ', self::MEMOSERV_UID) implements ServiceUidProviderInterface {
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

        return new ServiceUidRegistry(['memoserv' => $provider]);
    }

    #[Test]
    public function subscribesToUserJoinedChannelEvent(): void
    {
        $subscriber = new MemoServPendingChannelNoticeSubscriber(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(MemoUserAccountPort::class),
            $this->createStub(MemoRepositoryInterface::class),
            $this->createStub(MemoSettingsRepositoryInterface::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
            $this->createStub(MemoServNotifierInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslatorInterface::class),
            $this->createUidRegistry(),
        );

        self::assertSame(
            [UserJoinedChannelEvent::class => ['onUserJoinedChannel', -10]],
            $subscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function doesNothingWhenUidIsMemoServ(): void
    {
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::never())->method('findByChannelName');

        $subscriber = new MemoServPendingChannelNoticeSubscriber(
            $channelRepo,
            $this->createStub(MemoUserAccountPort::class),
            $this->createStub(MemoRepositoryInterface::class),
            $this->createStub(MemoSettingsRepositoryInterface::class),
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
            $this->createStub(MemoServNotifierInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslatorInterface::class),
            $this->createUidRegistry(),
        );

        $event = new UserJoinedChannelEvent(
            uid: new Uid(self::MEMOSERV_UID),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::None,
        );

        $subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function doesNothingWhenChannelNotRegistered(): void
    {
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#test')->willReturn(null);

        $memoSettingsRepo = $this->createMock(MemoSettingsRepositoryInterface::class);
        $memoSettingsRepo->expects(self::never())->method('isEnabledForChannel');

        $subscriber = new MemoServPendingChannelNoticeSubscriber(
            $channelRepo,
            $this->createStub(MemoUserAccountPort::class),
            $this->createStub(MemoRepositoryInterface::class),
            $memoSettingsRepo,
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
            $this->createStub(MemoServNotifierInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslatorInterface::class),
            $this->createUidRegistry(),
        );

        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::None,
        );

        $subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function doesNothingWhenMemoNotEnabledForChannel(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);

        $memoSettingsRepo = $this->createMock(MemoSettingsRepositoryInterface::class);
        $memoSettingsRepo->expects(self::once())->method('isEnabledForChannel')->with(self::CHANNEL_ID)->willReturn(false);

        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->expects(self::never())->method('countUnreadByTargetChannel');

        $subscriber = new MemoServPendingChannelNoticeSubscriber(
            $channelRepo,
            $this->createStub(MemoUserAccountPort::class),
            $memoRepo,
            $memoSettingsRepo,
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
            $this->createStub(MemoServNotifierInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(TranslatorInterface::class),
            $this->createUidRegistry(),
        );

        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::None,
        );

        $subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function doesNothingWhenNoUnreadMemos(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);

        $memoSettingsRepo = $this->createMock(MemoSettingsRepositoryInterface::class);
        $memoSettingsRepo->expects(self::once())->method('isEnabledForChannel')->with(self::CHANNEL_ID)->willReturn(true);

        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->expects(self::once())->method('countUnreadByTargetChannel')->with(self::CHANNEL_ID)->willReturn(0);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::never())->method('findByUid');

        $subscriber = new MemoServPendingChannelNoticeSubscriber(
            $channelRepo,
            $this->createStub(MemoUserAccountPort::class),
            $memoRepo,
            $memoSettingsRepo,
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
            $this->createStub(MemoServNotifierInterface::class),
            $userLookup,
            $this->createStub(TranslatorInterface::class),
            $this->createUidRegistry(),
        );

        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::None,
        );

        $subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function doesNothingWhenUserLookupReturnsNull(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);

        $memoSettingsRepo = $this->createMock(MemoSettingsRepositoryInterface::class);
        $memoSettingsRepo->expects(self::once())->method('isEnabledForChannel')->with(self::CHANNEL_ID)->willReturn(true);

        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->expects(self::once())->method('countUnreadByTargetChannel')->with(self::CHANNEL_ID)->willReturn(3);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->with('001USER')->willReturn(null);

        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendNotice');

        $subscriber = new MemoServPendingChannelNoticeSubscriber(
            $channelRepo,
            $this->createStub(MemoUserAccountPort::class),
            $memoRepo,
            $memoSettingsRepo,
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
            $notifier,
            $userLookup,
            $this->createStub(TranslatorInterface::class),
            $this->createUidRegistry(),
        );

        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::None,
        );

        $subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function doesNothingWhenNickNotRegistered(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);
        $sender = new SenderView(
            uid: '001USER',
            nick: 'TestUser',
            ident: '~u',
            hostname: 'user.example.com',
            cloakedHost: 'user.example.com',
            ipBase64: '',
        );

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);

        $memoSettingsRepo = $this->createMock(MemoSettingsRepositoryInterface::class);
        $memoSettingsRepo->expects(self::once())->method('isEnabledForChannel')->with(self::CHANNEL_ID)->willReturn(true);

        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->expects(self::once())->method('countUnreadByTargetChannel')->with(self::CHANNEL_ID)->willReturn(3);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->with('001USER')->willReturn($sender);

        $userAccountPort = $this->createMock(MemoUserAccountPort::class);
        $userAccountPort->expects(self::once())->method('findAccountByNick')->with('TestUser')->willReturn(null);

        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendNotice');

        $subscriber = new MemoServPendingChannelNoticeSubscriber(
            $channelRepo,
            $userAccountPort,
            $memoRepo,
            $memoSettingsRepo,
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
            $notifier,
            $userLookup,
            $this->createStub(TranslatorInterface::class),
            $this->createUidRegistry(),
        );

        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::None,
        );

        $subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function doesNothingWhenUserLevelBelowMemoread(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);
        $sender = new SenderView(
            uid: '001USER',
            nick: 'TestUser',
            ident: '~u',
            hostname: 'user.example.com',
            cloakedHost: 'user.example.com',
            ipBase64: '',
            isIdentified: true,
        );
        $account = new MemoAccountView(self::NICK_ID, 'TestUser', 'en');

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);

        $memoSettingsRepo = $this->createMock(MemoSettingsRepositoryInterface::class);
        $memoSettingsRepo->expects(self::once())->method('isEnabledForChannel')->with(self::CHANNEL_ID)->willReturn(true);

        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->expects(self::once())->method('countUnreadByTargetChannel')->with(self::CHANNEL_ID)->willReturn(3);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->with('001USER')->willReturn($sender);

        $userAccountPort = $this->createMock(MemoUserAccountPort::class);
        $userAccountPort->expects(self::once())->method('findAccountByNick')->with('TestUser')->willReturn($account);

        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->expects(self::once())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn(null);

        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::once())->method('findByChannelAndKey')->with(self::CHANNEL_ID, ChannelLevel::KEY_MEMOREAD)->willReturn(new ChannelLevel(self::CHANNEL_ID, ChannelLevel::KEY_MEMOREAD, 200));

        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendNotice');

        $subscriber = new MemoServPendingChannelNoticeSubscriber(
            $channelRepo,
            $userAccountPort,
            $memoRepo,
            $memoSettingsRepo,
            new ChanServAccessHelper($accessRepo, $levelRepo),
            $notifier,
            $userLookup,
            $this->createStub(TranslatorInterface::class),
            $this->createUidRegistry(),
        );

        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::None,
        );

        $subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function sendsNoticeWhenUserHasMemoreadAndUnreadMemos(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->method('getId')->willReturn(self::CHANNEL_ID);
        $channel->expects(self::atLeastOnce())->method('isFounder')->with(self::NICK_ID)->willReturn(false);

        $sender = new SenderView(
            uid: '001USER',
            nick: 'TestUser',
            ident: '~u',
            hostname: 'user.example.com',
            cloakedHost: 'user.example.com',
            ipBase64: '',
            isIdentified: true,
        );
        $account = new MemoAccountView(self::NICK_ID, 'TestUser', '');

        $access = new ChannelAccess(self::CHANNEL_ID, self::NICK_ID, 250);

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('findByChannelName')->with('#test')->willReturn($channel);

        $memoSettingsRepo = $this->createMock(MemoSettingsRepositoryInterface::class);
        $memoSettingsRepo->expects(self::once())->method('isEnabledForChannel')->with(self::CHANNEL_ID)->willReturn(true);

        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->expects(self::once())->method('countUnreadByTargetChannel')->with(self::CHANNEL_ID)->willReturn(2);

        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->with('001USER')->willReturn($sender);

        $userAccountPort = $this->createMock(MemoUserAccountPort::class);
        $userAccountPort->expects(self::once())->method('findAccountByNick')->with('TestUser')->willReturn($account);

        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->expects(self::once())->method('findByChannelAndNick')->with(self::CHANNEL_ID, self::NICK_ID)->willReturn($access);

        $levelRepo = $this->createMock(ChannelLevelRepositoryInterface::class);
        $levelRepo->expects(self::once())->method('findByChannelAndKey')->with(self::CHANNEL_ID, ChannelLevel::KEY_MEMOREAD)->willReturn(new ChannelLevel(self::CHANNEL_ID, ChannelLevel::KEY_MEMOREAD, 200));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')->with(
            'notify.channel_pending',
            ['%channel%' => '#test', '%count%' => 2, '%bot%' => 'MemoServ'],
            'memoserv',
            'es',
        )->willReturn('You have 2 pending memo(s) for #test.');

        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('MemoServ');
        $notifier->expects(self::once())->method('sendNotice')->with('001USER', 'You have 2 pending memo(s) for #test.');

        $subscriber = new MemoServPendingChannelNoticeSubscriber(
            $channelRepo,
            $userAccountPort,
            $memoRepo,
            $memoSettingsRepo,
            new ChanServAccessHelper($accessRepo, $levelRepo),
            $notifier,
            $userLookup,
            $translator,
            $this->createUidRegistry(),
            'es',
        );

        $event = new UserJoinedChannelEvent(
            uid: new Uid('001USER'),
            channel: new ChannelName('#test'),
            role: ChannelMemberRole::None,
        );

        $subscriber->onUserJoinedChannel($event);
    }
}
