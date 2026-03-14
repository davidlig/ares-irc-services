<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Network;

use App\Domain\IRC\Event\ChannelModesChangedEvent;
use App\Domain\IRC\Event\ChannelSyncedEvent;
use App\Domain\IRC\Event\ChannelTopicChangedEvent;
use App\Domain\IRC\Event\UserHostChangedEvent;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\IRC\Event\UserJoinedNetworkEvent;
use App\Domain\IRC\Event\UserLeftChannelEvent;
use App\Domain\IRC\Event\UserModeChangedEvent;
use App\Domain\IRC\Event\UserNickChangedEvent;
use App\Domain\IRC\Event\UserQuitNetworkEvent;
use App\Domain\IRC\Network\Channel;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\Network\NetworkUser;
use App\Domain\IRC\Repository\ChannelRepositoryInterface;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Ident;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;
use App\Infrastructure\IRC\Network\NetworkStateSubscriber;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(NetworkStateSubscriber::class)]
final class NetworkStateSubscriberTest extends TestCase
{
    private NetworkUserRepositoryInterface&MockObject $userRepository;

    private ChannelRepositoryInterface&MockObject $channelRepository;

    private LoggerInterface&MockObject $logger;

    private NetworkStateSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->userRepository = $this->createMock(NetworkUserRepositoryInterface::class);
        $this->channelRepository = $this->createMock(ChannelRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->subscriber = new NetworkStateSubscriber(
            $this->userRepository,
            $this->channelRepository,
            $this->logger,
        );
    }

    #[Test]
    public function getSubscribedEventsReturnsAllEvents(): void
    {
        $events = NetworkStateSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(UserJoinedNetworkEvent::class, $events);
        self::assertArrayHasKey(UserQuitNetworkEvent::class, $events);
        self::assertArrayHasKey(UserNickChangedEvent::class, $events);
        self::assertArrayHasKey(UserModeChangedEvent::class, $events);
        self::assertArrayHasKey(UserHostChangedEvent::class, $events);
        self::assertArrayHasKey(ChannelSyncedEvent::class, $events);
        self::assertArrayHasKey(UserJoinedChannelEvent::class, $events);
        self::assertArrayHasKey(UserLeftChannelEvent::class, $events);
        self::assertArrayHasKey(ChannelModesChangedEvent::class, $events);
        self::assertArrayHasKey(ChannelTopicChangedEvent::class, $events);
    }

    #[Test]
    public function onUserJoinedNetworkAddsUserToRepository(): void
    {
        $user = $this->createUser('001ABC123', 'TestUser');
        $event = new UserJoinedNetworkEvent($user);

        $this->userRepository->expects(self::once())
            ->method('add')
            ->with($user);

        $this->subscriber->onUserJoinedNetwork($event);
    }

    #[Test]
    public function onUserQuitNetworkRemovesUserAndCleansChannels(): void
    {
        $uid = new Uid('001ABC123');
        $nick = new Nick('TestUser');
        $user = $this->createMock(NetworkUser::class);
        $user->method('getChannelNames')->willReturn(['#test', '#other']);
        $channel = new Channel(new ChannelName('#test'));

        $this->userRepository->expects(self::once())
            ->method('findByUid')
            ->with($uid)
            ->willReturn($user);

        $this->channelRepository->expects(self::exactly(2))
            ->method('findByName')
            ->willReturnCallback(static function (ChannelName $name) use ($channel): ?Channel {
                if ('#test' === $name->value || '#other' === $name->value) {
                    return $channel;
                }

                return null;
            });

        $this->channelRepository->expects(self::exactly(2))
            ->method('save')
            ->with($channel);

        $this->userRepository->expects(self::once())
            ->method('removeByUid')
            ->with($uid);

        $this->logger->expects(self::once())
            ->method('info');

        $event = new UserQuitNetworkEvent($uid, $nick, 'Quit message', 'testuser', 'testhost');
        $this->subscriber->onUserQuitNetwork($event);
    }

    #[Test]
    public function onUserQuitNetworkDoesNothingWhenUserNotFound(): void
    {
        $uid = new Uid('001ABC123');
        $nick = new Nick('TestUser');

        $this->userRepository->expects(self::once())
            ->method('findByUid')
            ->with($uid)
            ->willReturn(null);

        $this->userRepository->expects(self::never())
            ->method('removeByUid');

        $event = new UserQuitNetworkEvent($uid, $nick, 'Quit message');
        $this->subscriber->onUserQuitNetwork($event);
    }

    #[Test]
    public function onUserNickChangedUpdatesRepository(): void
    {
        $uid = new Uid('001ABC123');
        $oldNick = new Nick('OldNick');
        $newNick = new Nick('NewNick');
        $event = new UserNickChangedEvent($uid, $oldNick, $newNick);

        $this->userRepository->expects(self::once())
            ->method('updateNick')
            ->with($uid, $oldNick, $newNick);

        $this->subscriber->onUserNickChanged($event);
    }

    #[Test]
    public function onUserModeChangedAppliesModeChangeToUser(): void
    {
        $uid = new Uid('001ABC123');
        $user = $this->createMock(NetworkUser::class);
        $event = new UserModeChangedEvent($uid, '+r');

        $this->userRepository->expects(self::once())
            ->method('findByUid')
            ->with($uid)
            ->willReturn($user);

        $user->expects(self::once())
            ->method('applyModeChange')
            ->with('+r');

        $this->subscriber->onUserModeChanged($event);
    }

    #[Test]
    public function onUserModeChangedDoesNothingWhenUserNotFound(): void
    {
        $uid = new Uid('001ABC123');
        $event = new UserModeChangedEvent($uid, '+r');

        $this->userRepository->expects(self::once())
            ->method('findByUid')
            ->with($uid)
            ->willReturn(null);

        $this->subscriber->onUserModeChanged($event);
    }

    #[Test]
    public function onUserHostChangedUpdatesVirtualHost(): void
    {
        $uid = new Uid('001ABC123');
        $user = $this->createMock(NetworkUser::class);
        $event = new UserHostChangedEvent($uid, 'new.host.example.com');

        $this->userRepository->expects(self::once())
            ->method('findByUid')
            ->with($uid)
            ->willReturn($user);

        $user->expects(self::once())
            ->method('updateVirtualHost')
            ->with('new.host.example.com');

        $this->subscriber->onUserHostChanged($event);
    }

    #[Test]
    public function onUserHostChangedDoesNothingWhenUserNotFound(): void
    {
        $uid = new Uid('001ABC123');
        $event = new UserHostChangedEvent($uid, 'new.host.example.com');

        $this->userRepository->expects(self::once())
            ->method('findByUid')
            ->with($uid)
            ->willReturn(null);

        $this->subscriber->onUserHostChanged($event);
    }

    #[Test]
    public function onChannelSyncedSavesChannelAndUpdatesUsers(): void
    {
        $channelName = new ChannelName('#test');
        $channel = new Channel($channelName);
        $uid = new Uid('001ABC123');

        $channel->syncMember($uid, ChannelMemberRole::Op);

        $user = $this->createMock(NetworkUser::class);

        $this->channelRepository->expects(self::once())
            ->method('save')
            ->with($channel);

        $this->userRepository->expects(self::once())
            ->method('findByUid')
            ->with($uid)
            ->willReturn($user);

        $user->expects(self::once())
            ->method('addChannel')
            ->with($channelName);

        $event = new ChannelSyncedEvent($channel, true);
        $this->subscriber->onChannelSynced($event);
    }

    #[Test]
    public function onUserJoinedChannelAddsChannelToUser(): void
    {
        $uid = new Uid('001ABC123');
        $channelName = new ChannelName('#test');
        $user = $this->createMock(NetworkUser::class);
        $event = new UserJoinedChannelEvent($uid, $channelName, ChannelMemberRole::Op);

        $this->userRepository->expects(self::once())
            ->method('findByUid')
            ->with($uid)
            ->willReturn($user);

        $user->expects(self::once())
            ->method('addChannel')
            ->with($channelName);

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function onUserJoinedChannelDoesNothingWhenUserNotFound(): void
    {
        $uid = new Uid('001ABC123');
        $channelName = new ChannelName('#test');
        $event = new UserJoinedChannelEvent($uid, $channelName, ChannelMemberRole::Op);

        $this->userRepository->expects(self::once())
            ->method('findByUid')
            ->with($uid)
            ->willReturn(null);

        $this->subscriber->onUserJoinedChannel($event);
    }

    #[Test]
    public function onUserLeftChannelRemovesFromUserAndChannel(): void
    {
        $uid = new Uid('001ABC123');
        $nick = new Nick('TestUser');
        $channelName = new ChannelName('#test');
        $user = $this->createMock(NetworkUser::class);
        $channel = $this->createMock(Channel::class);
        $event = new UserLeftChannelEvent($uid, $nick, $channelName, 'Leaving', false);

        $this->userRepository->expects(self::once())
            ->method('findByUid')
            ->with($uid)
            ->willReturn($user);

        $user->expects(self::once())
            ->method('removeChannel')
            ->with($channelName);

        $this->channelRepository->expects(self::once())
            ->method('findByName')
            ->with($channelName)
            ->willReturn($channel);

        $channel->expects(self::once())
            ->method('removeMember')
            ->with($uid);

        $this->channelRepository->expects(self::once())
            ->method('save')
            ->with($channel);

        $this->subscriber->onUserLeftChannel($event);
    }

    #[Test]
    public function onChannelModesChangedSavesChannel(): void
    {
        $channel = $this->createMock(Channel::class);
        $event = new ChannelModesChangedEvent($channel);

        $this->channelRepository->expects(self::once())
            ->method('save')
            ->with($channel);

        $this->subscriber->onChannelModesChanged($event);
    }

    #[Test]
    public function onChannelTopicChangedSavesChannel(): void
    {
        $channel = $this->createMock(Channel::class);
        $event = new ChannelTopicChangedEvent($channel);

        $this->channelRepository->expects(self::once())
            ->method('save')
            ->with($channel);

        $this->subscriber->onChannelTopicChanged($event);
    }

    private function createUser(string $uid, string $nick): NetworkUser
    {
        return new NetworkUser(
            new Uid($uid),
            new Nick($nick),
            new Ident('user'),
            'hostname.example.com',
            'cloaked.example.com',
            'vhost.example.com',
            '+i',
            new DateTimeImmutable(),
            'Real Name',
            '001',
            'base64ip',
        );
    }
}
