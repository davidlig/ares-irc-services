<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelModeSupportInterface;
use App\ChanServ\Application\Port\In\ChannelProjection;
use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\ChanServ\Application\PublishedEvent\ChannelAccessChangedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelDropEvent;
use App\ChanServ\Application\PublishedEvent\ChannelForbiddenEvent;
use App\ChanServ\Application\PublishedEvent\ChannelFounderChangedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelMlockUpdatedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelRegisteredEvent;
use App\ChanServ\Application\PublishedEvent\ChannelSuspendedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelTopiclockUpdatedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelUnforbiddenEvent;
use App\ChanServ\Application\PublishedEvent\ChannelUnsuspendedEvent;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbChannelSyncSubscriber;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordExporter;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordWriterInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Domain\Event\ChannelTopicChangedEvent;
use App\Irc\Domain\Network\Channel as IrcChannel;
use App\Irc\Domain\ValueObject\ChannelName;
use App\NickServ\Application\Port\In\NickProjection;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use App\OperServ\Application\Port\In\GlineProjectionQuery;
use App\OperServ\Application\Port\In\OperatorNetworkProjectionQuery;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbChannelSyncSubscriber::class)]
final class UdbChannelSyncSubscriberTest extends TestCase
{
    private function createSubscriber(
        ?ChannelProjectionQuery $channelRepo = null,
        ?NickProjectionQuery $nickRepo = null,
        ?ChannelLookupPort $lookup = null,
        ?UdbRecordWriterInterface $writer = null,
    ): UdbChannelSyncSubscriber {
        $defaultWriter = $this->createStub(UdbRecordWriterInterface::class);
        $defaultWriter->method('insert')->willReturn(true);
        $defaultWriter->method('delete')->willReturn(true);
        $writer ??= $defaultWriter;

        return new UdbChannelSyncSubscriber(
            $writer,
            $channelRepo ?? $this->createStub(ChannelProjectionQuery::class),
            $this->createExporter($channelRepo, $nickRepo, $lookup),
        );
    }

    private function createExporter(
        ?ChannelProjectionQuery $channelRepo = null,
        ?NickProjectionQuery $nickRepo = null,
        ?ChannelLookupPort $lookup = null,
    ): UdbRecordExporter {
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);
        $support->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $support->method('getChannelRegisteredModeLetter')->willReturn('r');
        $support->method('getPermanentChannelModeLetter')->willReturn('P');
        $support->method('getChannelSettingModesWithParamOnSet')->willReturn(['l', 'k', 'j', 'f', 'L']);

        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $provider->method('getSupport')->willReturn($support);

        return new UdbRecordExporter(
            $nickRepo ?? $this->createStub(NickProjectionQuery::class),
            $channelRepo ?? $this->createStub(ChannelProjectionQuery::class),
            $this->createStub(OperatorNetworkProjectionQuery::class),
            $this->createStub(GlineProjectionQuery::class),
            $lookup ?? $this->createStub(ChannelLookupPort::class),
            $provider,
        );
    }

    /** @param array<string, string> $mlockParams */
    private function createChannel(
        string $name = '#chan',
        int $founderNickId = 7,
        ?string $topic = null,
        bool $mlockActive = false,
        bool $topicLock = false,
        bool $forbidden = false,
        bool $suspended = false,
        bool $pendingDeletion = false,
        string $mlock = '+nt',
        array $mlockParams = [],
    ): ChannelProjection {
        return new ChannelProjection(
            id: 1,
            name: $name,
            founderNickId: $founderNickId,
            topic: $topic,
            mlockActive: $mlockActive,
            mlock: $mlock,
            mlockParams: $mlockParams,
            topicLock: $topicLock,
            forbidden: $forbidden,
            forbiddenReason: $forbidden ? 'forbidden' : null,
            suspended: $suspended,
            pendingDeletion: $pendingDeletion,
            access: [],
        );
    }

    #[Test]
    public function getSubscribedEventsExportsOnlyDomainEvents(): void
    {
        $events = UdbChannelSyncSubscriber::getSubscribedEvents();

        self::assertSame([
            ChannelRegisteredEvent::class => 'onChannelRegistered',
            ChannelDropEvent::class => 'onChannelDrop',
            ChannelFounderChangedEvent::class => 'onChannelFounderChanged',
            ChannelForbiddenEvent::class => 'onChannelForbidden',
            ChannelUnforbiddenEvent::class => 'onChannelUnforbidden',
            ChannelSuspendedEvent::class => 'onChannelSuspended',
            ChannelUnsuspendedEvent::class => 'onChannelUnsuspended',
            ChannelAccessChangedEvent::class => 'onChannelAccessChanged',
            ChannelMlockUpdatedEvent::class => 'onChannelMlockUpdated',
            ChannelTopiclockUpdatedEvent::class => 'onChannelTopiclockUpdated',
            ChannelTopicChangedEvent::class => 'onChannelTopicChanged',
        ], $events);
    }

    #[Test]
    public function onChannelRegisteredExportsFounderTopicModesAndOptions(): void
    {
        $founder = new NickProjection(7, 'founder', null, null);
        $nickRepo = $this->createStub(NickProjectionQuery::class);
        $nickRepo->method('findById')->willReturnCallback(static fn (int $id): ?NickProjection => 7 === $id ? $founder : null);

        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn($this->createChannel('#chan', topic: 'Welcome'));

        $inserts = [];
        $writer = $this->createStub(UdbRecordWriterInterface::class);
        $writer->method('insert')->willReturnCallback(static function (string $block, string $path, string $value) use (&$inserts): bool {
            $inserts[$path] = $value;

            return true;
        });

        $sub = $this->createSubscriber(channelRepo: $channelRepo, nickRepo: $nickRepo, writer: $writer);
        $sub->onChannelRegistered(new ChannelRegisteredEvent(1, '#chan', '#chan'));

        self::assertSame([
            '#chan::founder' => 'founder',
            '#chan::topic' => 'Welcome',
            '#chan::options' => '*8',
        ], $inserts);
    }

    #[Test]
    public function onChannelRegisteredIsSkippedForUnknownChannels(): void
    {
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn(null);

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->never())->method('insert')->willReturn(true);

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelRegistered(new ChannelRegisteredEvent(1, '#chan', '#chan'));
    }

    #[Test]
    public function unknownChannelsAreSkippedWithoutWriterCalls(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->never())->method('insert')->willReturn(true);
        $writer->expects($this->never())->method('delete')->willReturn(true);

        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn(null);
        $channelRepo->method('all')->willReturn([]);

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelRegistered(new ChannelRegisteredEvent(1, '#chan', '#chan'));
        $sub->onChannelFounderChanged(new ChannelFounderChangedEvent(1, '#chan', 7, 9, 'oper', null, '', ''));
        $sub->onChannelMlockUpdated(new ChannelMlockUpdatedEvent('#chan'));
        $sub->onChannelTopiclockUpdated(new ChannelTopiclockUpdatedEvent('#chan'));
        $sub->onChannelTopicChanged(new ChannelTopicChangedEvent($this->createIrcChannel('#chan')));
    }

    #[Test]
    public function founderChangeIsSkippedForUnknownChannels(): void
    {
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn(null);

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->never())->method('insert')->willReturn(true);

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelFounderChanged(new ChannelFounderChangedEvent(1, '#chan', 7, 9, 'oper', null, '', ''));
    }

    #[Test]
    public function optionsRecordIsDeletedWhenNoFlagsRemain(): void
    {
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn(
            $this->createChannel('#chan', suspended: true),
        );

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('C', '#chan::suspended', '1');
        $writer->expects($this->once())->method('delete')->willReturn(true)->with('C', '#chan::options');

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelSuspended(new ChannelSuspendedEvent(1, '#chan', '#chan', 'reason', null, null, 'oper', null, '', ''));
    }

    #[Test]
    public function onChannelDropDeletesTheWholeProfile(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('delete')->willReturn(true)->with('C', '#chan');

        $sub = $this->createSubscriber(writer: $writer);
        $sub->onChannelDrop(new ChannelDropEvent(1, '#chan', '#chan', 'manual'));
    }

    #[Test]
    public function onChannelFounderChangedWritesFounderFromTheUpdatedEntity(): void
    {
        $founder = new NickProjection(9, 'newfounder', null, null);
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn($this->createChannel('#chan', founderNickId: 9));

        $nickRepo = $this->createStub(NickProjectionQuery::class);
        $nickRepo->method('findById')->willReturnCallback(static fn (int $id): ?NickProjection => 9 === $id ? $founder : null);

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('C', '#chan::founder', 'newfounder');

        $sub = $this->createSubscriber(channelRepo: $channelRepo, nickRepo: $nickRepo, writer: $writer);
        $sub->onChannelFounderChanged(new ChannelFounderChangedEvent(1, '#chan', 7, 9, 'oper', null, '', ''));
    }

    #[Test]
    public function onChannelForbiddenAndUnforbiddenManageForbidRecord(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('C', '#chan::forbid', 'spam');
        $writer->expects($this->once())->method('delete')->willReturn(true)->with('C', '#chan::forbid');

        $sub = $this->createSubscriber(writer: $writer);
        $sub->onChannelForbidden(new ChannelForbiddenEvent(1, '#chan', '#chan', 'spam', 'oper'));
        $sub->onChannelUnforbidden(new ChannelUnforbiddenEvent('#chan', '#chan', 'oper'));
    }

    #[Test]
    public function onChannelSuspendedManagesSuspendedRecordAndOptions(): void
    {
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn($this->createChannel('#chan', mlockActive: true, topicLock: true, suspended: true));

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->exactly(2))->method('insert')->willReturnCallback(static function (string $block, string $path, string $value): bool {
            if ('C::#chan::suspended' === 'C::' . $path) {
                self::assertSame('1', $value);
            } else {
                self::assertSame('*6', $value);
                self::assertSame('#chan::options', $path);
            }

            return true;
        });

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelSuspended(new ChannelSuspendedEvent(1, '#chan', '#chan', 'reason', null, null, 'oper', null, '', ''));
    }

    #[Test]
    public function onChannelUnsuspendedRestoresPersistentOptionBit(): void
    {
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn($this->createChannel('#chan'));

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('delete')->willReturn(true)->with('C', '#chan::suspended');
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('C', '#chan::options', '*8');

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelUnsuspended(new ChannelUnsuspendedEvent(1, '#chan', '#chan', 'oper', null, '', ''));
    }

    #[Test]
    public function onChannelAccessChangedInsertsAndDeletesEntries(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('C', '#chan::access::alice', '300');
        $writer->expects($this->once())->method('delete')->willReturn(true)->with('C', '#chan::access::bob');

        $sub = $this->createSubscriber(writer: $writer);
        $sub->onChannelAccessChanged(new ChannelAccessChangedEvent(1, '#chan', 'ADD', 9, 'alice', 300, 'oper', null, '', ''));
        $sub->onChannelAccessChanged(new ChannelAccessChangedEvent(1, '#chan', 'DEL', 8, 'bob', null, 'oper', null, '', ''));
    }

    #[Test]
    public function onChannelMlockUpdatedRefreshesOptions(): void
    {
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn($this->createChannel('#chan'));

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('C', '#chan::options', '*8');

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelMlockUpdated(new ChannelMlockUpdatedEvent('#chan'));
    }

    #[Test]
    public function onChannelTopiclockUpdatedRefreshesOptions(): void
    {
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn($this->createChannel('#chan', topicLock: true));

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('C', '#chan::options', '*12');

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelTopiclockUpdated(new ChannelTopiclockUpdatedEvent('#chan'));
    }

    #[Test]
    public function optionsRefreshIsSkippedForUnknownChannels(): void
    {
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn(null);

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->never())->method('insert')->willReturn(true);

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelMlockUpdated(new ChannelMlockUpdatedEvent('#chan'));
    }

    #[Test]
    public function onChannelMlockUpdatedUpdatesModesRecord(): void
    {
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channel = $this->createChannel('#chan', mlockActive: true, mlock: '+ntkl', mlockParams: ['k' => 'key', 'l' => '10']);
        $channelRepo->method('findByName')->willReturn($channel);

        $inserts = [];
        $writer = $this->createStub(UdbRecordWriterInterface::class);
        $writer->method('insert')->willReturnCallback(static function (string $block, string $path, string $value) use (&$inserts): bool {
            $inserts[$path] = $value;

            return true;
        });

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelMlockUpdated(new ChannelMlockUpdatedEvent('#chan'));

        self::assertSame(['#chan::options' => '*10', '#chan::modes' => '+ntkl key 10'], $inserts);
    }

    #[Test]
    public function onChannelMlockUpdatedDeletesModesWhenInactive(): void
    {
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn($this->createChannel('#chan'));

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('delete')->willReturn(true)->with('C', '#chan::modes');

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelMlockUpdated(new ChannelMlockUpdatedEvent('#chan'));
    }

    #[Test]
    public function onChannelTopicChangedInsertsAndDeletesTopic(): void
    {
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn($this->createChannel('#chan'));

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('C', '#chan::topic', 'New topic');
        $writer->expects($this->once())->method('delete')->willReturn(true)->with('C', '#chan::topic');

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $chanWithTopic = $this->createIrcChannel('#chan');
        $chanWithTopic->updateTopic('New topic');
        $sub->onChannelTopicChanged(new ChannelTopicChangedEvent($chanWithTopic));
        $sub->onChannelTopicChanged(new ChannelTopicChangedEvent($this->createIrcChannel('#chan')));
    }

    #[Test]
    public function wireEventsAreSkippedForForbiddenChannels(): void
    {
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn($this->createChannel('#bad', forbidden: true));

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->never())->method('insert')->willReturn(true);
        $writer->expects($this->never())->method('delete')->willReturn(true);

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelTopicChanged(new ChannelTopicChangedEvent($this->createIrcChannel('#bad')));
    }

    private function createIrcChannel(string $name): IrcChannel
    {
        return new IrcChannel(new ChannelName($name), '', new DateTimeImmutable('@12345'));
    }
}
