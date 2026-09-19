<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\ChanServ\Application\Port\In\ChannelProjection;
use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\ChanServ\Application\PublishedEvent\ChannelDropEvent;
use App\ChanServ\Application\PublishedEvent\ChannelForbiddenEvent;
use App\ChanServ\Application\PublishedEvent\ChannelFounderChangedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelMlockUpdatedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelPendingDeletionEvent;
use App\ChanServ\Application\PublishedEvent\ChannelRegisteredEvent;
use App\ChanServ\Application\PublishedEvent\ChannelRestoredEvent;
use App\ChanServ\Application\PublishedEvent\ChannelSuspendedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelTopiclockUpdatedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelUnforbiddenEvent;
use App\ChanServ\Application\PublishedEvent\ChannelUnsuspendedEvent;
use App\Irc\Adapter\Event\NetworkSyncCompleteEvent;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbChannelSuspendReasonResolver;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbChannelSyncSubscriber;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordExporter;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordWriterInterface;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
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
use Symfony\Contracts\Translation\TranslatorInterface;

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

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('pending deletion reason');

        return new UdbRecordExporter(
            $nickRepo ?? $this->createStub(NickProjectionQuery::class),
            $channelRepo ?? $this->createStub(ChannelProjectionQuery::class),
            $this->createStub(OperatorNetworkProjectionQuery::class),
            $this->createStub(GlineProjectionQuery::class),
            $lookup ?? $this->createStub(ChannelLookupPort::class),
            $provider,
            new UdbChannelSuspendReasonResolver($translator, 'ChanServ', 'en'),
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
        ?string $suspensionReason = null,
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
            suspensionReason: $suspensionReason,
        );
    }

    #[Test]
    public function getSubscribedEventsExportsOnlyDomainEvents(): void
    {
        $events = UdbChannelSyncSubscriber::getSubscribedEvents();

        self::assertSame([
            ChannelRegisteredEvent::class => 'onChannelRegistered',
            ChannelDropEvent::class => 'onChannelDrop',
            ChannelPendingDeletionEvent::class => 'onChannelPendingDeletion',
            ChannelRestoredEvent::class => 'onChannelRestored',
            ChannelFounderChangedEvent::class => 'onChannelFounderChanged',
            ChannelForbiddenEvent::class => 'onChannelForbidden',
            ChannelUnforbiddenEvent::class => 'onChannelUnforbidden',
            ChannelSuspendedEvent::class => 'onChannelSuspended',
            ChannelUnsuspendedEvent::class => 'onChannelUnsuspended',
            ChannelMlockUpdatedEvent::class => 'onChannelMlockUpdated',
            ChannelTopiclockUpdatedEvent::class => 'onChannelTopiclockUpdated',
            ChannelTopicChangedEvent::class => 'onChannelTopicChanged',
            NetworkSyncCompleteEvent::class => ['onNetworkSyncComplete', -5],
        ], $events);
    }

    #[Test]
    public function onChannelRegisteredExportsFounderTopicModesAndOptions(): void
    {
        $founder = new NickProjection(7, 'founder', null, null, false, null, false);
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
        $sub->onChannelRegistered(new ChannelRegisteredEvent(1, '#chan', '#chan', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));

        self::assertSame([
            '#chan::founder' => 'founder',
            '#chan::topic' => 'Welcome',
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
        $sub->onChannelRegistered(new ChannelRegisteredEvent(1, '#chan', '#chan', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
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
        $sub->onChannelRegistered(new ChannelRegisteredEvent(1, '#chan', '#chan', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $sub->onChannelFounderChanged(new ChannelFounderChangedEvent(1, '#chan', 7, 9, 'oper', null, '', '', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
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
        $sub->onChannelFounderChanged(new ChannelFounderChangedEvent(1, '#chan', 7, 9, 'oper', null, '', '', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
    }

    #[Test]
    public function optionsRecordIsDeletedWhenNoFlagsRemain(): void
    {
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn(
            $this->createChannel('#chan', suspended: true),
        );

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('C', '#chan::suspend', 'reason');
        $writer->expects($this->once())->method('delete')->willReturn(true)->with('C', '#chan::options');

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelSuspended(new ChannelSuspendedEvent(1, '#chan', '#chan', 'reason', null, null, 'oper', null, '', '', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
    }

    #[Test]
    public function onChannelDropDeletesTheWholeProfile(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('delete')->willReturn(true)->with('C', '#chan');

        $sub = $this->createSubscriber(writer: $writer);
        $sub->onChannelDrop(new ChannelDropEvent(1, '#chan', '#chan', 'manual', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
    }

    #[Test]
    public function onChannelFounderChangedWritesFounderFromTheUpdatedEntity(): void
    {
        $founder = new NickProjection(9, 'newfounder', null, null, false, null, false);
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn($this->createChannel('#chan', founderNickId: 9));

        $nickRepo = $this->createStub(NickProjectionQuery::class);
        $nickRepo->method('findById')->willReturnCallback(static fn (int $id): ?NickProjection => 9 === $id ? $founder : null);

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('C', '#chan::founder', 'newfounder');

        $sub = $this->createSubscriber(channelRepo: $channelRepo, nickRepo: $nickRepo, writer: $writer);
        $sub->onChannelFounderChanged(new ChannelFounderChangedEvent(1, '#chan', 7, 9, 'oper', null, '', '', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
    }

    #[Test]
    public function onChannelForbiddenAndUnforbiddenManageForbidRecord(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('C', '#chan::forbid', 'spam');
        $writer->expects($this->once())->method('delete')->willReturn(true)->with('C', '#chan::forbid');

        $sub = $this->createSubscriber(writer: $writer);
        $sub->onChannelForbidden(new ChannelForbiddenEvent(1, '#chan', '#chan', 'spam', 'oper', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $sub->onChannelUnforbidden(new ChannelUnforbiddenEvent('#chan', '#chan', 'oper', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
    }

    #[Test]
    public function onChannelSuspendedManagesSuspendedRecordAndOptions(): void
    {
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn($this->createChannel('#chan', mlockActive: true, topicLock: true, suspended: true));

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->exactly(2))->method('insert')->willReturnCallback(static function (string $block, string $path, string $value): bool {
            if ('C::#chan::suspend' === 'C::' . $path) {
                self::assertSame('reason', $value);
            } else {
                self::assertSame('*6', $value);
                self::assertSame('#chan::options', $path);
            }

            return true;
        });

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelSuspended(new ChannelSuspendedEvent(1, '#chan', '#chan', 'reason', null, null, 'oper', null, '', '', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
    }

    #[Test]
    public function onChannelSuspendedFallsBackToNeutralMarkerWithoutReason(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('C', '#chan::suspend', '1');

        $sub = $this->createSubscriber(writer: $writer);
        $sub->onChannelSuspended(new ChannelSuspendedEvent(1, '#chan', '#chan', '', null, null, 'oper', null, '', '', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
    }

    #[Test]
    public function onChannelPendingDeletionInsertsTheTranslatedSuspendReason(): void
    {
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn($this->createChannel('#pending', pendingDeletion: true));

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('C', '#pending::suspend', 'pending deletion reason');

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelPendingDeletion(new ChannelPendingDeletionEvent(1, '#pending', '#pending', 'oper', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
    }

    #[Test]
    public function onChannelPendingDeletionIsSkippedForUnknownChannels(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->never())->method('insert');

        $sub = $this->createSubscriber(writer: $writer);
        $sub->onChannelPendingDeletion(new ChannelPendingDeletionEvent(1, '#missing', '#missing', null, new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
    }

    #[Test]
    public function onChannelRestoredDeletesTheSuspendRecord(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('delete')->willReturn(true)->with('C', '#pending::suspend');

        $sub = $this->createSubscriber(writer: $writer);
        $sub->onChannelRestored(new ChannelRestoredEvent(1, '#pending', '#pending', 'oper', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));
    }

    #[Test]
    public function onChannelUnsuspendedRemovesOptionsWhenNoLocksRemain(): void
    {
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn($this->createChannel('#chan'));

        $deletes = [];
        $writer = $this->createStub(UdbRecordWriterInterface::class);
        $writer->method('delete')->willReturnCallback(static function (string $block, string $path) use (&$deletes): bool {
            $deletes[] = [$block, $path];

            return true;
        });

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelUnsuspended(new ChannelUnsuspendedEvent(1, '#chan', '#chan', 'oper', null, '', '', new DateTimeImmutable('2026-01-01T00:00:00+00:00')));

        self::assertSame([['C', '#chan::suspend'], ['C', '#chan::options']], $deletes);
    }

    #[Test]
    public function onChannelMlockUpdatedRemovesOptionsWhenNoLocksRemain(): void
    {
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn($this->createChannel('#chan'));

        $deletes = [];
        $writer = $this->createStub(UdbRecordWriterInterface::class);
        $writer->method('delete')->willReturnCallback(static function (string $block, string $path) use (&$deletes): bool {
            $deletes[] = [$block, $path];

            return true;
        });

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelMlockUpdated(new ChannelMlockUpdatedEvent('#chan'));

        self::assertSame([['C', '#chan::options'], ['C', '#chan::modes']], $deletes);
    }

    #[Test]
    public function onChannelTopiclockUpdatedRefreshesOptions(): void
    {
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn($this->createChannel('#chan', topicLock: true));

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('C', '#chan::options', '*4');

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

        self::assertSame(['#chan::options' => '*2', '#chan::modes' => '+ntkl key 10'], $inserts);
    }

    #[Test]
    public function onChannelMlockUpdatedDeletesModesWhenInactive(): void
    {
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('findByName')->willReturn($this->createChannel('#chan'));

        $deletes = [];
        $writer = $this->createStub(UdbRecordWriterInterface::class);
        $writer->method('delete')->willReturnCallback(static function (string $block, string $path) use (&$deletes): bool {
            $deletes[] = [$block, $path];

            return true;
        });

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelMlockUpdated(new ChannelMlockUpdatedEvent('#chan'));

        self::assertSame([['C', '#chan::options'], ['C', '#chan::modes']], $deletes);
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

    #[Test]
    public function networkSyncReconcilesEveryChannelOptionsAndSuspendRecordsWithoutPersistentBit(): void
    {
        $channelRepo = $this->createStub(ChannelProjectionQuery::class);
        $channelRepo->method('all')->willReturn([
            $this->createChannel('#plain'),
            $this->createChannel('#mlock', mlockActive: true),
            $this->createChannel('#topiclock', topicLock: true),
            $this->createChannel('#both', mlockActive: true, topicLock: true),
            $this->createChannel('#pending', pendingDeletion: true),
        ]);

        $inserts = [];
        $deletes = [];
        $writer = $this->createStub(UdbRecordWriterInterface::class);
        $writer->method('insert')->willReturnCallback(static function (string $block, string $path, string $value) use (&$inserts): bool {
            $inserts[] = [$block, $path, $value];

            return true;
        });
        $writer->method('delete')->willReturnCallback(static function (string $block, string $path) use (&$deletes): bool {
            $deletes[] = [$block, $path];

            return true;
        });

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onNetworkSyncComplete(new NetworkSyncCompleteEvent($this->createStub(ConnectionInterface::class), '001'));

        self::assertSame([
            ['C', '#plain::options'],
            ['C', '#plain::suspend'],
            ['C', '#mlock::suspend'],
            ['C', '#topiclock::suspend'],
            ['C', '#both::suspend'],
            ['C', '#pending::options'],
        ], $deletes);
        self::assertSame([
            ['C', '#mlock::options', '*2'],
            ['C', '#topiclock::options', '*4'],
            ['C', '#both::options', '*6'],
            ['C', '#pending::suspend', 'pending deletion reason'],
        ], $inserts);
    }

    private function createIrcChannel(string $name): IrcChannel
    {
        return new IrcChannel(new ChannelName($name), '', new DateTimeImmutable('@12345'));
    }
}
