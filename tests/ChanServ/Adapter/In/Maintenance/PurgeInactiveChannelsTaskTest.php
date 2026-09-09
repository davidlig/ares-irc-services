<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Maintenance;

use App\ChanServ\Adapter\In\Maintenance\PurgeInactiveChannelsTask;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanDropService;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(PurgeInactiveChannelsTask::class)]
final class PurgeInactiveChannelsTaskTest extends TestCase
{
    #[Test]
    public function getNameReturnsTaskName(): void
    {
        $task = new PurgeInactiveChannelsTask(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanDropService::class),
            $this->createStub(LoggerInterface::class),
            3600,
            90,
        );

        self::assertSame('chanserv.purge_inactive_channels', $task->getName());
    }

    #[Test]
    public function getIntervalSecondsReturnsInjectedValue(): void
    {
        $task = new PurgeInactiveChannelsTask(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanDropService::class),
            $this->createStub(LoggerInterface::class),
            7200,
            60,
        );

        self::assertSame(7200, $task->getIntervalSeconds());
    }

    #[Test]
    public function getOrderReturns300(): void
    {
        $task = new PurgeInactiveChannelsTask(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanDropService::class),
            $this->createStub(LoggerInterface::class),
            3600,
            90,
        );

        self::assertSame(300, $task->getOrder());
    }

    #[Test]
    public function runDoesNothingWhenInactivityExpiryDaysIsZero(): void
    {
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::never())->method('findRegisteredInactiveSince');
        $channelRepo->expects(self::never())->method('delete');
        $dropService = $this->createMock(ChanDropService::class);
        $dropService->expects(self::never())->method('hardDropChannel');

        $task = new PurgeInactiveChannelsTask(
            $channelRepo,
            $dropService,
            $this->createStub(LoggerInterface::class),
            3600,
            0,
        );
        $task->run();
    }

    #[Test]
    public function runHardDropsAndLogsEachInactiveChannel(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $lastUsed = new DateTimeImmutable('2024-01-01 12:00:00');
        $channel->method('getLastUsedAt')->willReturn($lastUsed);
        $channel->method('getCreatedAt')->willReturn(new DateTimeImmutable('2023-01-01'));

        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())
            ->method('findRegisteredInactiveSince')
            ->with(self::callback(static function (DateTimeImmutable $t): bool {
                $expected = new DateTimeImmutable()->modify('-90 days');

                return $t->format('Y-m-d') === $expected->format('Y-m-d');
            }))
            ->willReturn([$channel]);
        $channelRepo->expects(self::never())->method('delete');

        $dropService = $this->createMock(ChanDropService::class);
        $dropService->expects(self::once())->method('hardDropChannel')->with(
            $channel,
            self::isInstanceOf(DateTimeImmutable::class),
            'inactivity',
        );

        $logMessages = [];
        $logger = $this->createStub(LoggerInterface::class);
        $logger->method('info')->willReturnCallback(static function (string $msg) use (&$logMessages): void {
            $logMessages[] = $msg;
        });

        $task = new PurgeInactiveChannelsTask(
            $channelRepo,
            $dropService,
            $logger,
            3600,
            90,
        );
        $task->run();

        self::assertCount(1, $logMessages);
        self::assertStringContainsString('deleted channel #test', $logMessages[0]);
        self::assertStringContainsString('inactivity', $logMessages[0]);
    }
}
