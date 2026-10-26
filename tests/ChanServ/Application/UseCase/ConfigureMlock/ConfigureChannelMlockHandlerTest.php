<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\ConfigureMlock;

use App\ChanServ\Adapter\Out\Persistence\DoctrineChannelMlockStorage;
use App\ChanServ\Application\Port\Out\ChannelHistoryRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelMlockUpdatedEvent;
use App\ChanServ\Application\Service\ChannelHistoryService;
use App\ChanServ\Application\UseCase\ConfigureMlock\ConfigureChannelMlock;
use App\ChanServ\Application\UseCase\ConfigureMlock\ConfigureChannelMlockHandler;
use App\ChanServ\Application\UseCase\ConfigureMlock\ConfigureChannelMlockResult;
use App\ChanServ\Domain\Entity\ChannelHistory;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\ChannelModeLock;
use App\ChanServ\Domain\ValueObject\ChannelSetting;
use App\ChanServ\Domain\ValueObject\ModeName;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass(ConfigureChannelMlock::class)]
#[CoversClass(ConfigureChannelMlockHandler::class)]
#[CoversClass(ConfigureChannelMlockResult::class)]
#[CoversClass(DoctrineChannelMlockStorage::class)]
final class ConfigureChannelMlockHandlerTest extends TestCase
{
    #[Test]
    public function itPersistsTheSemanticSnapshotAndPublishesItsChange(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Test');
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 1);
        $channels = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channels->expects(self::once())->method('save')->with($channel);
        $events = $this->createMock(ChanServEventPublisher::class);
        $events->expects(self::once())->method('publish')->with(self::callback(
            static fn (object $event): bool => $event instanceof ChannelMlockUpdatedEvent && '#test' === $event->channelName,
        ));
        $lock = ChannelModeLock::active([
            new ChannelSetting(new ModeName('n')),
            new ChannelSetting(new ModeName('k'), 'secret'),
        ]);
        $saved = null;
        $historyRepository = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $historyRepository->expects(self::once())->method('save')->willReturnCallback(static function (ChannelHistory $entry) use (&$saved): void { $saved = $entry; });

        $result = new ConfigureChannelMlockHandler($channels, new DoctrineChannelMlockStorage(), $events, new ChannelHistoryService($historyRepository))->handle(new ConfigureChannelMlock($channel, $lock, 'Founder', new DateTimeImmutable()));

        self::assertSame($lock, $result->modeLock);
        self::assertSame('+nk', $channel->getMlock());
        self::assertTrue($channel->isMlockActive());
        self::assertSame('secret', $channel->getMlockParam('k'));
        self::assertNotNull($saved);
        self::assertSame('SET_MLOCK_ON', $saved->getAction());
        self::assertSame('Founder', $saved->getPerformedBy());
        self::assertNull($saved->getPerformedByNickId());
        self::assertSame([], $saved->getExtraData());
    }

    #[Test]
    public function itKeepsInactiveAndActiveEmptyLocksDistinct(): void
    {
        $channel = RegisteredChannel::register(new DateTimeImmutable(), '#test', 1, 'Test');
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 1);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $events = $this->createStub(ChanServEventPublisher::class);
        $handler = new ConfigureChannelMlockHandler($channels, new DoctrineChannelMlockStorage(), $events, $this->historyService());

        $activeResult = $handler->handle(new ConfigureChannelMlock($channel, ChannelModeLock::active(), 'Founder', new DateTimeImmutable()));
        self::assertTrue($activeResult->modeLock->active);
        self::assertSame('', $channel->getMlock());
        self::assertTrue($channel->isMlockActive());

        $inactiveResult = $handler->handle(new ConfigureChannelMlock($channel, ChannelModeLock::inactive(), 'Founder', new DateTimeImmutable()));
        self::assertFalse($inactiveResult->modeLock->active);
        self::assertSame('', $channel->getMlock());
        self::assertFalse($channel->isMlockActive());
    }

    private function historyService(): ChannelHistoryService
    {
        return new ChannelHistoryService($this->createStub(ChannelHistoryRepositoryInterface::class));
    }
}
