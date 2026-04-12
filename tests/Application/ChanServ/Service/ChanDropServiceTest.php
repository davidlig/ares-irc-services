<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Service;

use App\Application\ChanServ\Service\ChanDropService;
use App\Application\Port\ServiceDebugNotifierInterface;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Event\ChannelDropEvent;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[CoversClass(ChanDropService::class)]
final class ChanDropServiceTest extends TestCase
{
    #[Test]
    public function dropChannelDispatchesEventAndDeletesChannel(): void
    {
        $channel = $this->createChannelWithId('#test', 42);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('delete')->with($channel);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->with(self::callback(static fn (ChannelDropEvent $event): bool => 42 === $event->channelId
                && '#test' === $event->channelName
                && 'manual' === $event->reason));

        $debug = $this->createMock(ServiceDebugNotifierInterface::class);
        $debug->expects(self::once())->method('log')->with(
            'OperUser',
            'DROP',
            '#test',
            null,
            null,
            'manual',
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $service = new ChanDropService(
            $channelRepository,
            $eventDispatcher,
            $debug,
            $logger,
        );

        $service->dropChannel($channel, 'manual', 'OperUser');
    }

    #[Test]
    public function dropChannelWithInactivityReasonLogsToDebugWithAsteriskOperator(): void
    {
        $channel = $this->createChannelWithId('#inactive', 100);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('delete');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->with(self::callback(static fn (ChannelDropEvent $event): bool => 'inactivity' === $event->reason));

        $debug = $this->createMock(ServiceDebugNotifierInterface::class);
        $debug->expects(self::once())->method('log')->with(
            '*',
            'DROP',
            '#inactive',
            null,
            null,
            'inactivity',
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $service = new ChanDropService(
            $channelRepository,
            $eventDispatcher,
            $debug,
            $logger,
        );

        $service->dropChannel($channel, 'inactivity', null);
    }

    #[Test]
    public function dropChannelWithManualReasonAndNullOperatorLogsToDebugWithAsteriskOperator(): void
    {
        $channel = $this->createChannelWithId('#testchan', 200);

        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->expects(self::once())->method('delete');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $debug = $this->createMock(ServiceDebugNotifierInterface::class);
        $debug->expects(self::once())->method('log')->with(
            '*',
            'DROP',
            '#testchan',
            null,
            null,
            'manual',
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $service = new ChanDropService(
            $channelRepository,
            $eventDispatcher,
            $debug,
            $logger,
        );

        $service->dropChannel($channel, 'manual', null);
    }

    private function createChannelWithId(string $name, int $id): RegisteredChannel
    {
        $channel = RegisteredChannel::register($name, 1, 'Test description');

        $ref = new ReflectionProperty(RegisteredChannel::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($channel, $id);

        return $channel;
    }
}
