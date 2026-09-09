<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Event;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceUidProviderInterface;
use App\Irc\Application\Port\In\ServiceUidRegistry;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent;
use App\MemoServ\Adapter\In\Event\MemoServPendingChannelNoticeSubscriber;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Application\Model\MemoAccountView;
use App\MemoServ\Application\Model\MemoChannelView;
use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Application\UseCase\GetPendingChannelNotice\GetPendingChannelNoticeHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(MemoServPendingChannelNoticeSubscriber::class)]
final class MemoServPendingChannelNoticeSubscriberTest extends TestCase
{
    public const string MEMOSERV_UID = '001MEMO';

    #[Test]
    public function subscribesToThePublishedJoinEventAfterEntryMessage(): void
    {
        self::assertSame(
            [UserJoinedChannelEvent::class => ['onUserJoinedChannel', -10]],
            MemoServPendingChannelNoticeSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function ignoresMemoServWithoutLookingUpAUser(): void
    {
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::never())->method('findByUid');

        $subscriber = $this->subscriber(
            $this->createStub(MemoChannelPort::class),
            $this->createStub(MemoUserAccountPort::class),
            $this->createStub(MemoRepositoryInterface::class),
            $this->createStub(MemoSettingsRepositoryInterface::class),
            $this->createStub(MemoServNotifierInterface::class),
            $userLookup,
            $this->createStub(TranslatorInterface::class),
        );

        $subscriber->onUserJoinedChannel(new UserJoinedChannelEvent(self::MEMOSERV_UID, '#test'));
    }

    #[Test]
    public function ignoresAJoinWhenTheNetworkUserIsUnavailable(): void
    {
        $channelPort = $this->createMock(MemoChannelPort::class);
        $channelPort->expects(self::never())->method('findChannelByName');
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->with('001USER')->willReturn(null);

        $subscriber = $this->subscriber(
            $channelPort,
            $this->createStub(MemoUserAccountPort::class),
            $this->createStub(MemoRepositoryInterface::class),
            $this->createStub(MemoSettingsRepositoryInterface::class),
            $this->createStub(MemoServNotifierInterface::class),
            $userLookup,
            $this->createStub(TranslatorInterface::class),
        );

        $subscriber->onUserJoinedChannel(new UserJoinedChannelEvent('001USER', '#test'));
    }

    #[Test]
    public function doesNotPresentWhenTheUseCaseReturnsNoNotice(): void
    {
        $channelPort = $this->createMock(MemoChannelPort::class);
        $channelPort->expects(self::once())->method('findChannelByName')->with('#test')->willReturn(null);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($this->sender());
        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendNotice');
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::never())->method('trans');

        $subscriber = $this->subscriber(
            $channelPort,
            $this->createStub(MemoUserAccountPort::class),
            $this->createStub(MemoRepositoryInterface::class),
            $this->createStub(MemoSettingsRepositoryInterface::class),
            $notifier,
            $userLookup,
            $translator,
        );

        $subscriber->onUserJoinedChannel(new UserJoinedChannelEvent('001USER', '#TeSt'));
    }

    #[Test]
    public function mapsTheSenderAndPresentsTheSemanticResultUsingTheDefaultLanguage(): void
    {
        $channelPort = $this->createMock(MemoChannelPort::class);
        $channelPort->expects(self::once())->method('findChannelByName')->with('#test')->willReturn(new MemoChannelView(5, '#Test'));
        $channelPort->expects(self::once())->method('canReadChannelMemos')->with(5, 10)->willReturn(true);
        $userAccountPort = $this->createMock(MemoUserAccountPort::class);
        $userAccountPort->expects(self::once())->method('findAccountByNick')->with('TestUser')->willReturn(new MemoAccountView(10, 'TestUser', ''));
        $memoRepository = $this->createMock(MemoRepositoryInterface::class);
        $memoRepository->expects(self::once())->method('countUnreadByTargetChannel')->with(5)->willReturn(2);
        $settingsRepository = $this->createMock(MemoSettingsRepositoryInterface::class);
        $settingsRepository->expects(self::once())->method('isEnabledForChannel')->with(5)->willReturn(true);
        $userLookup = $this->createMock(NetworkUserLookupPort::class);
        $userLookup->expects(self::once())->method('findByUid')->with('001USER')->willReturn($this->sender());
        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::once())->method('getNick')->willReturn('MemoServ');
        $notifier->expects(self::once())->method('sendNotice')->with('001USER', 'Two pending memos');
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')->with(
            'notify.channel_pending',
            ['%channel%' => '#TeSt', '%count%' => 2, '%bot%' => 'MemoServ'],
            'memoserv',
            'es',
        )->willReturn('Two pending memos');

        $subscriber = $this->subscriber(
            $channelPort,
            $userAccountPort,
            $memoRepository,
            $settingsRepository,
            $notifier,
            $userLookup,
            $translator,
            'es',
        );

        $subscriber->onUserJoinedChannel(new UserJoinedChannelEvent('001USER', '#TeSt'));
    }

    private function subscriber(
        MemoChannelPort $channelPort,
        MemoUserAccountPort $userAccountPort,
        MemoRepositoryInterface $memoRepository,
        MemoSettingsRepositoryInterface $settingsRepository,
        MemoServNotifierInterface $notifier,
        NetworkUserLookupPort $userLookup,
        TranslatorInterface $translator,
        string $defaultLanguage = 'en',
    ): MemoServPendingChannelNoticeSubscriber {
        return new MemoServPendingChannelNoticeSubscriber(
            new GetPendingChannelNoticeHandler(
                $channelPort,
                $userAccountPort,
                $memoRepository,
                $settingsRepository,
            ),
            $notifier,
            $userLookup,
            $translator,
            $this->uidRegistry(),
            $defaultLanguage,
        );
    }

    private function sender(): SenderView
    {
        return new SenderView(
            uid: '001USER',
            nick: 'TestUser',
            ident: '~u',
            hostname: 'user.example.com',
            cloakedHost: 'user.example.com',
            ipBase64: '',
            isIdentified: true,
        );
    }

    private function uidRegistry(): ServiceUidRegistry
    {
        $provider = new class implements ServiceUidProviderInterface {
            public function getServiceKey(): string
            {
                return 'memoserv';
            }

            public function getNickname(): string
            {
                return 'MemoServ';
            }

            public function getUid(): string
            {
                return MemoServPendingChannelNoticeSubscriberTest::MEMOSERV_UID;
            }
        };

        return new ServiceUidRegistry(['memoserv' => $provider]);
    }
}
