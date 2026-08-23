<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber;

use App\Application\ChanServ\Event\ChannelMlockUpdatedEvent;
use App\Application\ChanServ\Event\ChannelTopiclockUpdatedEvent;
use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelView;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\UdbRecordWriterInterface;
use App\Domain\ChanServ\Entity\ChannelAccess;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Event\ChannelAccessChangedEvent;
use App\Domain\ChanServ\Event\ChannelDropEvent;
use App\Domain\ChanServ\Event\ChannelForbiddenEvent;
use App\Domain\ChanServ\Event\ChannelFounderChangedEvent;
use App\Domain\ChanServ\Event\ChannelRegisteredEvent;
use App\Domain\ChanServ\Event\ChannelSuspendedEvent;
use App\Domain\ChanServ\Event\ChannelUnforbiddenEvent;
use App\Domain\ChanServ\Event\ChannelUnsuspendedEvent;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\ChannelModesChangedEvent;
use App\Domain\IRC\Event\ChannelTopicChangedEvent;
use App\Domain\IRC\Network\Channel;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbRecordReceivedEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbSyncCompleteEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbSyncRequestedEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber\UdbChannelSyncSubscriber;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbChannelModesFormatter;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbChannelModeSupport;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbRecordWriter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(UdbChannelSyncSubscriber::class)]
final class UdbChannelSyncSubscriberTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    private function createSubscriber(
        ?RegisteredChannelRepositoryInterface $chanRepo = null,
        ?RegisteredNickRepositoryInterface $nickRepo = null,
        ?ChannelAccessRepositoryInterface $accessRepo = null,
        ?ChannelLookupPort $channelLookup = null,
        string $protocol = 'unrealudb',
    ): UdbChannelSyncSubscriber {
        $holder = $this->createConnectedHolder($protocol);
        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn(new UnrealUdbChannelModeSupport());

        return new UdbChannelSyncSubscriber(
            $holder,
            new UnrealUdbRecordWriter($holder),
            $chanRepo ?? $this->createStub(RegisteredChannelRepositoryInterface::class),
            $nickRepo ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            $accessRepo ?? $this->createStub(ChannelAccessRepositoryInterface::class),
            $modeSupportProvider,
            $channelLookup ?? $this->createStub(ChannelLookupPort::class),
            new UdbChannelModesFormatter(),
        );
    }

    private function createConnectedHolder(string $protocol): ActiveConnectionHolder
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });
        $connection->method('isConnected')->willReturn(true);

        $holder = new ActiveConnectionHolder();
        $reflection = new ReflectionClass($holder);
        $property = $reflection->getProperty('connection');
        $property->setValue($holder, $connection);
        $sidProperty = $reflection->getProperty('serverSid');
        $sidProperty->setValue($holder, '001');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getProtocolName')->willReturn($protocol);
        $holder->setProtocolModule($module);

        return $holder;
    }

    public function testGetSubscribedEvents(): void
    {
        $events = UdbChannelSyncSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(ChannelRegisteredEvent::class, $events);
        $this->assertArrayHasKey(ChannelDropEvent::class, $events);
        $this->assertArrayHasKey(ChannelFounderChangedEvent::class, $events);
        $this->assertArrayHasKey(ChannelForbiddenEvent::class, $events);
        $this->assertArrayHasKey(ChannelUnforbiddenEvent::class, $events);
        $this->assertArrayHasKey(ChannelSuspendedEvent::class, $events);
        $this->assertArrayHasKey(ChannelUnsuspendedEvent::class, $events);
        $this->assertArrayHasKey(ChannelAccessChangedEvent::class, $events);
        $this->assertArrayHasKey(ChannelMlockUpdatedEvent::class, $events);
        $this->assertArrayHasKey(ChannelTopiclockUpdatedEvent::class, $events);
        $this->assertArrayHasKey(ChannelModesChangedEvent::class, $events);
        $this->assertArrayHasKey(UdbSyncRequestedEvent::class, $events);
        $this->assertArrayHasKey(UdbSyncCompleteEvent::class, $events);
        $this->assertArrayHasKey(UdbRecordReceivedEvent::class, $events);
    }

    public function testOnChannelDropDoesNothingWhenNotConnected(): void
    {
        $sub = new UdbChannelSyncSubscriber(
            new ActiveConnectionHolder(),
            $this->createStub(UdbRecordWriterInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            $this->createStub(ChannelLookupPort::class),
        );

        $sub->onChannelDrop(new ChannelDropEvent(1, '#chan', '#chan', 'manual'));

        self::assertSame([], $this->written);
    }

    public function testOnChannelDropDoesNothingWhenProtocolIsNotUdb(): void
    {
        $sub = $this->createSubscriber(protocol: 'inspircd');

        $sub->onChannelDrop(new ChannelDropEvent(1, '#chan', '#chan', 'manual'));

        self::assertSame([], $this->written);
    }

    public function testOnChannelDropSendsDelete(): void
    {
        $sub = $this->createSubscriber();
        $sub->onChannelDrop(new ChannelDropEvent(1, '#chan', '#chan', 'manual'));

        self::assertSame([':001 DB * DEL C::#chan'], $this->written);
    }

    public function testOnChannelRegisteredDoesNothingWhenNotConnected(): void
    {
        $sub = new UdbChannelSyncSubscriber(
            new ActiveConnectionHolder(),
            $this->createStub(UdbRecordWriterInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            $this->createStub(ChannelLookupPort::class),
        );

        $sub->onChannelRegistered(new ChannelRegisteredEvent(1, '#chan', '#chan'));

        self::assertSame([], $this->written);
    }

    public function testOnChannelRegisteredNoChannel(): void
    {
        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn(null);

        $sub = $this->createSubscriber($chanRepo);
        $sub->onChannelRegistered(new ChannelRegisteredEvent(1, '#chan', '#chan'));

        self::assertSame([], $this->written);
    }

    public function testOnChannelRegisteredSuccessWithTopicModesAndMlock(): void
    {
        $chan = $this->createStub(RegisteredChannel::class);
        $chan->method('getFounderNickId')->willReturn(10);
        $chan->method('getTopic')->willReturn('Welcome');
        $chan->method('isMlockActive')->willReturn(true);
        $chan->method('isTopicLock')->willReturn(true);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn($chan);

        $founder = $this->createStub(RegisteredNick::class);
        $founder->method('getNickname')->willReturn('alice');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($founder);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn(new ChannelView(
            name: '#chan',
            modes: '+rPntk',
            topic: 'Welcome',
            memberCount: 1,
            modeParams: ['k' => 'secret'],
        ));

        $sub = $this->createSubscriber($chanRepo, $nickRepo, null, $channelLookup);
        $sub->onChannelRegistered(new ChannelRegisteredEvent(1, '#chan', '#chan'));

        self::assertSame([
            ':001 DB * INS C::#chan::founder :alice',
            ':001 DB * INS C::#chan::topic :Welcome',
            ':001 DB * INS C::#chan::modes :+ntk secret',
            ':001 DB * INS C::#chan::mlock :*1',
            ':001 DB * INS C::#chan::topiclock :*1',
        ], $this->written);
    }

    public function testOnChannelRegisteredWithoutFounder(): void
    {
        $chan = $this->createStub(RegisteredChannel::class);
        $chan->method('getFounderNickId')->willReturn(10);
        $chan->method('getTopic')->willReturn(null);
        $chan->method('isMlockActive')->willReturn(false);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn($chan);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn(null);

        $sub = $this->createSubscriber($chanRepo, $nickRepo);
        $sub->onChannelRegistered(new ChannelRegisteredEvent(1, '#chan', '#chan'));

        self::assertSame([], $this->written);
    }

    public function testOnChannelFounderChanged(): void
    {
        $founder = $this->createStub(RegisteredNick::class);
        $founder->method('getNickname')->willReturn('bob');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($founder);

        $sub = $this->createSubscriber(null, $nickRepo);
        $sub->onChannelFounderChanged(new ChannelFounderChangedEvent(1, '#chan', 1, 2, 'operator', null, '127.0.0.1', 'host'));

        self::assertSame([':001 DB * INS C::#chan::founder :bob'], $this->written);
    }

    public function testOnChannelFounderChangedWithoutFounder(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn(null);

        $sub = $this->createSubscriber(null, $nickRepo);
        $sub->onChannelFounderChanged(new ChannelFounderChangedEvent(1, '#chan', 1, 2, 'operator', null, '127.0.0.1', 'host'));

        self::assertSame([], $this->written);
    }

    public function testOnChannelForbiddenAndUnforbidden(): void
    {
        $sub = $this->createSubscriber();
        $sub->onChannelForbidden(new ChannelForbiddenEvent(1, '#chan', '#chan', 'Bad channel', 'operator'));

        self::assertSame([':001 DB * INS C::#chan::forbid :Bad channel'], $this->written);

        $this->written = [];
        $sub->onChannelUnforbidden(new ChannelUnforbiddenEvent('#chan', '#chan', 'operator'));

        self::assertSame([':001 DB * DEL C::#chan::forbid'], $this->written);
    }

    public function testOnChannelSuspendedAndUnsuspended(): void
    {
        $sub = $this->createSubscriber();
        $sub->onChannelSuspended(new ChannelSuspendedEvent(1, '#chan', '#chan', 'reason', null, null, 'operator', null, '127.0.0.1', 'host'));

        self::assertSame([':001 DB * INS C::#chan::suspended :1'], $this->written);

        $this->written = [];
        $sub->onChannelUnsuspended(new ChannelUnsuspendedEvent(1, '#chan', '#chan', 'operator', null, '127.0.0.1', 'host'));

        self::assertSame([':001 DB * DEL C::#chan::suspended'], $this->written);
    }

    public function testOnChannelAccessChanged(): void
    {
        $sub = $this->createSubscriber();
        $sub->onChannelAccessChanged(new ChannelAccessChangedEvent(1, '#chan', 'ADD', 20, 'alice', 5, 'operator', null, '127.0.0.1', 'host'));

        self::assertSame([':001 DB * INS C::#chan::access::alice :5'], $this->written);

        $this->written = [];
        $sub->onChannelAccessChanged(new ChannelAccessChangedEvent(1, '#chan', 'DEL', 20, 'alice', null, 'operator', null, '127.0.0.1', 'host'));

        self::assertSame([':001 DB * DEL C::#chan::access::alice'], $this->written);
    }

    public function testOnChannelMlockUpdatedInsertsFlagWhenActive(): void
    {
        $chan = $this->createStub(RegisteredChannel::class);
        $chan->method('getName')->willReturn('#chan');
        $chan->method('isMlockActive')->willReturn(true);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn($chan);

        $sub = $this->createSubscriber($chanRepo);
        $sub->onChannelMlockUpdated(new ChannelMlockUpdatedEvent('#chan'));

        self::assertSame([':001 DB * INS C::#chan::mlock :*1'], $this->written);
    }

    public function testOnChannelMlockUpdatedDeletesFlagWhenInactive(): void
    {
        $chan = $this->createStub(RegisteredChannel::class);
        $chan->method('getName')->willReturn('#chan');
        $chan->method('isMlockActive')->willReturn(false);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn($chan);

        $sub = $this->createSubscriber($chanRepo);
        $sub->onChannelMlockUpdated(new ChannelMlockUpdatedEvent('#chan'));

        self::assertSame([':001 DB * DEL C::#chan::mlock'], $this->written);
    }

    public function testOnChannelMlockUpdatedDoesNothingWhenChannelNotFound(): void
    {
        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn(null);

        $sub = $this->createSubscriber($chanRepo);
        $sub->onChannelMlockUpdated(new ChannelMlockUpdatedEvent('#chan'));

        self::assertSame([], $this->written);
    }

    public function testOnChannelMlockUpdatedDoesNothingWhenUdbNotActive(): void
    {
        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $sub = $this->createSubscriber($chanRepo, null, null, null, 'unreal');
        $sub->onChannelMlockUpdated(new ChannelMlockUpdatedEvent('#chan'));

        self::assertSame([], $this->written);
    }

    public function testOnChannelTopiclockUpdatedInsertsFlagWhenActive(): void
    {
        $chan = $this->createStub(RegisteredChannel::class);
        $chan->method('getName')->willReturn('#chan');
        $chan->method('isTopicLock')->willReturn(true);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn($chan);

        $sub = $this->createSubscriber($chanRepo);
        $sub->onChannelTopiclockUpdated(new ChannelTopiclockUpdatedEvent('#chan'));

        self::assertSame([':001 DB * INS C::#chan::topiclock :*1'], $this->written);
    }

    public function testOnChannelTopiclockUpdatedDeletesFlagWhenInactive(): void
    {
        $chan = $this->createStub(RegisteredChannel::class);
        $chan->method('getName')->willReturn('#chan');
        $chan->method('isTopicLock')->willReturn(false);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn($chan);

        $sub = $this->createSubscriber($chanRepo);
        $sub->onChannelTopiclockUpdated(new ChannelTopiclockUpdatedEvent('#chan'));

        self::assertSame([':001 DB * DEL C::#chan::topiclock'], $this->written);
    }

    public function testOnChannelTopiclockUpdatedDoesNothingWhenChannelNotFound(): void
    {
        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn(null);

        $sub = $this->createSubscriber($chanRepo);
        $sub->onChannelTopiclockUpdated(new ChannelTopiclockUpdatedEvent('#chan'));

        self::assertSame([], $this->written);
    }

    public function testOnChannelTopiclockUpdatedDoesNothingWhenUdbNotActive(): void
    {
        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $sub = $this->createSubscriber($chanRepo, null, null, null, 'unreal');
        $sub->onChannelTopiclockUpdated(new ChannelTopiclockUpdatedEvent('#chan'));

        self::assertSame([], $this->written);
    }

    public function testOnChannelModesChangedInsertsModesWithParams(): void
    {
        $chan = $this->createStub(RegisteredChannel::class);
        $chan->method('isForbidden')->willReturn(false);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn($chan);

        $channel = new Channel(new ChannelName('#chan'), '+rPntk', new DateTimeImmutable('@0'));
        $channel->applyModeParam('k', 'mykey');

        $sub = $this->createSubscriber($chanRepo);
        $sub->onChannelModesChanged(new ChannelModesChangedEvent($channel));

        self::assertSame([':001 DB * INS C::#chan::modes :+ntk mykey'], $this->written);
    }

    public function testOnChannelModesChangedDeletesModesWhenEmpty(): void
    {
        $chan = $this->createStub(RegisteredChannel::class);
        $chan->method('isForbidden')->willReturn(false);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn($chan);

        $channel = new Channel(new ChannelName('#chan'), '+rP', new DateTimeImmutable('@0'));

        $sub = $this->createSubscriber($chanRepo);
        $sub->onChannelModesChanged(new ChannelModesChangedEvent($channel));

        self::assertSame([':001 DB * DEL C::#chan::modes'], $this->written);
    }

    public function testOnChannelModesChangedDoesNothingWhenNotRegisteredOrForbidden(): void
    {
        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn(null);

        $channel = new Channel(new ChannelName('#chan'), '+nt', new DateTimeImmutable('@0'));

        $sub = $this->createSubscriber($chanRepo);
        $sub->onChannelModesChanged(new ChannelModesChangedEvent($channel));

        self::assertSame([], $this->written);

        $forbidden = $this->createStub(RegisteredChannel::class);
        $forbidden->method('isForbidden')->willReturn(true);
        $chanRepo2 = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo2->method('findByChannelName')->willReturn($forbidden);

        $sub2 = $this->createSubscriber($chanRepo2);
        $sub2->onChannelModesChanged(new ChannelModesChangedEvent($channel));

        self::assertSame([], $this->written);
    }

    public function testOnChannelTopicChangedInsertsTopicWhenNonEmpty(): void
    {
        $chan = $this->createStub(RegisteredChannel::class);
        $chan->method('isForbidden')->willReturn(false);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn($chan);

        $channel = new Channel(new ChannelName('#chan'), '+nt', new DateTimeImmutable('@0'));
        $channel->updateTopic('New topic from IRC', 'oper', 12345);

        $sub = $this->createSubscriber($chanRepo);
        $sub->onChannelTopicChanged(new ChannelTopicChangedEvent($channel));

        self::assertSame([':001 DB * INS C::#chan::topic :New topic from IRC'], $this->written);
    }

    public function testOnChannelTopicChangedDeletesTopicWhenEmptyOrNull(): void
    {
        $chan = $this->createStub(RegisteredChannel::class);
        $chan->method('isForbidden')->willReturn(false);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn($chan);

        $channel = new Channel(new ChannelName('#chan'), '+nt', new DateTimeImmutable('@0'));

        $sub = $this->createSubscriber($chanRepo);
        $sub->onChannelTopicChanged(new ChannelTopicChangedEvent($channel));

        self::assertSame([':001 DB * DEL C::#chan::topic'], $this->written);
    }

    public function testOnChannelTopicChangedDoesNothingWhenNotRegisteredOrForbidden(): void
    {
        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn(null);

        $channel = new Channel(new ChannelName('#chan'), '+nt', new DateTimeImmutable('@0'));
        $channel->updateTopic('Topic', 'oper', 12345);

        $sub = $this->createSubscriber($chanRepo);
        $sub->onChannelTopicChanged(new ChannelTopicChangedEvent($channel));

        self::assertSame([], $this->written);

        $forbidden = $this->createStub(RegisteredChannel::class);
        $forbidden->method('isForbidden')->willReturn(true);
        $chanRepo2 = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo2->method('findByChannelName')->willReturn($forbidden);

        $sub2 = $this->createSubscriber($chanRepo2);
        $sub2->onChannelTopicChanged(new ChannelTopicChangedEvent($channel));

        self::assertSame([], $this->written);
    }

    public function testOnChannelTopicChangedDoesNothingWhenUdbNotActive(): void
    {
        $chan = $this->createStub(RegisteredChannel::class);
        $chan->method('isForbidden')->willReturn(false);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn($chan);

        $channel = new Channel(new ChannelName('#chan'), '+nt', new DateTimeImmutable('@0'));
        $channel->updateTopic('Topic', 'oper', 12345);

        $sub = $this->createSubscriber($chanRepo, null, null, null, 'unreal');
        $sub->onChannelTopicChanged(new ChannelTopicChangedEvent($channel));

        self::assertSame([], $this->written);
    }

    public function testOnSyncRequestedDoesNothingWhenNotConnected(): void
    {
        $sub = new UdbChannelSyncSubscriber(
            new ActiveConnectionHolder(),
            $this->createStub(UdbRecordWriterInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            $this->createStub(ChannelLookupPort::class),
        );

        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));

        self::assertSame([], $this->written);
    }

    public function testOnSyncRequestedWrongBlock(): void
    {
        $sub = $this->createSubscriber();
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));

        self::assertSame([], $this->written);
    }

    public function testOnSyncRequestedCorrectBlockSendsUnicastRes(): void
    {
        $sub = $this->createSubscriber();
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', 'ABC'));

        self::assertSame([':001 DB ABC RES C'], $this->written);
    }

    public function testOnSyncCompleteDoesNothingWhenNotConnected(): void
    {
        $sub = new UdbChannelSyncSubscriber(
            new ActiveConnectionHolder(),
            $this->createStub(UdbRecordWriterInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            $this->createStub(ChannelLookupPort::class),
        );

        $sub->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));

        self::assertSame([], $this->written);
    }

    public function testOnSyncCompleteWrongBlock(): void
    {
        $sub = $this->createSubscriber();
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('N', '001'));

        self::assertSame([':001 DB 001 RES C'], $this->written);
    }

    public function testOnSyncCompleteWithoutSyncRequest(): void
    {
        $sub = $this->createSubscriber();
        $sub->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));

        self::assertSame([], $this->written);
    }

    public function testOnSyncCompleteRepopulatesFullState(): void
    {
        $chanForbid = $this->createStub(RegisteredChannel::class);
        $chanForbid->method('getId')->willReturn(1);
        $chanForbid->method('getName')->willReturn('#forbid');
        $chanForbid->method('isForbidden')->willReturn(true);
        $chanForbid->method('getForbiddenReason')->willReturn('No');

        $chanSusp = $this->createStub(RegisteredChannel::class);
        $chanSusp->method('getId')->willReturn(2);
        $chanSusp->method('getName')->willReturn('#susp');
        $chanSusp->method('isForbidden')->willReturn(false);
        $chanSusp->method('isSuspended')->willReturn(true);

        $chanActive = $this->createStub(RegisteredChannel::class);
        $chanActive->method('getId')->willReturn(3);
        $chanActive->method('getName')->willReturn('#active');
        $chanActive->method('isForbidden')->willReturn(false);
        $chanActive->method('isSuspended')->willReturn(false);
        $chanActive->method('getFounderNickId')->willReturn(10);
        $chanActive->method('getTopic')->willReturn('Topic');
        $chanActive->method('isMlockActive')->willReturn(true);
        $chanActive->method('isTopicLock')->willReturn(true);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('listAll')->willReturn([$chanForbid, $chanSusp, $chanActive]);

        $nickFounder = $this->createStub(RegisteredNick::class);
        $nickFounder->method('getNickname')->willReturn('alice');

        $nickAccess = $this->createStub(RegisteredNick::class);
        $nickAccess->method('getId')->willReturn(20);
        $nickAccess->method('getNickname')->willReturn('bob');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturnCallback(static function (int $id) use ($nickFounder, $nickAccess): ?RegisteredNick {
            if (10 === $id) {
                return $nickFounder;
            }
            if (20 === $id) {
                return $nickAccess;
            }

            return null;
        });

        $access1 = $this->createStub(ChannelAccess::class);
        $access1->method('getNickId')->willReturn(20);
        $access1->method('getLevel')->willReturn(5);

        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('listByChannel')->willReturnCallback(static fn (int $channelId): array => 3 === $channelId ? [$access1] : []);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturnCallback(static fn (string $name): ?ChannelView => '#active' === $name ? new ChannelView(
            name: '#active',
            modes: '+nt',
            topic: 'Topic',
            memberCount: 1,
        ) : null);

        $sub = $this->createSubscriber($chanRepo, $nickRepo, $accessRepo, $channelLookup);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));

        self::assertSame([
            ':001 DB 001 RES C',
            ':001 DB * INS C::#forbid::forbid :No',
            ':001 DB * INS C::#susp::suspended :1',
            ':001 DB * INS C::#active::founder :alice',
            ':001 DB * INS C::#active::topic :Topic',
            ':001 DB * INS C::#active::modes :+nt',
            ':001 DB * INS C::#active::mlock :*1',
            ':001 DB * INS C::#active::topiclock :*1',
            ':001 DB * INS C::#active::access::bob :5',
        ], $this->written);
    }

    public function testOnSyncCompleteDoesNotReinsertMatchingUdbRecords(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(3);
        $channel->method('getName')->willReturn('#active');
        $channel->method('isForbidden')->willReturn(false);
        $channel->method('isSuspended')->willReturn(false);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getTopic')->willReturn(null);
        $channel->method('isMlockActive')->willReturn(false);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('listAll')->willReturn([$channel]);
        $channelRepo->method('findByChannelName')->willReturn($channel);

        $founder = $this->createStub(RegisteredNick::class);
        $founder->method('getNickname')->willReturn('alice');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($founder);
        $nickRepo->method('findByNick')->willReturn($founder);

        $sub = $this->createSubscriber($channelRepo, $nickRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('C::#active::founder', 'alice'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));

        self::assertSame([':001 DB 001 RES C'], $this->written);
    }

    public function testOnRecordReceivedDeletesPropertiesNotAllowedOnForbiddenChannel(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('isForbidden')->willReturn(true);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($channel);
        $channelRepo->method('listAll')->willReturn([]);

        $sub = $this->createSubscriber($channelRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('C::#chan::founder', 'alice'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));

        self::assertSame([':001 DB 001 RES C', ':001 DB * DEL C::#chan::founder'], $this->written);
    }

    public function testOnRecordReceivedDoesNothingWhenNotConnectedOrMalformed(): void
    {
        $sub = new UdbChannelSyncSubscriber(
            new ActiveConnectionHolder(),
            $this->createStub(UdbRecordWriterInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            $this->createStub(ChannelLookupPort::class),
        );

        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::chan::founder', 'nick'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('C::malformed', 'val'));

        self::assertSame([], $this->written);
    }

    public function testOnRecordReceivedWrongBlockWithConnectedSubscriber(): void
    {
        $chanRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $chanRepo->expects($this->never())->method('findByChannelName');

        $sub = $this->createSubscriber($chanRepo);
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::chan::founder', 'nick'));

        self::assertSame([], $this->written);
    }

    public function testDomainEventsDoNothingWhenNotConnected(): void
    {
        $sub = new UdbChannelSyncSubscriber(
            new ActiveConnectionHolder(),
            $this->createStub(UdbRecordWriterInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            $this->createStub(ChannelLookupPort::class),
        );

        $sub->onChannelFounderChanged(new ChannelFounderChangedEvent(1, '#chan', 1, 2, 'operator', null, '127.0.0.1', 'host'));
        $sub->onChannelForbidden(new ChannelForbiddenEvent(1, '#chan', '#chan', 'reason', 'operator'));
        $sub->onChannelUnforbidden(new ChannelUnforbiddenEvent('#chan', '#chan', 'operator'));
        $sub->onChannelSuspended(new ChannelSuspendedEvent(1, '#chan', '#chan', 'reason', null, null, 'operator', null, '127.0.0.1', 'host'));
        $sub->onChannelUnsuspended(new ChannelUnsuspendedEvent(1, '#chan', '#chan', 'operator', null, '127.0.0.1', 'host'));
        $sub->onChannelAccessChanged(new ChannelAccessChangedEvent(1, '#chan', 'ADD', 20, 'alice', 5, 'operator', null, '127.0.0.1', 'host'));
        $sub->onChannelModesChanged(new ChannelModesChangedEvent(new Channel(new ChannelName('#chan'), '+nt', new DateTimeImmutable('@0'))));
        $sub->onChannelTopicChanged(new ChannelTopicChangedEvent(new Channel(new ChannelName('#chan'), '+nt', new DateTimeImmutable('@0'))));

        self::assertSame([], $this->written);
    }

    public function testOnRecordReceivedChannelNotFoundSendsDel(): void
    {
        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn(null);
        $chanRepo->method('listAll')->willReturn([]);

        $sub = $this->createSubscriber($chanRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('C::#ghost::founder', 'alice'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));

        self::assertSame([':001 DB 001 RES C', ':001 DB * DEL C::#ghost'], $this->written);
    }

    public function testOnRecordReceivedFounderReconciliation(): void
    {
        $chan = $this->createStub(RegisteredChannel::class);
        $chan->method('getId')->willReturn(1);
        $chan->method('isForbidden')->willReturn(false);
        $chan->method('getFounderNickId')->willReturn(10);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn($chan);
        $chanRepo->method('listAll')->willReturn([]);

        $founder = $this->createStub(RegisteredNick::class);
        $founder->method('getNickname')->willReturn('alice');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($founder);

        // 1. Founder differs -> INS
        $sub = $this->createSubscriber($chanRepo, $nickRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('C::#chan::founder', 'eve'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C', ':001 DB * INS C::#chan::founder :alice'], $this->written);

        // 2. Founder matches -> nothing
        $this->written = [];
        $sub2 = $this->createSubscriber($chanRepo, $nickRepo);
        $sub2->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub2->onRecordReceived(new UdbRecordReceivedEvent('C::#chan::founder', 'alice'));
        $sub2->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C'], $this->written);
    }

    public function testOnRecordReceivedFounderWithoutAccountSendsDel(): void
    {
        $chan = $this->createStub(RegisteredChannel::class);
        $chan->method('getId')->willReturn(1);
        $chan->method('isForbidden')->willReturn(false);
        $chan->method('getFounderNickId')->willReturn(10);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn($chan);
        $chanRepo->method('listAll')->willReturn([]);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn(null);

        $sub = $this->createSubscriber($chanRepo, $nickRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('C::#chan::founder', 'alice'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));

        self::assertSame([':001 DB 001 RES C', ':001 DB * DEL C::#chan::founder'], $this->written);
    }

    public function testOnRecordReceivedTopicReconciliation(): void
    {
        $chanNoTopic = $this->createStub(RegisteredChannel::class);
        $chanNoTopic->method('getId')->willReturn(1);
        $chanNoTopic->method('isForbidden')->willReturn(false);
        $chanNoTopic->method('getTopic')->willReturn(null);

        $chanWithTopic = $this->createStub(RegisteredChannel::class);
        $chanWithTopic->method('getId')->willReturn(2);
        $chanWithTopic->method('isForbidden')->willReturn(false);
        $chanWithTopic->method('getTopic')->willReturn('New topic');

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturnCallback(static fn (string $name): ?RegisteredChannel => '#notopic' === $name ? $chanNoTopic : $chanWithTopic);
        $chanRepo->method('listAll')->willReturn([]);

        // 1. No local topic -> DEL
        $sub = $this->createSubscriber($chanRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('C::#notopic::topic', 'Old'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C', ':001 DB * DEL C::#notopic::topic'], $this->written);

        // 2. Local topic differs -> INS
        $this->written = [];
        $sub2 = $this->createSubscriber($chanRepo);
        $sub2->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub2->onRecordReceived(new UdbRecordReceivedEvent('C::#topic::topic', 'Old'));
        $sub2->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C', ':001 DB * INS C::#topic::topic :New topic'], $this->written);

        // 3. Local topic matches -> nothing
        $this->written = [];
        $sub3 = $this->createSubscriber($chanRepo);
        $sub3->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub3->onRecordReceived(new UdbRecordReceivedEvent('C::#topic::topic', 'New topic'));
        $sub3->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C'], $this->written);
    }

    public function testOnRecordReceivedMlockReconciliation(): void
    {
        $chanNoMlock = $this->createStub(RegisteredChannel::class);
        $chanNoMlock->method('getId')->willReturn(1);
        $chanNoMlock->method('isForbidden')->willReturn(false);
        $chanNoMlock->method('isMlockActive')->willReturn(false);

        $chanWithMlock = $this->createStub(RegisteredChannel::class);
        $chanWithMlock->method('getId')->willReturn(2);
        $chanWithMlock->method('isForbidden')->willReturn(false);
        $chanWithMlock->method('isMlockActive')->willReturn(true);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturnCallback(static fn (string $name): ?RegisteredChannel => '#nomlock' === $name ? $chanNoMlock : $chanWithMlock);
        $chanRepo->method('listAll')->willReturn([]);

        // 1. Inactive locally, but UDB sent *1 -> DEL
        $sub = $this->createSubscriber($chanRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('C::#nomlock::mlock', '*1'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C', ':001 DB * DEL C::#nomlock::mlock'], $this->written);

        // 2. Active locally, but UDB had *0 -> INS *1
        $this->written = [];
        $sub2 = $this->createSubscriber($chanRepo);
        $sub2->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub2->onRecordReceived(new UdbRecordReceivedEvent('C::#mlock::mlock', '*0'));
        $sub2->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C', ':001 DB * INS C::#mlock::mlock :*1'], $this->written);

        // 3. Active locally, UDB matches *1 -> nothing
        $this->written = [];
        $sub3 = $this->createSubscriber($chanRepo);
        $sub3->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub3->onRecordReceived(new UdbRecordReceivedEvent('C::#mlock::mlock', '*1'));
        $sub3->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C'], $this->written);
    }

    public function testOnRecordReceivedTopiclockReconciliation(): void
    {
        $chanNoTopiclock = $this->createStub(RegisteredChannel::class);
        $chanNoTopiclock->method('getId')->willReturn(1);
        $chanNoTopiclock->method('isForbidden')->willReturn(false);
        $chanNoTopiclock->method('isTopicLock')->willReturn(false);

        $chanWithTopiclock = $this->createStub(RegisteredChannel::class);
        $chanWithTopiclock->method('getId')->willReturn(2);
        $chanWithTopiclock->method('isForbidden')->willReturn(false);
        $chanWithTopiclock->method('isTopicLock')->willReturn(true);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturnCallback(static fn (string $name): ?RegisteredChannel => '#notopiclock' === $name ? $chanNoTopiclock : $chanWithTopiclock);
        $chanRepo->method('listAll')->willReturn([]);

        // 1. Inactive locally, but UDB sent *1 -> DEL
        $sub = $this->createSubscriber($chanRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('C::#notopiclock::topiclock', '*1'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C', ':001 DB * DEL C::#notopiclock::topiclock'], $this->written);

        // 2. Active locally, but UDB had *0 -> INS *1
        $this->written = [];
        $sub2 = $this->createSubscriber($chanRepo);
        $sub2->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub2->onRecordReceived(new UdbRecordReceivedEvent('C::#topiclock::topiclock', '*0'));
        $sub2->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C', ':001 DB * INS C::#topiclock::topiclock :*1'], $this->written);

        // 3. Active locally, UDB matches *1 -> nothing
        $this->written = [];
        $sub3 = $this->createSubscriber($chanRepo);
        $sub3->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub3->onRecordReceived(new UdbRecordReceivedEvent('C::#topiclock::topiclock', '*1'));
        $sub3->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C'], $this->written);
    }

    public function testOnRecordReceivedModesReconciliation(): void
    {
        $chanNoModes = $this->createStub(RegisteredChannel::class);
        $chanNoModes->method('getId')->willReturn(1);
        $chanNoModes->method('isForbidden')->willReturn(false);

        $chanWithModes = $this->createStub(RegisteredChannel::class);
        $chanWithModes->method('getId')->willReturn(2);
        $chanWithModes->method('isForbidden')->willReturn(false);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturnCallback(static fn (string $name): ?RegisteredChannel => '#nomodes' === $name ? $chanNoModes : $chanWithModes);
        $chanRepo->method('listAll')->willReturn([]);

        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturnCallback(static function (string $name): ?ChannelView {
            if ('#nomodes' === $name) {
                return null;
            }
            if ('#modes' === $name) {
                return new ChannelView(
                    name: '#modes',
                    modes: '+ntk',
                    topic: null,
                    memberCount: 1,
                    modeParams: ['k' => 'secret'],
                );
            }

            return null;
        });

        // 1. No local modes -> DEL
        $sub = $this->createSubscriber($chanRepo, null, null, $channelLookup);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('C::#nomodes::modes', '+nt'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C', ':001 DB * DEL C::#nomodes::modes'], $this->written);

        // 2. Local modes differ -> INS
        $this->written = [];
        $sub2 = $this->createSubscriber($chanRepo, null, null, $channelLookup);
        $sub2->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub2->onRecordReceived(new UdbRecordReceivedEvent('C::#modes::modes', '+nt'));
        $sub2->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C', ':001 DB * INS C::#modes::modes :+ntk secret'], $this->written);

        // 3. Local modes match -> nothing
        $this->written = [];
        $sub3 = $this->createSubscriber($chanRepo, null, null, $channelLookup);
        $sub3->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub3->onRecordReceived(new UdbRecordReceivedEvent('C::#modes::modes', '+ntk secret'));
        $sub3->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C'], $this->written);
    }

    public function testOnRecordReceivedAccessReconciliation(): void
    {
        $chan = $this->createStub(RegisteredChannel::class);
        $chan->method('getId')->willReturn(1);
        $chan->method('isForbidden')->willReturn(false);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn($chan);
        $chanRepo->method('listAll')->willReturn([]);

        $bob = $this->createStub(RegisteredNick::class);
        $bob->method('getId')->willReturn(20);
        $bob->method('getNickname')->willReturn('bob');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($bob);

        // 1. Access record without nick component -> DEL
        $sub = $this->createSubscriber($chanRepo, $nickRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('C::#chan::access', '5'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C', ':001 DB * DEL C::#chan::access'], $this->written);

        // 2. No local access -> DEL
        $this->written = [];
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo->method('findByChannelAndNick')->willReturn(null);
        $sub2 = $this->createSubscriber($chanRepo, $nickRepo, $accessRepo);
        $sub2->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub2->onRecordReceived(new UdbRecordReceivedEvent('C::#chan::access::bob', '5'));
        $sub2->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C', ':001 DB * DEL C::#chan::access::bob'], $this->written);

        // 3. Different level -> INS
        $this->written = [];
        $access = $this->createStub(ChannelAccess::class);
        $access->method('getLevel')->willReturn(7);
        $accessRepo2 = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo2->method('findByChannelAndNick')->willReturn($access);
        $sub3 = $this->createSubscriber($chanRepo, $nickRepo, $accessRepo2);
        $sub3->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub3->onRecordReceived(new UdbRecordReceivedEvent('C::#chan::access::bob', '5'));
        $sub3->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C', ':001 DB * INS C::#chan::access::bob :7'], $this->written);

        // 4. Same level -> nothing
        $this->written = [];
        $accessRepo3 = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepo3->method('findByChannelAndNick')->willReturn($access);
        $sub4 = $this->createSubscriber($chanRepo, $nickRepo, $accessRepo3);
        $sub4->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub4->onRecordReceived(new UdbRecordReceivedEvent('C::#chan::access::bob', '7'));
        $sub4->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C'], $this->written);
    }

    public function testOnRecordReceivedAccessTargetAccountNotFound(): void
    {
        $chan = $this->createStub(RegisteredChannel::class);
        $chan->method('getId')->willReturn(1);
        $chan->method('isForbidden')->willReturn(false);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn($chan);
        $chanRepo->method('listAll')->willReturn([]);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);

        $sub = $this->createSubscriber($chanRepo, $nickRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('C::#chan::access::ghost', '5'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));

        self::assertSame([':001 DB 001 RES C', ':001 DB * DEL C::#chan::access::ghost'], $this->written);
    }

    public function testOnRecordReceivedForbidReconciliation(): void
    {
        $chanNotForbidden = $this->createStub(RegisteredChannel::class);
        $chanNotForbidden->method('getId')->willReturn(1);
        $chanNotForbidden->method('isForbidden')->willReturn(false);

        $chanForbidden = $this->createStub(RegisteredChannel::class);
        $chanForbidden->method('getId')->willReturn(2);
        $chanForbidden->method('isForbidden')->willReturn(true);
        $chanForbidden->method('getForbiddenReason')->willReturn('No entry');

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturnCallback(static fn (string $name): ?RegisteredChannel => '#ok' === $name ? $chanNotForbidden : $chanForbidden);
        $chanRepo->method('listAll')->willReturn([]);

        // 1. Not forbidden locally -> DEL
        $sub = $this->createSubscriber($chanRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('C::#ok::forbid', 'No'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C', ':001 DB * DEL C::#ok::forbid'], $this->written);

        // 2. Forbidden with different reason -> INS
        $this->written = [];
        $sub2 = $this->createSubscriber($chanRepo);
        $sub2->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub2->onRecordReceived(new UdbRecordReceivedEvent('C::#forbid::forbid', 'Old'));
        $sub2->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C', ':001 DB * INS C::#forbid::forbid :No entry'], $this->written);

        // 3. Same reason -> nothing
        $this->written = [];
        $sub3 = $this->createSubscriber($chanRepo);
        $sub3->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub3->onRecordReceived(new UdbRecordReceivedEvent('C::#forbid::forbid', 'No entry'));
        $sub3->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));
        self::assertSame([':001 DB 001 RES C'], $this->written);
    }

    public function testOnRecordReceivedSuspendedReconciliation(): void
    {
        $chanActive = $this->createStub(RegisteredChannel::class);
        $chanActive->method('getId')->willReturn(1);
        $chanActive->method('isForbidden')->willReturn(false);
        $chanActive->method('isSuspended')->willReturn(false);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn($chanActive);
        $chanRepo->method('listAll')->willReturn([]);

        $sub = $this->createSubscriber($chanRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('C::#active::suspended', '1'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));

        self::assertSame([':001 DB 001 RES C', ':001 DB * DEL C::#active::suspended'], $this->written);
    }

    public function testOnRecordReceivedUnknownPropertySendsDel(): void
    {
        $chan = $this->createStub(RegisteredChannel::class);
        $chan->method('getId')->willReturn(1);
        $chan->method('isForbidden')->willReturn(false);

        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('findByChannelName')->willReturn($chan);
        $chanRepo->method('listAll')->willReturn([]);

        $sub = $this->createSubscriber($chanRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('C::#chan::pass', 'hash'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));

        self::assertSame([':001 DB 001 RES C', ':001 DB * DEL C::#chan::pass'], $this->written);
    }

    public function testOnRecordReceivedOutsideSyncDoesNothing(): void
    {
        $chanRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $chanRepo->expects($this->never())->method('findByChannelName');

        $sub = $this->createSubscriber($chanRepo);
        $sub->onRecordReceived(new UdbRecordReceivedEvent('C::#chan::founder', 'alice'));

        self::assertSame([], $this->written);
    }

    public function testOnChannelDropDuringSyncIsBufferedUntilComplete(): void
    {
        $chanRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $chanRepo->method('listAll')->willReturn([]);

        $sub = $this->createSubscriber($chanRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
        $sub->onChannelDrop(new ChannelDropEvent(1, '#chan', '#chan', 'manual'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));

        self::assertSame([':001 DB 001 RES C', ':001 DB * DEL C::#chan'], $this->written);
    }
}
