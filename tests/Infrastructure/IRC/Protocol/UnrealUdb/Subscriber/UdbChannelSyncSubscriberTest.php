<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber;

use App\Application\ChanServ\Event\ChannelMlockUpdatedEvent;
use App\Application\ChanServ\Event\ChannelTopiclockUpdatedEvent;
use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\UdbRecordWriterInterface;
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
use App\Domain\ChanServ\ValueObject\ChannelStatus;
use App\Domain\IRC\Event\ChannelTopicChangedEvent;
use App\Domain\IRC\Network\Channel as IrcChannel;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber\UdbChannelSyncSubscriber;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbRecordExporter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(UdbChannelSyncSubscriber::class)]
final class UdbChannelSyncSubscriberTest extends TestCase
{
    private function createSubscriber(
        ?RegisteredChannelRepositoryInterface $channelRepo = null,
        ?RegisteredNickRepositoryInterface $nickRepo = null,
        ?ChannelAccessRepositoryInterface $accessRepo = null,
        ?ChannelLookupPort $lookup = null,
        ?UdbRecordWriterInterface $writer = null,
    ): UdbChannelSyncSubscriber {
        $defaultWriter = $this->createStub(UdbRecordWriterInterface::class);
        $defaultWriter->method('insert')->willReturn(true);
        $defaultWriter->method('delete')->willReturn(true);
        $writer ??= $defaultWriter;

        return new UdbChannelSyncSubscriber(
            $writer,
            $channelRepo ?? $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createExporter($channelRepo, $nickRepo, $accessRepo, $lookup),
        );
    }

    private function createExporter(
        ?RegisteredChannelRepositoryInterface $channelRepo = null,
        ?RegisteredNickRepositoryInterface $nickRepo = null,
        ?ChannelAccessRepositoryInterface $accessRepo = null,
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
            $nickRepo ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            $channelRepo ?? $this->createStub(RegisteredChannelRepositoryInterface::class),
            $accessRepo ?? $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(GlineRepositoryInterface::class),
            $lookup ?? $this->createStub(ChannelLookupPort::class),
            $provider,
        );
    }

    private function createChannel(
        string $name = '#chan',
        int $founderNickId = 7,
        ?string $topic = null,
        bool $mlockActive = false,
        bool $topicLock = false,
        ChannelStatus $status = ChannelStatus::Active,
    ): RegisteredChannel {
        $channel = RegisteredChannel::register($name, $founderNickId, 'desc');
        $reflection = new ReflectionClass(RegisteredChannel::class);
        $reflection->getProperty('id')->setValue($channel, 1);
        if (null !== $topic) {
            $channel->updateTopic($topic);
        }
        if ($mlockActive) {
            $channel->configureMlock(true, '+nt');
        }
        if ($topicLock) {
            $channel->configureTopicLock(true);
        }
        $reflection->getProperty('status')->setValue($channel, $status);

        return $channel;
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
        $founder = RegisteredNick::createPending('founder', 'argon2id:$h', 'f@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $founder->activate();
        new ReflectionClass(RegisteredNick::class)->getProperty('id')->setValue($founder, 7);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturnCallback(static fn (int $id): ?RegisteredNick => 7 === $id ? $founder : null);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($this->createChannel('#chan', topic: 'Welcome'));

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
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);

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

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);
        $channelRepo->method('listAll')->willReturn([]);

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
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->never())->method('insert')->willReturn(true);

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelFounderChanged(new ChannelFounderChangedEvent(1, '#chan', 7, 9, 'oper', null, '', ''));
    }

    #[Test]
    public function optionsRecordIsDeletedWhenNoFlagsRemain(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(
            $this->createChannel('#chan', status: ChannelStatus::Suspended),
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
        $founder = RegisteredNick::createPending('newfounder', 'argon2id:$h', 'f@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $founder->activate();
        new ReflectionClass(RegisteredNick::class)->getProperty('id')->setValue($founder, 9);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($this->createChannel('#chan', founderNickId: 9));

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturnCallback(static fn (int $id): ?RegisteredNick => 9 === $id ? $founder : null);

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
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($this->createChannel('#chan', mlockActive: true, topicLock: true, status: ChannelStatus::Suspended));

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
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($this->createChannel('#chan'));

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
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($this->createChannel('#chan'));

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('C', '#chan::options', '*8');

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelMlockUpdated(new ChannelMlockUpdatedEvent('#chan'));
    }

    #[Test]
    public function onChannelTopiclockUpdatedRefreshesOptions(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($this->createChannel('#chan', topicLock: true));

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('C', '#chan::options', '*12');

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelTopiclockUpdated(new ChannelTopiclockUpdatedEvent('#chan'));
    }

    #[Test]
    public function optionsRefreshIsSkippedForUnknownChannels(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn(null);

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->never())->method('insert')->willReturn(true);

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelMlockUpdated(new ChannelMlockUpdatedEvent('#chan'));
    }

    #[Test]
    public function onChannelMlockUpdatedUpdatesModesRecord(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channel = $this->createChannel('#chan');
        $channel->configureMlock(true, '+ntkl', ['k' => 'key', 'l' => '10']);
        $channelRepo->method('findByChannelName')->willReturn($channel);

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
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($this->createChannel('#chan'));

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('delete')->willReturn(true)->with('C', '#chan::modes');

        $sub = $this->createSubscriber(channelRepo: $channelRepo, writer: $writer);
        $sub->onChannelMlockUpdated(new ChannelMlockUpdatedEvent('#chan'));
    }

    #[Test]
    public function onChannelTopicChangedInsertsAndDeletesTopic(): void
    {
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($this->createChannel('#chan'));

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
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($this->createChannel('#bad', status: ChannelStatus::Forbidden));

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
