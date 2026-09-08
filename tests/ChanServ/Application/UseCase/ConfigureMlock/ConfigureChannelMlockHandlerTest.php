<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\ConfigureMlock;

use App\ChanServ\Adapter\Out\Persistence\LegacyChannelMlockStorage;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelMlockUpdatedEvent;
use App\ChanServ\Application\UseCase\ConfigureMlock\ConfigureChannelMlock;
use App\ChanServ\Application\UseCase\ConfigureMlock\ConfigureChannelMlockHandler;
use App\ChanServ\Application\UseCase\ConfigureMlock\ConfigureChannelMlockResult;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\ChannelModeLock;
use App\ChanServ\Domain\ValueObject\ChannelSetting;
use App\ChanServ\Domain\ValueObject\ModeName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigureChannelMlock::class)]
#[CoversClass(ConfigureChannelMlockHandler::class)]
#[CoversClass(ConfigureChannelMlockResult::class)]
#[CoversClass(LegacyChannelMlockStorage::class)]
final class ConfigureChannelMlockHandlerTest extends TestCase
{
    #[Test]
    public function itPersistsTheSemanticSnapshotAndPublishesItsChange(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
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

        $result = new ConfigureChannelMlockHandler($channels, new LegacyChannelMlockStorage(), $events)->handle(new ConfigureChannelMlock($channel, $lock));

        self::assertSame($lock, $result->modeLock);
        self::assertSame('+nk', $channel->getMlock());
        self::assertTrue($channel->isMlockActive());
        self::assertSame('secret', $channel->getMlockParam('k'));
    }

    #[Test]
    public function itKeepsInactiveAndActiveEmptyLocksDistinct(): void
    {
        $channel = RegisteredChannel::register('#test', 1, 'Test');
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $events = $this->createStub(ChanServEventPublisher::class);
        $handler = new ConfigureChannelMlockHandler($channels, new LegacyChannelMlockStorage(), $events);

        $activeResult = $handler->handle(new ConfigureChannelMlock($channel, ChannelModeLock::active()));
        self::assertTrue($activeResult->modeLock->active);
        self::assertSame('', $channel->getMlock());
        self::assertTrue($channel->isMlockActive());

        $inactiveResult = $handler->handle(new ConfigureChannelMlock($channel, ChannelModeLock::inactive()));
        self::assertFalse($inactiveResult->modeLock->active);
        self::assertSame('', $channel->getMlock());
        self::assertFalse($channel->isMlockActive());
    }
}
