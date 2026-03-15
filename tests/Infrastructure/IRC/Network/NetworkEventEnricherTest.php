<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Network;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelModeSupportInterface;
use App\Domain\IRC\Event\ChannelModesChangedEvent;
use App\Domain\IRC\Event\ChannelSyncedEvent;
use App\Domain\IRC\Event\ChannelTopicChangedEvent;
use App\Domain\IRC\Event\FjoinReceivedEvent;
use App\Domain\IRC\Event\FmodeReceivedEvent;
use App\Domain\IRC\Event\FtopicReceivedEvent;
use App\Domain\IRC\Event\KickReceivedEvent;
use App\Domain\IRC\Event\LmodeReceivedEvent;
use App\Domain\IRC\Event\ModeReceivedEvent;
use App\Domain\IRC\Event\NickChangeReceivedEvent;
use App\Domain\IRC\Event\PartReceivedEvent;
use App\Domain\IRC\Event\QuitReceivedEvent;
use App\Domain\IRC\Event\SethostReceivedEvent;
use App\Domain\IRC\Event\Umode2ReceivedEvent;
use App\Domain\IRC\Event\UserHostChangedEvent;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use App\Domain\IRC\Event\UserLeftChannelEvent;
use App\Domain\IRC\Event\UserModeChangedEvent;
use App\Domain\IRC\Event\UserNickChangedEvent;
use App\Domain\IRC\Event\UserQuitNetworkEvent;
use App\Domain\IRC\Network\Channel;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\Network\NetworkUser;
use App\Domain\IRC\Repository\ChannelRepositoryInterface;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\IRC\SkipIdentifiedModeStripRegistryInterface;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Ident;
use App\Domain\IRC\ValueObject\Nick;
use App\Domain\IRC\ValueObject\Uid;
use App\Infrastructure\IRC\Network\NetworkEventEnricher;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[CoversClass(NetworkEventEnricher::class)]
final class NetworkEventEnricherTest extends TestCase
{
    #[Test]
    public function getSubscribedEventsReturnsExpectedHandlers(): void
    {
        $enricher = new NetworkEventEnricher(
            $this->createStub(ChannelRepositoryInterface::class),
            $this->createStub(NetworkUserRepositoryInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $events = $enricher->getSubscribedEvents();

        self::assertArrayHasKey(QuitReceivedEvent::class, $events);
        self::assertIsArray($events[QuitReceivedEvent::class]);
        self::assertSame('onQuitReceived', $events[QuitReceivedEvent::class][0]);
    }

    #[Test]
    public function onQuitReceivedDoesNothingWhenUserNotFound(): void
    {
        $userRepo = $this->createMock(NetworkUserRepositoryInterface::class);
        $userRepo->method('findByUid')->willReturn(null);
        $userRepo->expects(self::never())->method('removeByUid');
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $enricher = new NetworkEventEnricher(
            $this->createStub(ChannelRepositoryInterface::class),
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onQuitReceived(new QuitReceivedEvent('001ABC123', 'Leaving'));
    }

    #[Test]
    public function onQuitReceivedDispatchesLeftChannelAndQuitNetworkAndRemovesUser(): void
    {
        $user = new NetworkUser(
            new Uid('001ABC123'),
            new Nick('Nick'),
            new Ident('ident'),
            'host.example',
            'cloak.example',
            'vhost.example',
            '+i',
            new DateTimeImmutable('2024-01-01'),
            'Real',
            '001',
            '*',
        );
        $user->addChannel(new ChannelName('#test'));

        $userRepo = $this->createMock(NetworkUserRepositoryInterface::class);
        $userRepo->expects(self::atLeastOnce())->method('findByUid')->with(self::callback(static fn (Uid $u) => '001ABC123' === $u->value))->willReturn($user);
        $userRepo->expects(self::once())->method('removeByUid')->with(self::callback(static fn ($u) => $u instanceof Uid && '001ABC123' === $u->value));

        $dispatched = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        $enricher = new NetworkEventEnricher(
            $this->createStub(ChannelRepositoryInterface::class),
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onQuitReceived(new QuitReceivedEvent('001ABC123', 'Bye'));

        self::assertCount(2, $dispatched);
        self::assertInstanceOf(UserLeftChannelEvent::class, $dispatched[0]);
        self::assertSame('001ABC123', $dispatched[0]->uid->value);
        self::assertSame('Bye', $dispatched[0]->reason);
        self::assertInstanceOf(UserQuitNetworkEvent::class, $dispatched[1]);
        self::assertSame('001ABC123', $dispatched[1]->uid->value);
        self::assertSame('Bye', $dispatched[1]->reason);
    }

    #[Test]
    public function onNickChangeReceivedDispatchesUserNickChangedWhenUserFound(): void
    {
        $user = new NetworkUser(
            new Uid('001ABC123'),
            new Nick('OldNick'),
            new Ident('ident'),
            'host.example',
            'cloak.example',
            'vhost.example',
            '+i',
            new DateTimeImmutable('2024-01-01'),
            'Real',
            '001',
            '*',
        );

        $userRepo = $this->createStub(NetworkUserRepositoryInterface::class);
        $userRepo->method('findByUid')->willReturn($user);
        $skipRegistry = $this->createStub(SkipIdentifiedModeStripRegistryInterface::class);
        $skipRegistry->method('peek')->willReturn(true);

        $dispatched = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static function (object $event) use (&$dispatched): bool {
                $dispatched[] = $event;

                return true;
            }))->willReturnCallback(static fn (object $event): object => $event);

        $enricher = new NetworkEventEnricher(
            $this->createStub(ChannelRepositoryInterface::class),
            $userRepo,
            $eventDispatcher,
            $skipRegistry,
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onNickChangeReceived(new NickChangeReceivedEvent('001ABC123', 'NewNick'));

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(UserNickChangedEvent::class, $dispatched[0]);
        self::assertSame('001ABC123', $dispatched[0]->uid->value);
        self::assertSame('NewNick', $dispatched[0]->newNick->value);
    }

    #[Test]
    public function onPartReceivedDispatchesUserLeftChannelWhenUserFound(): void
    {
        $user = new NetworkUser(
            new Uid('001ABC123'),
            new Nick('Nick'),
            new Ident('ident'),
            'host.example',
            'cloak.example',
            'vhost.example',
            '+i',
            new DateTimeImmutable('2024-01-01'),
            'Real',
            '001',
            '*',
        );

        $userRepo = $this->createStub(NetworkUserRepositoryInterface::class);
        $userRepo->method('findByUid')->willReturn($user);

        $dispatched = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->with(self::callback(static function (object $event) use (&$dispatched): bool {
                $dispatched[] = $event;

                return true;
            }))->willReturnCallback(static fn (object $event): object => $event);

        $enricher = new NetworkEventEnricher(
            $this->createStub(ChannelRepositoryInterface::class),
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $channelName = new ChannelName('#test');
        $enricher->onPartReceived(new PartReceivedEvent('001ABC123', $channelName, 'Bye', false));

        self::assertCount(1, $dispatched);
        self::assertInstanceOf(UserLeftChannelEvent::class, $dispatched[0]);
        self::assertSame('001ABC123', $dispatched[0]->uid->value);
        self::assertSame('#test', $dispatched[0]->channel->value);
        self::assertSame('Bye', $dispatched[0]->reason);
    }

    #[Test]
    public function onNickChangeReceivedDoesNothingWhenUserNotFound(): void
    {
        $userRepo = $this->createStub(NetworkUserRepositoryInterface::class);
        $userRepo->method('findByUid')->willReturn(null);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $enricher = new NetworkEventEnricher(
            $this->createStub(ChannelRepositoryInterface::class),
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onNickChangeReceived(new NickChangeReceivedEvent('001ABC123', 'NewNick'));
    }

    #[Test]
    public function onNickChangeReceivedDispatchesMinusRWhenSkipRegistryPeekIsFalse(): void
    {
        $user = new NetworkUser(
            new Uid('001ABC123'),
            new Nick('OldNick'),
            new Ident('ident'),
            'host.example',
            'cloak.example',
            'vhost.example',
            '+i',
            new DateTimeImmutable('2024-01-01'),
            'Real',
            '001',
            '*',
        );
        $userRepo = $this->createStub(NetworkUserRepositoryInterface::class);
        $userRepo->method('findByUid')->willReturn($user);
        $skipRegistry = $this->createStub(SkipIdentifiedModeStripRegistryInterface::class);
        $skipRegistry->method('peek')->willReturn(false);

        $dispatched = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::exactly(2))->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        $enricher = new NetworkEventEnricher(
            $this->createStub(ChannelRepositoryInterface::class),
            $userRepo,
            $eventDispatcher,
            $skipRegistry,
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onNickChangeReceived(new NickChangeReceivedEvent('001ABC123', 'NewNick'));

        self::assertInstanceOf(UserModeChangedEvent::class, $dispatched[0]);
        self::assertSame('-r', $dispatched[0]->modeDelta);
        self::assertInstanceOf(UserNickChangedEvent::class, $dispatched[1]);
    }

    #[Test]
    public function onPartReceivedDoesNothingWhenUserNotFound(): void
    {
        $userRepo = $this->createStub(NetworkUserRepositoryInterface::class);
        $userRepo->method('findByUid')->willReturn(null);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $enricher = new NetworkEventEnricher(
            $this->createStub(ChannelRepositoryInterface::class),
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onPartReceived(new PartReceivedEvent('001ABC123', new ChannelName('#test'), 'Bye', false));
    }

    #[Test]
    public function onKickReceivedDoesNothingWhenTargetNotFound(): void
    {
        $userRepo = $this->createStub(NetworkUserRepositoryInterface::class);
        $userRepo->method('findByUid')->willReturn(null);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $enricher = new NetworkEventEnricher(
            $this->createStub(ChannelRepositoryInterface::class),
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onKickReceived(new KickReceivedEvent(new ChannelName('#chan'), '002DEF', 'Kicked'));
    }

    #[Test]
    public function onKickReceivedDispatchesUserLeftChannelWhenTargetFound(): void
    {
        $target = new NetworkUser(
            new Uid('002DEF456'),
            new Nick('TargetNick'),
            new Ident('ident'),
            'host.example',
            'cloak.example',
            'vhost.example',
            '+i',
            new DateTimeImmutable('2024-01-01'),
            'Real',
            '001',
            '*',
        );
        $userRepo = $this->createMock(NetworkUserRepositoryInterface::class);
        $userRepo->expects(self::atLeastOnce())->method('findByUid')->with(self::callback(static fn (Uid $u) => '002DEF456' === $u->value))->willReturn($target);

        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $enricher = new NetworkEventEnricher(
            $this->createStub(ChannelRepositoryInterface::class),
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onKickReceived(new KickReceivedEvent(new ChannelName('#chan'), '002DEF456', 'Kicked'));

        self::assertInstanceOf(UserLeftChannelEvent::class, $captured);
        self::assertSame('002DEF456', $captured->uid->value);
        self::assertSame('#chan', $captured->channel->value);
        self::assertTrue($captured->wasKicked);
    }

    #[Test]
    public function onFjoinReceivedCreatesNewChannelAndDispatchesChannelSyncedEvent(): void
    {
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn(null);
        $channelRepo->expects(self::once())->method('save')->with(self::callback(static fn (Channel $c) => '#test' === $c->name->value && '+nt' === $c->getModes()));

        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $event = new FjoinReceivedEvent(
            new ChannelName('#test'),
            1704067200,
            '+nt',
            [['uid' => new Uid('001ABC'), 'role' => ChannelMemberRole::Op, 'prefixLetters' => ['o']]],
            [],
            [],
        );
        $enricher->onFjoinReceived($event);

        self::assertInstanceOf(ChannelSyncedEvent::class, $captured);
        self::assertSame('#test', $captured->channel->name->value);
    }

    #[Test]
    public function onFmodeReceivedUpdatesChannelAndDispatchesChannelModesChangedEvent(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+n', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save')->with(self::identicalTo($channel));

        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onFmodeReceived(new FmodeReceivedEvent(new ChannelName('#chan'), '+t'));

        self::assertInstanceOf(ChannelModesChangedEvent::class, $captured);
        self::assertSame('#chan', $captured->channel->name->value);
    }

    #[Test]
    public function onFmodeReceivedDoesNothingWhenChannelNotFound(): void
    {
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn(null);
        $channelRepo->expects(self::never())->method('save');
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onFmodeReceived(new FmodeReceivedEvent(new ChannelName('#chan'), '+t'));
    }

    #[Test]
    public function onLmodeReceivedAddsBanAndDispatchesChannelModesChangedEvent(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onLmodeReceived(new LmodeReceivedEvent(new ChannelName('#chan'), 'b', ['*!*@bad.host']));

        self::assertInstanceOf(ChannelModesChangedEvent::class, $captured);
    }

    #[Test]
    public function onFtopicReceivedUpdatesTopicAndDispatchesChannelTopicChangedEvent(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onFtopicReceived(new FtopicReceivedEvent(new ChannelName('#chan'), 'New topic'));

        self::assertInstanceOf(ChannelTopicChangedEvent::class, $captured);
        self::assertSame('New topic', $channel->getTopic());
    }

    #[Test]
    public function onFtopicReceivedDoesNothingWhenChannelNotFound(): void
    {
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn(null);
        $channelRepo->expects(self::never())->method('save');
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onFtopicReceived(new FtopicReceivedEvent(new ChannelName('#chan'), 'Topic'));
    }

    #[Test]
    public function onModeReceivedCreatesChannelWhenNotFoundAndHasModeDelta(): void
    {
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn(null);
        $channelRepo->expects(self::exactly(2))->method('save')->with(self::callback(static fn (Channel $c): bool => '#chan' === $c->name->value));

        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn([]);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $enricher->onModeReceived(new ModeReceivedEvent(new ChannelName('#chan'), '+n', []));

        self::assertInstanceOf(ChannelModesChangedEvent::class, $captured);
    }

    #[Test]
    public function onUmode2ReceivedDispatchesUserModeChangedWhenUserFound(): void
    {
        $user = new NetworkUser(
            new Uid('001ABC123'),
            new Nick('Nick'),
            new Ident('ident'),
            'host.example',
            'cloak.example',
            'vhost.example',
            '+i',
            new DateTimeImmutable('2024-01-01'),
            'Real',
            '001',
            '*',
        );
        $userRepo = $this->createStub(NetworkUserRepositoryInterface::class);
        $userRepo->method('findByUid')->willReturn($user);

        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $enricher = new NetworkEventEnricher(
            $this->createStub(ChannelRepositoryInterface::class),
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onUmode2Received(new Umode2ReceivedEvent('001ABC123', '+x'));

        self::assertInstanceOf(UserModeChangedEvent::class, $captured);
        self::assertSame('001ABC123', $captured->uid->value);
        self::assertSame('+x', $captured->modeDelta);
    }

    #[Test]
    public function onUmode2ReceivedDoesNothingWhenUserNotFound(): void
    {
        $userRepo = $this->createStub(NetworkUserRepositoryInterface::class);
        $userRepo->method('findByUid')->willReturn(null);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $enricher = new NetworkEventEnricher(
            $this->createStub(ChannelRepositoryInterface::class),
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onUmode2Received(new Umode2ReceivedEvent('001ABC123', '+x'));
    }

    #[Test]
    public function onSethostReceivedDispatchesUserHostChangedWhenUserFound(): void
    {
        $user = new NetworkUser(
            new Uid('001ABC123'),
            new Nick('Nick'),
            new Ident('ident'),
            'host.example',
            'cloak.example',
            'vhost.example',
            '+i',
            new DateTimeImmutable('2024-01-01'),
            'Real',
            '001',
            '*',
        );
        $userRepo = $this->createStub(NetworkUserRepositoryInterface::class);
        $userRepo->method('findByUid')->willReturn($user);

        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $enricher = new NetworkEventEnricher(
            $this->createStub(ChannelRepositoryInterface::class),
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onSethostReceived(new SethostReceivedEvent('001ABC123', 'new.host.name'));

        self::assertInstanceOf(UserHostChangedEvent::class, $captured);
        self::assertSame('001ABC123', $captured->uid->value);
        self::assertSame('new.host.name', $captured->newHost);
    }

    #[Test]
    public function onSethostReceivedDoesNothingWhenUserNotFound(): void
    {
        $userRepo = $this->createStub(NetworkUserRepositoryInterface::class);
        $userRepo->method('findByUid')->willReturn(null);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $enricher = new NetworkEventEnricher(
            $this->createStub(ChannelRepositoryInterface::class),
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onSethostReceived(new SethostReceivedEvent('001ABC123', 'new.host'));
    }

    #[Test]
    public function applyOutgoingChannelModesUpdatesChannelAndDispatchesChannelModesChangedEvent(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+n', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->applyOutgoingChannelModes('#chan', '+t', []);

        self::assertInstanceOf(ChannelModesChangedEvent::class, $captured);
        self::assertStringContainsString('t', $channel->getModes());
    }

    #[Test]
    public function applyOutgoingChannelModesDoesNothingWhenChannelNotFound(): void
    {
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn(null);
        $channelRepo->expects(self::never())->method('save');
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->applyOutgoingChannelModes('#chan', '+t', []);
    }

    #[Test]
    public function onFjoinReceivedExistingChannelUpdatesAndDispatchesChannelSyncedEvent(): void
    {
        $existingChannel = new Channel(new ChannelName('#test'), '+n', new DateTimeImmutable('@0'));
        $existingChannel->syncMember(new Uid('001EXISTING'), ChannelMemberRole::Op, ['o']);

        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($existingChannel);
        $channelRepo->expects(self::once())->method('save');

        $dispatched = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::atLeastOnce())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $event = new FjoinReceivedEvent(
            new ChannelName('#test'),
            1704067200,
            '+nt',
            [['uid' => new Uid('001NEW'), 'role' => ChannelMemberRole::Voice, 'prefixLetters' => ['v']]],
            [],
            [],
        );
        $enricher->onFjoinReceived($event);

        self::assertInstanceOf(ChannelSyncedEvent::class, $dispatched[0]);
        self::assertInstanceOf(UserJoinedChannelEvent::class, $dispatched[1]);
        self::assertSame('001NEW', $dispatched[1]->uid->value);
    }

    #[Test]
    public function onFjoinReceivedDispatchesUserJoinedChannelEventForExistingChannel(): void
    {
        $existingChannel = new Channel(new ChannelName('#test'), '+n', new DateTimeImmutable('@0'));

        $channelRepo = $this->createStub(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($existingChannel);

        $dispatched = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::exactly(2))->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatched): object {
                $dispatched[] = $event;

                return $event;
            });

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $event = new FjoinReceivedEvent(
            new ChannelName('#test'),
            1704067200,
            '+nt',
            [['uid' => new Uid('001ABC'), 'role' => ChannelMemberRole::Op, 'prefixLetters' => ['o']]],
            [],
            [],
        );
        $enricher->onFjoinReceived($event);

        self::assertCount(2, $dispatched);
        self::assertInstanceOf(ChannelSyncedEvent::class, $dispatched[0]);
        self::assertInstanceOf(UserJoinedChannelEvent::class, $dispatched[1]);
    }

    #[Test]
    public function onLmodeReceivedWithExemptModeAddsExempt(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onLmodeReceived(new LmodeReceivedEvent(new ChannelName('#chan'), 'e', ['*!*@exempt.host']));

        self::assertInstanceOf(ChannelModesChangedEvent::class, $captured);
    }

    #[Test]
    public function onLmodeReceivedWithInviteExceptionModeAddsInviteException(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onLmodeReceived(new LmodeReceivedEvent(new ChannelName('#chan'), 'I', ['*!*@invite.host']));

        self::assertInstanceOf(ChannelModesChangedEvent::class, $captured);
    }

    #[Test]
    public function onLmodeReceivedEmptyMaskBreaksLoop(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onLmodeReceived(new LmodeReceivedEvent(new ChannelName('#chan'), 'b', ['', '*!*@bad.host']));
    }

    #[Test]
    public function onFjoinReceivedWithListModesEIAddsExemptsAndInviteExceptions(): void
    {
        $dispatched = [];
        $channel = new Channel(new ChannelName('#test'), '+n', new DateTimeImmutable('@0'));

        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn(null);
        $channelRepo->expects(self::once())->method('save')
            ->willReturnCallback(static function (Channel $c) use (&$dispatched): void {
                $dispatched['channel'] = $c;
            });

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $event = new FjoinReceivedEvent(
            new ChannelName('#test'),
            1704067200,
            '+nt',
            [],
            ['b' => ['*!*@bad.host'], 'e' => ['*!*@exempt.host'], 'I' => ['*!*@invite.host']],
            [],
        );
        $enricher->onFjoinReceived($event);

        self::assertNotNull($dispatched['channel']);
    }

    #[Test]
    public function onModeReceivedWithChannelFoundAppliesModes(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+n', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn([]);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $enricher->onModeReceived(new ModeReceivedEvent(new ChannelName('#chan'), '+t', []));

        self::assertInstanceOf(ChannelModesChangedEvent::class, $captured);
        self::assertStringContainsString('t', $channel->getModes());
    }

    #[Test]
    public function onModeReceivedWithPrefixModesAppliesPrefixChanges(): void
    {
        $user = new NetworkUser(
            new Uid('001ABC123'),
            new Nick('Nick'),
            new Ident('ident'),
            'host.example',
            'cloak.example',
            'vhost.example',
            '+i',
            new DateTimeImmutable('2024-01-01'),
            'Real',
            '001',
            '*',
        );

        $channel = new Channel(new ChannelName('#chan'), '+n', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $userRepo = $this->createStub(NetworkUserRepositoryInterface::class);
        $userRepo->method('findByUid')->willReturn($user);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn([]);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $enricher->onModeReceived(new ModeReceivedEvent(new ChannelName('#chan'), '+o', ['001ABC123']));
    }

    #[Test]
    public function onModeReceivedWithListModesAddsAndRemoves(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+n', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn([]);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $enricher->onModeReceived(new ModeReceivedEvent(new ChannelName('#chan'), '+b-b', ['*!*@bad.host', '*!*@old.host']));
    }

    #[Test]
    public function onModeReceivedWithChannelSettingModesAppliesParam(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+n', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn(['k', 'l', 'L']);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $enricher->onModeReceived(new ModeReceivedEvent(new ChannelName('#chan'), '+k', ['secretkey']));
    }

    #[Test]
    public function applyOutgoingChannelModesInvalidChannelNameReturnsEarly(): void
    {
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->expects(self::never())->method('findByName');
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->applyOutgoingChannelModes('invalid', '+t', []);
    }

    #[Test]
    public function applyOutgoingChannelModesEmptyModeStrDoesNothing(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+n', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->applyOutgoingChannelModes('#chan', '', []);
    }

    #[Test]
    public function applyOutgoingChannelModesWithParamsAppliesModeParam(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+n', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn(['k']);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $enricher->applyOutgoingChannelModes('#chan', '+k', ['secretkey']);
    }

    #[Test]
    public function onNickChangeReceivedInvalidNickReturnsEarly(): void
    {
        $user = new NetworkUser(
            new Uid('001ABC123'),
            new Nick('OldNick'),
            new Ident('ident'),
            'host.example',
            'cloak.example',
            'vhost.example',
            '+i',
            new DateTimeImmutable('2024-01-01'),
            'Real',
            '001',
            '*',
        );
        $userRepo = $this->createStub(NetworkUserRepositoryInterface::class);
        $userRepo->method('findByUid')->willReturn($user);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $enricher = new NetworkEventEnricher(
            $this->createStub(ChannelRepositoryInterface::class),
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onNickChangeReceived(new NickChangeReceivedEvent('001ABC123', ''));
    }

    #[Test]
    public function onNickChangeReceivedWithInvalidNickContainingSpacesReturnsEarly(): void
    {
        $user = new NetworkUser(
            new Uid('001ABC123'),
            new Nick('OldNick'),
            new Ident('ident'),
            'host.example',
            'cloak.example',
            'vhost.example',
            '+i',
            new DateTimeImmutable('2024-01-01'),
            'Real',
            '001',
            '*',
        );
        $userRepo = $this->createStub(NetworkUserRepositoryInterface::class);
        $userRepo->method('findByUid')->willReturn($user);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $enricher = new NetworkEventEnricher(
            $this->createStub(ChannelRepositoryInterface::class),
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onNickChangeReceived(new NickChangeReceivedEvent('001ABC123', 'Invalid Nick'));
    }

    #[Test]
    public function resolveUserFallsBackToFindByNickWhenUidInvalid(): void
    {
        $user = new NetworkUser(
            new Uid('001ABC123'),
            new Nick('TestNick'),
            new Ident('ident'),
            'host.example',
            'cloak.example',
            'vhost.example',
            '+i',
            new DateTimeImmutable('2024-01-01'),
            'Real',
            '001',
            '*',
        );

        $userRepo = $this->createMock(NetworkUserRepositoryInterface::class);
        $userRepo->expects(self::never())->method('findByUid');
        $userRepo->expects(self::once())->method('findByNick')->willReturn($user);

        $channelRepo = $this->createStub(ChannelRepositoryInterface::class);
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onPartReceived(new PartReceivedEvent('NotAValidUID', new ChannelName('#test'), '', false));
    }

    #[Test]
    public function resolveUserReturnsNullWhenUidInvalidAndNickNotFound(): void
    {
        $userRepo = $this->createMock(NetworkUserRepositoryInterface::class);
        $userRepo->expects(self::never())->method('findByUid');
        $userRepo->expects(self::once())->method('findByNick')->willReturn(null);

        $channelRepo = $this->createStub(ChannelRepositoryInterface::class);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onPartReceived(new PartReceivedEvent('NotAValidUID', new ChannelName('#test'), '', false));
    }

    #[Test]
    public function resolveUserHandlesInvalidUidFormatGracefully(): void
    {
        $user = new NetworkUser(
            new Uid('001ABC123'),
            new Nick('TestNick'),
            new Ident('ident'),
            'host.example',
            'cloak.example',
            'vhost.example',
            '+i',
            new DateTimeImmutable('2024-01-01'),
            'Real',
            '001',
            '*',
        );

        $userRepo = $this->createMock(NetworkUserRepositoryInterface::class);
        $userRepo->expects(self::never())->method('findByUid');
        $userRepo->expects(self::once())->method('findByNick')->willReturn($user);

        $channelRepo = $this->createStub(ChannelRepositoryInterface::class);
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onPartReceived(new PartReceivedEvent('NotAValidUID', new ChannelName('#test'), '', false));
    }

    #[Test]
    public function applyOutgoingChannelModesSkipsPrefixModes(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+nt', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn(['l']);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $enricher->applyOutgoingChannelModes('#chan', '+ov', ['001ABC', 'nick'], []);

        self::assertStringContainsString('n', $channel->getModes());
        self::assertStringContainsString('t', $channel->getModes());
    }

    #[Test]
    public function applyOutgoingChannelModesSkipsListModes(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+n', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn([]);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $enricher->applyOutgoingChannelModes('#chan', '+b', ['*!*@bad.host'], []);

        self::assertStringNotContainsString('b', $channel->getModes());
    }

    #[Test]
    public function applyOutgoingChannelModesClearsParamOnUnsetMode(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+nk', new DateTimeImmutable('@0'));
        $channel->applyModeParam('k', 'secretkey');
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn(['k']);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $enricher->applyOutgoingChannelModes('#chan', '-k', [], []);
    }

    #[Test]
    public function onModeReceivedWithChannelFoundDoesNotCreateNewChannel(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+n', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn([]);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $enricher->onModeReceived(new ModeReceivedEvent(new ChannelName('#chan'), '+s', []));

        self::assertStringContainsString('s', $channel->getModes());
    }

    #[Test]
    public function onModeReceivedWhenChannelNotFoundAndNoModeDeltaReturnsEarly(): void
    {
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn(null);
        $channelRepo->expects(self::never())->method('save');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn([]);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $enricher->onModeReceived(new ModeReceivedEvent(new ChannelName('#chan'), '+o', ['someuser']));
    }

    #[Test]
    public function onModeReceivedWithPrefixModeUserNotFoundSkipsGracefully(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+n', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $userRepo = $this->createStub(NetworkUserRepositoryInterface::class);
        $userRepo->method('findByUid')->willReturn(null);

        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn([]);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $enricher->onModeReceived(new ModeReceivedEvent(new ChannelName('#chan'), '+o', ['001NOTFND']));

        self::assertInstanceOf(ChannelModesChangedEvent::class, $captured);
    }

    #[Test]
    public function onFjoinReceivedWithModeParamsAppliesThemToChannel(): void
    {
        $channel = new Channel(new ChannelName('#test'), '+n', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn(['k', 'l', 'L']);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $event = new FjoinReceivedEvent(
            new ChannelName('#test'),
            1704067200,
            '+lk',
            [],
            [],
            ['key123', '100'],
        );
        $enricher->onFjoinReceived($event);
    }

    #[Test]
    public function onFjoinReceivedWithMinusModeStrSkipsParamApplication(): void
    {
        $channel = new Channel(new ChannelName('#test'), '+lk', new DateTimeImmutable('@0'));
        $channel->applyModeParam('k', 'oldkey');
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::atLeastOnce())->method('dispatch');

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn(['k', 'l', 'L']);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $event = new FjoinReceivedEvent(
            new ChannelName('#test'),
            1704067200,
            '-l+k',
            [['uid' => new Uid('001ABC'), 'role' => ChannelMemberRole::Op, 'prefixLetters' => ['o']]],
            [],
            ['newkey'],
        );
        $enricher->onFjoinReceived($event);
    }

    #[Test]
    public function onFjoinReceivedModeParamsExhaustedBreaksLoop(): void
    {
        $channel = new Channel(new ChannelName('#test'), '+n', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn(null);
        $channelRepo->expects(self::once())->method('save');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn(['k', 'l', 'L']);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $event = new FjoinReceivedEvent(
            new ChannelName('#test'),
            1704067200,
            '+kl',
            [],
            [],
            ['keyonly'],
        );
        $enricher->onFjoinReceived($event);
    }

    #[Test]
    public function onModeReceivedPrefixModeParamsExhaustedBreaksLoop(): void
    {
        $user = new NetworkUser(
            new Uid('001ABC123'),
            new Nick('Nick'),
            new Ident('ident'),
            'host.example',
            'cloak.example',
            'vhost.example',
            '+i',
            new DateTimeImmutable('2024-01-01'),
            'Real',
            '001',
            '*',
        );

        $channel = new Channel(new ChannelName('#chan'), '+n', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $userRepo = $this->createStub(NetworkUserRepositoryInterface::class);
        $userRepo->method('findByUid')->willReturn($user);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn([]);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $userRepo,
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $enricher->onModeReceived(new ModeReceivedEvent(new ChannelName('#chan'), '+o', []));
    }

    #[Test]
    public function onModeReceivedListModeParamsExhaustedBreaksLoop(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+n', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn([]);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $enricher->onModeReceived(new ModeReceivedEvent(new ChannelName('#chan'), '+b', []));
    }

    #[Test]
    public function onModeReceivedChannelSettingModeParamsExhaustedBreaksLoop(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+n', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn(['k', 'l']);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $enricher->onModeReceived(new ModeReceivedEvent(new ChannelName('#chan'), '+k', []));
    }

    #[Test]
    public function onModeReceivedRemovesExemptMode(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+n', new DateTimeImmutable('@0'));
        $channel->addExempt('*!*@exempt.host');
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn([]);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $enricher->onModeReceived(new ModeReceivedEvent(new ChannelName('#chan'), '-e', ['*!*@exempt.host']));

        self::assertInstanceOf(ChannelModesChangedEvent::class, $captured);
    }

    #[Test]
    public function onModeReceivedRemovesInviteException(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+n', new DateTimeImmutable('@0'));
        $channel->addInviteException('*!*@invite.host');
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn([]);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $enricher->onModeReceived(new ModeReceivedEvent(new ChannelName('#chan'), '-I', ['*!*@invite.host']));

        self::assertInstanceOf(ChannelModesChangedEvent::class, $captured);
    }

    #[Test]
    public function onModeReceivedClearsModeParamOnUnset(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+nk', new DateTimeImmutable('@0'));
        $channel->applyModeParam('k', 'secretkey');
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn(['k']);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $enricher->onModeReceived(new ModeReceivedEvent(new ChannelName('#chan'), '-k', []));

        self::assertInstanceOf(ChannelModesChangedEvent::class, $captured);
    }

    #[Test]
    public function mergeModeStringReturnsCurrentWhenDeltaIsEmpty(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+nt', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->applyOutgoingChannelModes('#chan', '', []);

        self::assertInstanceOf(ChannelModesChangedEvent::class, $captured);
        self::assertSame('+nt', $channel->getModes());
    }

    #[Test]
    public function onModeReceivedWithMixedAddRemoveModes(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+nt', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn([]);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $enricher->onModeReceived(new ModeReceivedEvent(new ChannelName('#chan'), '+s-n', []));

        self::assertInstanceOf(ChannelModesChangedEvent::class, $captured);
        self::assertStringContainsString('s', $channel->getModes());
        self::assertStringNotContainsString('n', $channel->getModes());
    }

    #[Test]
    public function onLmodeReceivedDoesNothingWhenChannelNotFound(): void
    {
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn(null);
        $channelRepo->expects(self::never())->method('save');
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onLmodeReceived(new LmodeReceivedEvent(new ChannelName('#chan'), 'b', ['*!*@bad.host']));
    }

    #[Test]
    public function onFmodeReceivedEmptyModeStrMergesCorrectly(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+nt', new DateTimeImmutable('@0'));
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );

        $enricher->onFmodeReceived(new FmodeReceivedEvent(new ChannelName('#chan'), ''));

        self::assertInstanceOf(ChannelModesChangedEvent::class, $captured);
        self::assertSame('+nt', $channel->getModes());
    }

    #[Test]
    public function onModeReceivedUnsetsChannelSettingMode(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+nkl', new DateTimeImmutable('@0'));
        $channel->applyModeParam('k', 'secretkey');
        $channel->applyModeParam('l', '100');
        $channelRepo = $this->createMock(ChannelRepositoryInterface::class);
        $channelRepo->method('findByName')->willReturn($channel);
        $channelRepo->expects(self::once())->method('save');

        $captured = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$captured): object {
                $captured = $event;

                return $event;
            });

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn(['k', 'l', 'L']);
        $modeProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $channelRepo,
            $this->createStub(NetworkUserRepositoryInterface::class),
            $eventDispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistryInterface::class),
            $modeProvider,
        );

        $enricher->onModeReceived(new ModeReceivedEvent(new ChannelName('#chan'), '-k-l', ['dummykey', '']));

        self::assertInstanceOf(ChannelModesChangedEvent::class, $captured);
    }
}
